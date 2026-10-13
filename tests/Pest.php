<?php

use CaturSeptian\PennantAnalytics\Facades\PennantAnalytics;
use CaturSeptian\PennantAnalytics\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

uses(TestCase::class)->in('Feature', 'Unit');

function metrics(): Collection
{
    return DB::table('pennant_analytics_metrics')->orderBy('variant')->orderBy('action')->get();
}

function metric(string $feature, string $variant, string $action): ?object
{
    return DB::table('pennant_analytics_metrics')
        ->where(compact('feature', 'variant', 'action'))
        ->first();
}

/**
 * 20 users enter in September (10 per variant); 6 "true" users convert in
 * October; 1 new "true" user enters in October.
 */
function seedCohorts(): void
{
    Feature::define('cohort', fn (string $s) => str_ends_with($s, 'a'));

    Carbon::setTestNow('2026-09-15 10:00:00');
    foreach (range(1, 10) as $i) {
        PennantAnalytics::impression('cohort', "u{$i}a");
        PennantAnalytics::impression('cohort', "u{$i}b");
    }

    Carbon::setTestNow('2026-10-05 10:00:00');
    foreach (range(1, 6) as $i) {
        PennantAnalytics::track('cohort', 'buy', "u{$i}a");
    }
    PennantAnalytics::impression('cohort', 'late-a');

    Carbon::setTestNow();
}
