<?php

use CaturSeptian\PennantAnalytics\Facades\PennantAnalytics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Pennant\Feature;

function seedExperiment(string $feature, array $variants, string $action = 'completed-purchase', string $date = '2026-10-01'): void
{
    foreach ($variants as $variant => [$impressions, $conversions]) {
        DB::table('pennant_analytics_metrics')->insert([
            ['feature' => $feature, 'variant' => $variant, 'action' => 'impression', 'date' => $date, 'total' => $impressions, 'uniques' => $impressions],
            ['feature' => $feature, 'variant' => $variant, 'action' => $action, 'date' => $date, 'total' => $conversions + 7, 'uniques' => $conversions],
        ]);
    }
}

it('computes conversion rates and declares a significant winner', function () {
    seedExperiment('new-checkout-ui', ['false' => [2000, 200], 'true' => [2000, 260]]);

    $report = PennantAnalytics::report('new-checkout-ui', 'completed-purchase');

    expect($report->control()->variant)->toBe('false')
        ->and($report->control()->conversionRate())->toBe(10.0)
        ->and($report->leader()->conversionRate())->toBe(13.0)
        ->and($report->leader()->totalConversions)->toBe(267)
        ->and($report->hasSignificantWinner())->toBeTrue()
        ->and($report->summary())->toBe('Variant TRUE is outperforming Variant FALSE by 30.0% (Statistically Significant)');
});

it('reports underperformers and non-significant results honestly', function () {
    seedExperiment('a', ['false' => [2000, 260], 'true' => [2000, 200]]);
    seedExperiment('b', ['false' => [1000, 100], 'true' => [1000, 104]]);
    seedExperiment('c', ['false' => [30, 3], 'true' => [30, 9]]);

    expect(PennantAnalytics::report('a', 'completed-purchase')->summary())
        ->toBe('Variant TRUE is underperforming Variant FALSE by 23.1% (Statistically Significant)')
        ->and(PennantAnalytics::report('b', 'completed-purchase')->summary())
        ->toBe('Variant TRUE is outperforming Variant FALSE by 4.0% (Not Statistically Significant)')
        ->and(PennantAnalytics::report('c', 'completed-purchase')->summary())
        ->toBe('Variant TRUE is outperforming Variant FALSE by 200.0% (Insufficient data)');
});

it('applies a Bonferroni correction for multivariate experiments', function () {
    // p is about 0.03: significant at 95% for one comparison, not for three.
    seedExperiment('two', ['control' => [3000, 300], 'red' => [3000, 350]]);
    seedExperiment('four', ['control' => [3000, 300], 'red' => [3000, 350], 'green' => [3000, 300], 'blue' => [3000, 300]]);

    expect(PennantAnalytics::report('two', 'completed-purchase')->hasSignificantWinner())->toBeTrue()
        ->and(PennantAnalytics::report('four', 'completed-purchase')->hasSignificantWinner())->toBeFalse()
        ->and(PennantAnalytics::report('four', 'completed-purchase')->control()->variant)->toBe('control');
});

it('falls back to the most exposed variant as control', function () {
    seedExperiment('colors', ['blue' => [500, 50], 'green' => [800, 80]]);

    expect(PennantAnalytics::report('colors', 'completed-purchase')->control()->variant)->toBe('green');
});

it('builds reports end-to-end from tracked hits', function () {
    Feature::define('flag', fn (string $s) => str_ends_with($s, 'a'));

    foreach (range(1, 10) as $i) {
        PennantAnalytics::impression('flag', "user-{$i}a");
        PennantAnalytics::impression('flag', "user-{$i}b");
    }
    foreach (range(1, 4) as $i) {
        PennantAnalytics::track('flag', 'signup', "user-{$i}a");
    }
    PennantAnalytics::track('flag', 'signup', 'user-1b');

    $report = PennantAnalytics::report('flag', 'signup');

    expect($report->leader()->conversionRate())->toBe(40.0)
        ->and($report->control()->conversionRate())->toBe(10.0);
});

it('renders the dashboard behind the gate', function () {
    seedExperiment('new-checkout-ui', ['false' => [2000, 200], 'true' => [2000, 260]]);

    $this->get('/pennant-analytics')->assertForbidden();

    Gate::define('viewPennantAnalytics', fn ($user = null) => true);

    $this->get('/pennant-analytics')
        ->assertOk()
        ->assertSee('new-checkout-ui');

    $this->get('/pennant-analytics/features/new-checkout-ui')
        ->assertOk()
        ->assertSee('Variant TRUE is outperforming Variant FALSE by 30.0% (Statistically Significant)')
        ->assertSee('13.00%')
        ->assertSee('significantly better')
        ->assertSee('Significant when p < 0.05 (95% confidence)');

    $this->getJson('/pennant-analytics/features/new-checkout-ui?action=completed-purchase')
        ->assertOk()
        ->assertJsonPath('significant', true)
        ->assertJsonPath('variants.1.conversion_rate', 13);
});

it('shows an empty state for features without conversions', function () {
    Gate::define('viewPennantAnalytics', fn ($user = null) => true);

    $this->get('/pennant-analytics')->assertOk()->assertSee('No data yet');
    $this->get('/pennant-analytics/features/unknown')->assertOk()->assertSee('No conversion actions recorded');
});

it('prints a report from the command line', function () {
    seedExperiment('new-checkout-ui', ['false' => [2000, 200], 'true' => [2000, 260]]);

    $this->artisan('pennant-analytics:report', ['feature' => 'new-checkout-ui'])
        ->expectsOutputToContain('Variant TRUE is outperforming Variant FALSE by 30.0% (Statistically Significant)')
        ->assertSuccessful();
});

it('forgets a feature', function () {
    seedExperiment('old', ['false' => [10, 1]]);

    $this->artisan('pennant-analytics:forget', ['feature' => 'old', '--force' => true])->assertSuccessful();

    expect(metrics())->toBeEmpty();
});

it('rejects malformed dates on the command line', function () {
    seedExperiment('new-checkout-ui', ['false' => [10, 1]]);

    $this->artisan('pennant-analytics:report', ['feature' => 'new-checkout-ui', '--from' => '13/10/2026'])
        ->expectsOutputToContain('--from must be a date in Y-m-d format.')
        ->assertFailed();
});

it('reports a date range as a cohort on the dashboard and command line', function () {
    Gate::define('viewPennantAnalytics', fn ($user = null) => true);
    seedCohorts();

    $this->getJson('/pennant-analytics/features/cohort?action=buy&from=2026-09-01&to=2026-09-30')
        ->assertOk()
        ->assertJsonPath('variants.0.variant', 'false')
        ->assertJsonPath('variants.1.conversion_rate', 60)
        ->assertJsonPath('variants.1.total_conversions', null);

    $this->artisan('pennant-analytics:report', ['feature' => 'cohort', 'action' => 'buy', '--from' => '2026-09-01'])
        ->expectsOutputToContain('Cohort of subjects first exposed in the date range')
        ->assertSuccessful();
});

it('marks the significance result per variant on the command line', function () {
    // lifetime: p = 0.031, above the Bonferroni threshold of 0.025 for two challengers.
    // monthly:  p = 0.0014, significantly worse than the control.
    seedExperiment('pricing', ['annual' => [2000, 234], 'lifetime' => [2000, 192], 'monthly' => [2000, 173]]);

    $this->artisan('pennant-analytics:report', ['feature' => 'pricing', 'action' => 'completed-purchase'])
        ->expectsTable(
            ['Variant', 'Impressions', 'Conversions', 'Rate', 'Lift', 'p-value', 'Result'],
            [
                ['annual (control)', '2,000', '234', '11.70%', '—', '—', 'baseline'],
                ['lifetime', '2,000', '192', '9.60%', '-17.9%', '0.0313', 'not significant'],
                ['monthly', '2,000', '173', '8.65%', '-26.1%', '0.0014', 'significantly worse'],
            ],
        )
        ->expectsOutputToContain('Significance threshold: p < 0.025 (95% confidence, Bonferroni-corrected for 2 comparisons)')
        ->assertSuccessful();
});
