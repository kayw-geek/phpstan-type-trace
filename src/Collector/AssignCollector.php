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
 * When the RHS is a method/static/function call, `via` lists third-party
 * dynamic return type extensions whose `isXxxSupported()` matched — i.e.
 * extensions that *may* have shaped the assigned type beyond the raw signature.
 *
 * @implements Collector<Assign, array{
 *     line: int,
 *     pos: int,
 *     functionKey: string,
 *     path: string,
 *     type: string,
 *     origin: string,
 *     via?: list<string>,
 * }>
 */
final class AssignCollector implements Collector
{
    public function __construct(
        private readonly ExtensionAttribution $extensionAttribution,
    ) {}

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

        $event = [
            'line' => $node->getStartLine(),
            'pos' => $node->getStartFilePos(),
            'functionKey' => ScopeKey::of($scope),
            'path' => $path,
            'type' => $scope->getType($node->expr)->describe(VerbosityLevel::precise()),
            'origin' => 'assign',
        ];

        $via = $this->extensionAttribution->ofExpr($node->expr, $scope);
        if ($via !== []) {
            $event['via'] = $via;
        }

        return $event;
    }
}
