<?php

namespace CaturSeptian\PennantAnalytics\Support;

use CaturSeptian\PennantAnalytics\PennantAnalyticsEngine;

/**
 * Fluent wrapper mirroring Pennant's Feature::for($scope) syntax.
 */
final readonly class PendingScopedTracking
{
    public function __construct(
        private PennantAnalyticsEngine $engine,
        private mixed $scope,
    ) {}

    public function impression(string $feature): void
    {
        $this->engine->impression($feature, $this->scope);
    }

    public function track(string $feature, string $action): void
    {
        $this->engine->track($feature, $action, $this->scope);
    }
}
