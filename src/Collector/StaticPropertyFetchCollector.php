<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * @implements Collector<StaticPropertyFetch, array{
 *     line: int,
 *     pos: int,
 *     functionKey: string,
 *     path: string,
 *     type: string,
 *     origin: string,
 * }>
 */
final class StaticPropertyFetchCollector implements Collector
{
    public function getNodeType(): string
    {
        return StaticPropertyFetch::class;
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
            'pos' => $node->getStartFilePos(),
            'functionKey' => ScopeKey::of($scope),
            'path' => $path,
            'type' => $scope->getType($node)->describe(VerbosityLevel::precise()),
            'origin' => 'read',
        ];
    }
}
