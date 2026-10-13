<?php

use CaturSeptian\PennantAnalytics\Facades\PennantAnalytics;
use CaturSeptian\PennantAnalytics\Jobs\PersistHits;
use CaturSeptian\PennantAnalytics\PennantAnalyticsEngine;
use CaturSeptian\PennantAnalytics\Storage\MetricsRepository;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;

beforeEach(function () {
    // Even user ids see the new checkout, odd ids the old one.
    Feature::define('new-checkout-ui', fn ($user) => $user !== null && $user->id % 2 === 0);
    Feature::define('button-color', fn () => 'blue');
});

it('records an impression for the authenticated user and their variant', function () {
    $this->actingAs($this->user(2));

    PennantAnalytics::impression('new-checkout-ui');

    expect(metric('new-checkout-ui', 'true', 'impression'))
        ->total->toEqual(1)
        ->uniques->toEqual(1);
});

it('records a conversion with the exact documented syntax', function () {
    $this->actingAs($this->user(1));

    PennantAnalytics::impression('new-checkout-ui');
    PennantAnalytics::track('new-checkout-ui', 'completed-purchase');

    expect(metric('new-checkout-ui', 'false', 'completed-purchase'))
        ->total->toEqual(1)
        ->uniques->toEqual(1);
});

it('counts every hit in total but each subject once in uniques', function () {
    $this->actingAs($this->user(2));

    PennantAnalytics::track('new-checkout-ui', 'completed-purchase');
    PennantAnalytics::track('new-checkout-ui', 'completed-purchase');
    PennantAnalytics::track('new-checkout-ui', 'completed-purchase');

    expect(metric('new-checkout-ui', 'true', 'completed-purchase'))
        ->total->toEqual(3)
        ->uniques->toEqual(1);
});

it('de-duplicates impressions within one request and across requests', function () {
    config(['pennant-analytics.persistence' => 'deferred']);
    $this->actingAs($this->user(2));

    PennantAnalytics::impression('new-checkout-ui');
    PennantAnalytics::impression('new-checkout-ui');
    expect(PennantAnalytics::pending())->toHaveCount(1);
    PennantAnalytics::flush();

    PennantAnalytics::impression('new-checkout-ui');
    PennantAnalytics::flush();

    expect(metric('new-checkout-ui', 'true', 'impression'))
        ->total->toEqual(2)
        ->uniques->toEqual(1);
});

it('implicitly records the exposure when a conversion arrives first', function () {
    $this->actingAs($this->user(4));

    PennantAnalytics::track('new-checkout-ui', 'completed-purchase');

    expect(metric('new-checkout-ui', 'true', 'impression')->uniques)->toEqual(1)
        ->and(metric('new-checkout-ui', 'true', 'completed-purchase')->uniques)->toEqual(1);
});

it('attributes conversions to the first exposed variant even if the flag flips', function () {
    $user = $this->user(2);
    $this->actingAs($user);

    PennantAnalytics::impression('new-checkout-ui'); // exposed to "true"

    Feature::for($user)->deactivate('new-checkout-ui');
    PennantAnalytics::track('new-checkout-ui', 'completed-purchase');

    expect(metric('new-checkout-ui', 'true', 'completed-purchase')->uniques)->toEqual(1)
        ->and(metric('new-checkout-ui', 'false', 'completed-purchase'))->toBeNull();
});

it('tracks against an explicit scope', function () {
    PennantAnalytics::for($this->user(6))->track('new-checkout-ui', 'upgraded');
    PennantAnalytics::for($this->user(7))->impression('new-checkout-ui');

    expect(metric('new-checkout-ui', 'true', 'upgraded')->uniques)->toEqual(1)
        ->and(metric('new-checkout-ui', 'false', 'impression')->uniques)->toEqual(1);
});

it('honours Pennant custom scope resolvers', function () {
    Feature::resolveScopeUsing(fn () => 'team-42');
    Feature::define('team-flag', fn (string $team) => $team === 'team-42' ? 'gold' : 'silver');

    PennantAnalytics::impression('team-flag');

    expect(metric('team-flag', 'gold', 'impression')->uniques)->toEqual(1)
        ->and(DB::table('pennant_analytics_subjects')->value('subject'))->toBe(hash('xxh128', 'team-42'));
});

it('stores multivariate values as their label', function () {
    PennantAnalytics::impression('button-color', 'someone');

    expect(metric('button-color', 'blue', 'impression'))->not->toBeNull();
});

it('identifies guests through the session so they are counted separately', function () {
    Route::middleware('web')->get('/checkout', function () {
        PennantAnalytics::impression('button-color');

        return 'ok';
    });

    $this->get('/checkout')->assertOk();
    $this->flushSession();
    $this->get('/checkout')->assertOk();

    expect(metric('button-color', 'blue', 'impression')->uniques)->toEqual(2);
});

it('allows a custom guest resolver', function () {
    PennantAnalytics::resolveGuestUsing(fn () => 'device-abc');

    PennantAnalytics::impression('button-color');

    expect(DB::table('pennant_analytics_subjects')->value('subject'))->toBe(hash('xxh128', 'guest|device-abc'));
});

it('writes nothing until terminate in deferred mode', function () {
    config(['pennant-analytics.persistence' => 'deferred']);
    app()->forgetInstance(PennantAnalyticsEngine::class);
    PennantAnalytics::clearResolvedInstances();

    PennantAnalytics::impression('button-color', 'someone');
    expect(metrics())->toBeEmpty();

    app()->terminate();

    expect(metrics())->toHaveCount(1);
});

it('hands hits to a queued job in queue mode', function () {
    Bus::fake();
    config(['pennant-analytics.persistence' => 'queue']);
    app()->forgetInstance(PennantAnalyticsEngine::class);
    PennantAnalytics::clearResolvedInstances();

    PennantAnalytics::track('button-color', 'clicked', 'someone');
    PennantAnalytics::flush();

    Bus::assertDispatched(PersistHits::class, function (PersistHits $job) {
        $job->handle(app(MetricsRepository::class));

        return count($job->hits) === 1 && $job->hits[0]['variant'] === 'blue';
    });
    expect(metric('button-color', 'blue', 'clicked')->uniques)->toEqual(1);
});

it('records impressions automatically from Pennant checks when enabled', function () {
    config(['pennant-analytics.auto_impressions' => ['enabled' => true, 'features' => ['new-checkout-ui']]]);
    app()->forgetInstance(PennantAnalyticsEngine::class);
    PennantAnalytics::clearResolvedInstances();
    $this->actingAs($this->user(2));

    Feature::active('new-checkout-ui');
    Feature::active('new-checkout-ui');
    Feature::value('button-color'); // not in the allow-list

    expect(metric('new-checkout-ui', 'true', 'impression')->uniques)->toEqual(1)
        ->and(metric('button-color', 'blue', 'impression'))->toBeNull();
});

it('does not double count when the engine itself resolves a flag with auto impressions on', function () {
    config(['pennant-analytics.auto_impressions' => ['enabled' => true, 'features' => []]]);
    app()->forgetInstance(PennantAnalyticsEngine::class);
    PennantAnalytics::clearResolvedInstances();

    PennantAnalytics::track('button-color', 'clicked', 'someone');

    expect(metric('button-color', 'blue', 'impression')->total)->toEqual(1);
});

it('records impressions through the route middleware for successful responses only', function () {
    Route::get('/ok', fn () => 'ok')->middleware('pennant.impression:button-color');
    Route::get('/fail', fn () => abort(500))->middleware('pennant.impression:button-color');

    $this->get('/ok')->assertOk();
    $this->get('/fail')->assertStatus(500);

    expect(metric('button-color', 'blue', 'impression')->total)->toEqual(1);
});

it('provides blade directives', function () {
    $html = Blade::render("@trackImpression('button-color')@trackConversion('button-color', 'clicked')done");

    expect($html)->toBe('done')
        ->and(metric('button-color', 'blue', 'clicked')->uniques)->toEqual(1);
});

it('never breaks the request when storage fails', function () {
    config(['pennant-analytics.persistence' => 'sync']);
    Schema::drop('pennant_analytics_metrics');

    PennantAnalytics::impression('button-color', 'someone');
})->throwsNoExceptions();

it('is a no-op when disabled', function () {
    config(['pennant-analytics.enabled' => false]);
    app()->forgetInstance(PennantAnalyticsEngine::class);
    PennantAnalytics::clearResolvedInstances();

    PennantAnalytics::track('button-color', 'clicked', 'someone');

    expect(metrics())->toBeEmpty();
});
