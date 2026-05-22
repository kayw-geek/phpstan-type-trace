<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PHPStan\Analyser\Scope;

final class ScopeKey
{
    public static function of(Scope $scope): string
    {
        $fn = $scope->getFunctionName() ?? '__top__';
        return $scope->getFile() . '::' . $fn;
    }

    public static function label(Scope $scope): string
    {
        $fn = $scope->getFunctionName();
        if ($fn === null) {
            return '<file scope ' . basename($scope->getFile()) . '>';
        }

        $class = self::classNameOrNull($scope);
        return $class !== null ? $class . '::' . $fn : $fn;
    }

    private static function classNameOrNull(Scope $scope): ?string
    {
        $class = $scope->getClassReflection();
        return $class?->getName();
    }
}
