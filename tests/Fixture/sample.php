<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Tests\Fixture;

function lookup(?string $name): string
{
    $name ??= 'guest';
    if ($name === '') {
        $name = null;
    }
    traceType($name, 'before strtolower');
    return strtolower($name ?? '');
}

function widening(): void
{
    $x = 1;
    if (rand() > 0.5) {
        $x = 'two';
    }
    traceType($x);
}
