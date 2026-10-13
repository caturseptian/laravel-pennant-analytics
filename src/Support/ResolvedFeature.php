<?php

namespace CaturSeptian\PennantAnalytics\Support;

/**
 * The outcome of asking Pennant for a feature: its canonical name, the value
 * it returned, and the scope Pennant actually evaluated it for.
 */
final readonly class ResolvedFeature
{
    public function __construct(
        public string $name,
        public mixed $value,
        public mixed $scope,
    ) {}
}
