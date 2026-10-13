<?php

namespace CaturSeptian\PennantAnalytics\Support;

use BackedEnum;
use CaturSeptian\PennantAnalytics\PennantAnalyticsEngine;
use UnitEnum;

/**
 * Fluent wrapper mirroring Pennant's Feature::for($scope) syntax.
 */
final readonly class PendingScopedTracking
{
    public function __construct(
        private PennantAnalyticsEngine $engine,
        private mixed $scope,
    ) {}

    public function impression(string|BackedEnum|UnitEnum $feature): void
    {
        $this->engine->impression($feature, $this->scope);
    }

    public function track(string|BackedEnum|UnitEnum $feature, string $action): void
    {
        $this->engine->track($feature, $action, $this->scope);
    }
}
