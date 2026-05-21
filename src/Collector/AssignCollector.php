<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * Captures the post-assignment type for `$x = expr`, `$x .= expr`, `$x = &expr`.
 *
 * We collect the type of the RHS at the scope just before the assign takes effect,
 * which equals the resulting type of $x for simple assigns. For compound assigns
 * ($x .= ...) this is an approximation good enough for chain visualisation.
 *
 * @implements Collector<Assign|AssignOp|AssignRef, array{
 *     line: int,
 *     functionKey: string,
 *     varName: string,
 *     type: string,
 *     origin: string,
 * }>
 */
final class AssignCollector implements Collector
{
    public function getNodeType(): string
    {
        // We can only declare one node type per collector — register two via service config
        // if you want both. We start with the most common: simple Assign.
        return Assign::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$node instanceof Assign) {
            return null;
        }
        if (!$node->var instanceof Variable || !is_string($node->var->name)) {
            return null;
        }

        return [
            'line' => $node->getStartLine(),
            'functionKey' => ScopeKey::of($scope),
            'varName' => $node->var->name,
            'type' => $scope->getType($node->expr)->describe(VerbosityLevel::precise()),
            'origin' => 'assign',
        ];
    }
}
