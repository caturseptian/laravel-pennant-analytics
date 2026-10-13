<?php

namespace CaturSeptian\PennantAnalytics\Facades;

use CaturSeptian\PennantAnalytics\Analytics\ExperimentReport;
use CaturSeptian\PennantAnalytics\PennantAnalyticsEngine;
use CaturSeptian\PennantAnalytics\Support\Hit;
use CaturSeptian\PennantAnalytics\Support\PendingScopedTracking;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void impression(string $feature, mixed $scope = null)
 * @method static void track(string $feature, string $action, mixed $scope = null)
 * @method static PendingScopedTracking for(mixed $scope)
 * @method static PennantAnalyticsEngine resolveGuestUsing(Closure $resolver)
 * @method static ExperimentReport report(string $feature, string $action, ?string $from = null, ?string $to = null)
 * @method static void flush()
 * @method static list<Hit> pending()
 * @method static string impressionAction()
 *
 * @see PennantAnalyticsEngine
 */
class PennantAnalytics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PennantAnalyticsEngine::class;
    }
}
