<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * Captures the post-assignment type for `$x = expr`.
 *
 * The result type of an Assign expression equals the new value of the LHS, so we
 * read it via $scope->getType($node) on the Assign itself.
 *
 * @implements Collector<Assign, array{
 *     line: int,
 *     pos: int,
 *     functionKey: string,
 *     path: string,
 *     type: string,
 *     origin: string,
 * }>
 */
final class AssignCollector implements Collector
{
    public function getNodeType(): string
    {
        return Assign::class;
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
            'origin' => 'assign',
        ];
    }
}
