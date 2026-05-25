<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\Ternary;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * Same idea as NarrowingCollector but for ternaries. Without this, code like
 * `is_string($x) ? $x : 'd'` shows a read with a silently narrowed type and
 * no explanation. Emits a narrow event anchored at the then-branch line so
 * the read inside the branch is preceded by the predicate that justifies it.
 *
 * Shorthand `?:` is skipped — its then-branch is the condition itself, so
 * there's no separate read to narrow.
 *
 * @implements Collector<Ternary, list<array{
 *     line: int,
 *     functionKey: string,
 *     path: string,
 *     type: string,
 *     origin: string,
 *     reason: string,
 * }>>
 */
final class TernaryNarrowingCollector implements Collector
{
    public function getNodeType(): string
    {
        return Ternary::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if ($node->if === null) {
            return null;
        }

        $branchLine = $node->if->getStartLine();
        $narrowedScope = $scope->filterByTruthyValue($node->cond);
        $events = [];
        foreach (NarrowGuardScanner::scan($node->cond) as [$expr, $reason]) {
            $path = ExprPath::of($expr);
            if ($path === null) {
                continue;
            }
            $events[] = [
                'line' => $branchLine,
                'functionKey' => ScopeKey::of($scope),
                'path' => $path,
                'type' => $narrowedScope->getType($expr)->describe(VerbosityLevel::precise()),
                'origin' => 'narrow',
                'reason' => NarrowGuardScanner::predicate($path, $reason),
            ];
        }
        return $events === [] ? null : $events;
    }
}
