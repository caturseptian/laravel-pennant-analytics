<?php

namespace CaturSeptian\PennantAnalytics\Tests\Fixtures;

class ClassBasedFlag
{
    public string $name = 'class-based-flag';

    public function resolve(mixed $scope): string
    {
        return 'variant-b';
    }
}
