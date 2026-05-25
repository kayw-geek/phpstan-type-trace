<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\AssignOp;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * Compound assignments: ??=, +=, -=, *=, /=, %=, .=, **=, |=, &=, ^=, <<=, >>=.
 *
 * Result type of an AssignOp expression equals the new value of the LHS, so we
 * read $scope->getType($node).
 *
 * @implements Collector<AssignOp, array{
 *     line: int,
 *     pos: int,
 *     functionKey: string,
 *     path: string,
 *     type: string,
 *     origin: string,
 * }>
 */
final class AssignOpCollector implements Collector
{
    public function getNodeType(): string
    {
        return AssignOp::class;
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
            'type' => $scope->getType($node)->describe(VerbosityLevel::precise()),
            'origin' => 'assign-op',
        ];
    }
}
