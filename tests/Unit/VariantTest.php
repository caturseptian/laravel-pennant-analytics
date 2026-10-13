<?php

use CaturSeptian\PennantAnalytics\Support\Variant;

enum Plan: string
{
    case Pro = 'pro';
}

it('normalizes Pennant values into labels', function (mixed $value, string $label) {
    expect(Variant::label($value))->toBe($label);
})->with([
    [true, 'true'],
    [false, 'false'],
    [null, 'null'],
    ['blue-button', 'blue-button'],
    [3, '3'],
    [Plan::Pro, 'pro'],
    [['layout' => 'grid'], '{"layout":"grid"}'],
]);

it('shortens oversized labels deterministically', function () {
    $value = ['payload' => str_repeat('x', 500)];

    expect(Variant::label($value))
        ->toHaveLength(Variant::MAX_LENGTH)
        ->toBe(Variant::label($value));
});
