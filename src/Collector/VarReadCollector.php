<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * @implements Collector<Variable, array{
 *     line: int,
 *     functionKey: string,
 *     varName: string,
 *     type: string,
 *     origin: string,
 * }>
 */
final class VarReadCollector implements Collector
{
    public function getNodeType(): string
    {
        return Variable::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!is_string($node->name)) {
            return null;
        }

        if ($node->name === 'this') {
            return null;
        }

        // LHS of an assignment — the AssignCollector reports the post-assign type;
        // collecting here would report the stale pre-assign type and clutter the chain.
        if ($scope->isInExpressionAssign($node)) {
            return null;
        }

        // Variable not (yet) defined at this point — PHPStan reports *ERROR* which is noise.
        if ($scope->hasVariableType($node->name)->no()) {
            return null;
        }

        return [
            'line' => $node->getStartLine(),
            'functionKey' => ScopeKey::of($scope),
            'varName' => $node->name,
            'type' => $scope->getType($node)->describe(VerbosityLevel::precise()),
            'origin' => 'read',
        ];
    }
}
