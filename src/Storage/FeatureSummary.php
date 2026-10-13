<?php

namespace CaturSeptian\PennantAnalytics\Storage;

final readonly class FeatureSummary
{
    public function __construct(
        public string $feature,
        public int $variants,
        public int $impressions,
        public string $lastSeen,
    ) {}
}
