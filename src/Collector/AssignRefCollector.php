<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\AssignRef;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * Capture `$x = &$y` reference assignments.
 *
 * @implements Collector<AssignRef, array{
 *     line: int,
 *     pos: int,
 *     functionKey: string,
 *     path: string,
 *     type: string,
 *     origin: string,
 * }>
 */
final class AssignRefCollector implements Collector
{
    public function getNodeType(): string
    {
        return AssignRef::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        $path = ExprPath::of($node->var);
        if ($path === null) {
            return null;
        }

        return [
            'line' => $node->getStartLine(),
            'pos' => $node->getStartFilePos(),
            'functionKey' => ScopeKey::of($scope),
            'path' => $path,
            'type' => $scope->getType($node->expr)->describe(VerbosityLevel::precise()),
            'origin' => 'assign-ref',
        ];
    }
}
