<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Stmt\If_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * Captures conditional guards on `if` statements that cause PHPStan to narrow
 * a value's type. Emitted at the if-body's first line so the chain shows
 * *where* a narrowing was justified, not just *that* the subsequent read had
 * a smaller type than the prior assign.
 *
 * Built-in guards (`is_*`, `instanceof`, `=== null`) are handled by
 * {@see NarrowGuardScanner::scan}. Third-party type-specifying extensions
 * (webmozart Assert, beberlei Assert, larastan auth checks, …) are handled
 * via {@see ExtensionAttribution::ofTypeSpecifyingCall} and tagged with `via`.
 *
 * @implements Collector<If_, list<array{
 *     line: int,
 *     pos: int,
 *     functionKey: string,
 *     path: string,
 *     type: string,
 *     origin: string,
 *     reason: string,
 *     via?: list<string>,
 * }>>
 */
final class NarrowingCollector implements Collector
{
    public function __construct(
        private readonly ExtensionAttribution $extensionAttribution,
    ) {}

    public function getNodeType(): string
    {
        return If_::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        // The narrow only holds inside the if-body, so anchor the event to the
        // first body statement. Anchoring it to the `if` line collides with
        // the read of the same variable in the condition expression itself.
        $bodyLine = isset($node->stmts[0]) ? $node->stmts[0]->getStartLine() : $node->getStartLine();
        $bodyPos = isset($node->stmts[0]) ? $node->stmts[0]->getStartFilePos() : $node->getStartFilePos();
        $narrowedScope = $scope->filterByTruthyValue($node->cond);
        $events = [];
        foreach (NarrowGuardScanner::scan($node->cond) as [$expr, $reason]) {
            $path = ExprPath::of($expr);
            if ($path === null) {
                continue;
            }
            $events[] = [
                'line' => $bodyLine,
                'pos' => $bodyPos,
                'functionKey' => ScopeKey::of($scope),
                'path' => $path,
                'type' => $narrowedScope->getType($expr)->describe(VerbosityLevel::precise()),
                'origin' => 'narrow',
                'reason' => NarrowGuardScanner::predicate($path, $reason),
            ];
        }
        foreach (NarrowGuardScanner::callsIn($node->cond) as $call) {
            $via = $this->extensionAttribution->ofTypeSpecifyingCall($call, $scope);
            if ($via === []) {
                continue;
            }
            foreach (SpecifierNarrow::narrowedArgs($call, $scope, $narrowedScope) as [$path, $type]) {
                $events[] = [
                    'line' => $bodyLine,
                    'pos' => $bodyPos,
                    'functionKey' => ScopeKey::of($scope),
                    'path' => $path,
                    'type' => $type,
                    'origin' => 'narrow',
                    'reason' => SpecifierNarrow::reason($call, $path),
                    'via' => $via,
                ];
            }
        }
        return $events === [] ? null : $events;
    }
}
