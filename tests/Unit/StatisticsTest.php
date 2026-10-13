<?php

use CaturSeptian\PennantAnalytics\Statistics\NormalDistribution;
use CaturSeptian\PennantAnalytics\Statistics\TwoProportionZTest;

it('approximates the standard normal CDF', function (float $z, float $expected) {
    expect(NormalDistribution::cdf($z))->toEqualWithDelta($expected, 1e-6);
})->with([
    [0.0, 0.5],
    [1.0, 0.8413447],
    [1.959964, 0.975],
    [-2.575829, 0.005],
    [3.0, 0.9986501],
]);

it('derives critical values from the confidence level', function () {
    expect(NormalDistribution::criticalValue(0.95))->toEqualWithDelta(1.959964, 1e-5)
        ->and(NormalDistribution::criticalValue(0.99))->toEqualWithDelta(2.575829, 1e-5);
});

it('matches a hand-computed two-proportion z-test', function () {
    // Control 200/2000 = 10%, variant 260/2000 = 13%.
    // pooled = 460/4000 = 0.115, SE = sqrt(.115*.885*(2/2000)) = 0.0100884
    // z = 0.03 / 0.0100884 = 2.97372, p = 0.002943
    $result = (new TwoProportionZTest)->compare(200, 2000, 260, 2000);

    expect($result->controlRate)->toBe(0.1)
        ->and($result->variantRate)->toBe(0.13)
        ->and($result->relativeLift)->toEqualWithDelta(30.0, 1e-9)
        ->and($result->absoluteDifference)->toEqualWithDelta(3.0, 1e-9)
        ->and($result->zScore)->toEqualWithDelta(2.97372, 1e-4)
        ->and($result->pValue)->toEqualWithDelta(0.002943, 1e-5)
        ->and($result->isSignificant())->toBeTrue()
        ->and($result->isWinning())->toBeTrue();
});

it('equals the chi-square statistic for a 2x2 table', function () {
    $result = (new TwoProportionZTest)->compare(120, 1000, 150, 1000);

    // Pearson chi-square (no continuity correction) computed independently.
    $observed = [[120, 880], [150, 850]];
    $rows = [1000, 1000];
    $cols = [270, 1730];
    $chi = 0.0;
    foreach ($observed as $i => $row) {
        foreach ($row as $j => $o) {
            $e = $rows[$i] * $cols[$j] / 2000;
            $chi += ($o - $e) ** 2 / $e;
        }
    }

    expect($result->zScore ** 2)->toEqualWithDelta($chi, 1e-9);
});

it('does not call small or noisy differences significant', function () {
    expect((new TwoProportionZTest)->compare(100, 1000, 105, 1000)->isSignificant())->toBeFalse();
});

it('withholds a verdict when samples are too small', function () {
    $result = (new TwoProportionZTest(minSampleSize: 100))->compare(1, 20, 15, 20);

    expect($result->hasEnoughData)->toBeFalse()
        ->and($result->isSignificant())->toBeFalse();
});

it('handles empty groups and zero-variance data without dividing by zero', function () {
    expect((new TwoProportionZTest)->compare(0, 0, 0, 0)->pValue)->toBe(1.0)
        ->and((new TwoProportionZTest)->compare(0, 500, 0, 500)->relativeLift)->toBeNull();
});

it('rejects impossible inputs', function () {
    (new TwoProportionZTest)->compare(11, 10, 1, 10);
})->throws(InvalidArgumentException::class);
