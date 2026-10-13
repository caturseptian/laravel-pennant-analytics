<?php

namespace CaturSeptian\PennantAnalytics\Statistics;

final readonly class ZTestResult
{
    public function __construct(
        /** Conversion rate of the control group, 0..1. */
        public float $controlRate,
        /** Conversion rate of the treatment group, 0..1. */
        public float $variantRate,
        /** Relative lift in percent: (variant - control) / control * 100. Null when control is 0. */
        public ?float $relativeLift,
        /** Absolute difference in percentage points. */
        public float $absoluteDifference,
        public float $zScore,
        public float $pValue,
        public float $confidence,
        /** False when samples are too small for the normal approximation to be valid. */
        public bool $hasEnoughData,
    ) {}

    public function isSignificant(): bool
    {
        return $this->hasEnoughData && $this->pValue < (1 - $this->confidence);
    }

    public function isWinning(): bool
    {
        return $this->variantRate > $this->controlRate;
    }

    /**
     * One label for the dashboard, CLI and JSON alike.
     */
    public function verdict(): string
    {
        return match (true) {
            ! $this->hasEnoughData => 'insufficient data',
            ! $this->isSignificant() => 'not significant',
            $this->isWinning() => 'significantly better',
            default => 'significantly worse',
        };
    }
}
