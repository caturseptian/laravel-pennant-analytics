<?php

use CaturSeptian\PennantAnalytics\Tests\TestCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
