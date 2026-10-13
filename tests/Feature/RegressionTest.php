<?php

use CaturSeptian\PennantAnalytics\Analytics\VariantResult;
use CaturSeptian\PennantAnalytics\Exceptions\InvalidTrackingName;
use CaturSeptian\PennantAnalytics\Facades\PennantAnalytics;
use CaturSeptian\PennantAnalytics\PennantAnalyticsEngine;
use CaturSeptian\PennantAnalytics\Tests\Fixtures\ClassBasedFlag;
use CaturSeptian\PennantAnalytics\Tests\Fixtures\FlagName;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * @return array<string, array{0: int, 1: int}>
 */
function counts(?string $from = null, ?string $to = null): array
{
    return collect(PennantAnalytics::report('cohort', 'buy', $from, $to)->variants)
        ->mapWithKeys(fn (VariantResult $v) => [$v->variant => [$v->impressions, $v->conversions]])
        ->all();
}

it('credits later conversions to the cohort that entered in the date range', function () {
    seedCohorts();

    expect(counts('2026-09-01', '2026-09-30'))->toBe(['false' => [10, 0], 'true' => [10, 6]]);
});

it('never reports more conversions than exposed subjects for a date range', function () {
    seedCohorts();

    expect(counts('2026-10-01'))->toBe(['true' => [1, 0]])
        ->and(counts(to: '2026-09-30'))->toBe(['false' => [10, 0], 'true' => [10, 6]])
        ->and(counts())->toBe(['false' => [10, 0], 'true' => [11, 6]]);
});

it('treats the date range as inclusive whole days', function () {
    seedCohorts();

    expect(counts('2026-09-15', '2026-09-15'))->toBe(['false' => [10, 0], 'true' => [10, 6]])
        ->and(counts('2026-09-16', '2026-10-04'))->toBe([]);
});

it('rejects names that do not fit the schema instead of silently dropping them', function (string $feature, string $action) {
    PennantAnalytics::track($feature, $action, 'someone');
})->with([
    'long action' => ['f', str_repeat('x', 101)],
    'empty action' => ['f', ''],
    'long feature' => [str_repeat('x', 151), 'buy'],
    'empty feature' => ['', 'buy'],
])->throws(InvalidTrackingName::class);

it('accepts names at the schema limits', function () {
    Feature::define(str_repeat('f', 150), fn () => true);

    PennantAnalytics::track(str_repeat('f', 150), str_repeat('a', 100), 'someone');

    expect(DB::table('pennant_analytics_metrics')->count())->toBe(2);
});

it('stores class based features under their Pennant name', function () {
    config(['pennant-analytics.auto_impressions' => ['enabled' => true, 'features' => []]]);
    app()->forgetInstance(PennantAnalyticsEngine::class);
    PennantAnalytics::clearResolvedInstances();

    Feature::for('someone')->value(ClassBasedFlag::class);
    PennantAnalytics::track(ClassBasedFlag::class, 'buy', 'someone');

    expect(DB::table('pennant_analytics_metrics')->distinct()->pluck('feature')->all())->toBe(['class-based-flag'])
        ->and(metric('class-based-flag', 'variant-b', 'impression')->total)->toEqual(1);
});

it('accepts enum feature names like Pennant does', function () {
    Feature::define(FlagName::NewCheckout, fn () => true);

    PennantAnalytics::track(FlagName::NewCheckout, 'buy', 'someone');

    expect(metric('new-checkout-ui', 'true', 'buy'))->not->toBeNull();
});
