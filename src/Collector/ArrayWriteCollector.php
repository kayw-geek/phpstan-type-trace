<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ArrayDimFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * Captures `$x[] = expr` and `$x['k'] = expr` writes as mutations of the base
 * variable. Recorded under the base path (`$x`) with origin `array-write`,
 * so the chain shows when and what was written into the container — the
 * post-write container type is recoverable from any subsequent read.
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
final class ArrayWriteCollector implements Collector
{
    public function getNodeType(): string
    {
        return Assign::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$node->var instanceof ArrayDimFetch) {
            return null;
        }

        $basePath = ExprPath::of($this->rootBase($node->var));
        if ($basePath === null) {
            return null;
        }

        return [
            'line' => $node->getStartLine(),
            'pos' => $node->getStartFilePos(),
            'functionKey' => ScopeKey::of($scope),
            'path' => $basePath,
            'type' => $scope->getType($node->expr)->describe(VerbosityLevel::precise()),
            'origin' => 'array-write',
        ];
    }

    /**
     * Walk nested ArrayDimFetch chains (`$x[$i][$j] = ...`) down to the root expression.
     */
    private function rootBase(ArrayDimFetch $node): Node\Expr
    {
        $base = $node->var;
        while ($base instanceof ArrayDimFetch) {
            $base = $base->var;
        }
        return $base;
    }
}
