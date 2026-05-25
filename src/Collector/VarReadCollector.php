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
 *     pos: int,
 *     functionKey: string,
 *     path: string,
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
        if (!is_string($node->name) || $node->name === 'this') {
            return null;
        }

        if ($scope->isInExpressionAssign($node)) {
            return null;
        }

        if ($scope->hasVariableType($node->name)->no()) {
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
