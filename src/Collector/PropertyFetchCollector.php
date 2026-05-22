<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * Capture reads of `$obj->prop` (including `$this->prop`).
 *
 * @implements Collector<PropertyFetch, array{
 *     line: int,
 *     functionKey: string,
 *     path: string,
 *     type: string,
 *     origin: string,
 * }>
 */
final class PropertyFetchCollector implements Collector
{
    public function getNodeType(): string
    {
        return PropertyFetch::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if ($scope->isInExpressionAssign($node)) {
            return null;
        }

        $path = ExprPath::of($node);
        if ($path === null) {
            return null;
        }

        return [
            'line' => $node->getStartLine(),
            'functionKey' => ScopeKey::of($scope),
            'path' => $path,
            'type' => $scope->getType($node)->describe(VerbosityLevel::precise()),
            'origin' => 'read',
        ];
    }
}
