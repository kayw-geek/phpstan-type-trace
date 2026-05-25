<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\AssignOp;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\Type\DynamicReturnTypeExtensionRegistryProvider;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\VerbosityLevel;

/**
 * Compound assignments: ??=, +=, -=, *=, /=, %=, .=, **=, |=, &=, ^=, <<=, >>=.
 *
 * Result type of an AssignOp expression equals the new value of the LHS, so we
 * read $scope->getType($node).
 *
 * When the RHS is a method/static/function call, `via` lists third-party
 * dynamic return type extensions that *may* have influenced the result type.
 *
 * @implements Collector<AssignOp, array{
 *     line: int,
 *     pos: int,
 *     functionKey: string,
 *     path: string,
 *     type: string,
 *     origin: string,
 *     via?: list<string>,
 * }>
 */
final class AssignOpCollector implements Collector
{
    public function __construct(
        private readonly DynamicReturnTypeExtensionRegistryProvider $registryProvider,
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

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

        $event = [
            'line' => $node->getStartLine(),
            'pos' => $node->getStartFilePos(),
            'functionKey' => ScopeKey::of($scope),
            'path' => $path,
            'type' => $scope->getType($node)->describe(VerbosityLevel::precise()),
            'origin' => 'assign-op',
        ];

        $via = ExtensionAttribution::ofExpr($node->expr, $scope, $this->registryProvider, $this->reflectionProvider);
        if ($via !== []) {
            $event['via'] = $via;
        }

        return $event;
    }
}
