<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * Capture reads of `$obj->prop` (including `$this->prop`).
 *
 * When the property is provided by a third-party PropertiesClassReflection
 * extension (e.g. larastan's Eloquent magic attributes), the responsible
 * extension is appended in `via` — so the chain shows *why* an undeclared
 * property nonetheless has a real type.
 *
 * @implements Collector<PropertyFetch, array{
 *     line: int,
 *     pos: int,
 *     functionKey: string,
 *     path: string,
 *     type: string,
 *     origin: string,
 *     via?: list<string>,
 * }>
 */
final class PropertyFetchCollector implements Collector
{
    public function __construct(
        private readonly ExtensionAttribution $extensionAttribution,
    ) {}

    public function getNodeType(): string
    {
        return PropertyFetch::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if ($scope->isInExpressionAssign($node)) {
            return null;
        }

        $path = ExprPath::of($node);
        if ($path === null) {
            return null;
        }

        $event = [
            'line' => $node->getStartLine(),
            'pos' => $node->getStartFilePos(),
            'functionKey' => ScopeKey::of($scope),
            'path' => $path,
            'type' => $scope->getType($node)->describe(VerbosityLevel::precise()),
            'origin' => 'read',
        ];

        $via = $this->extensionAttribution->ofPropertyFetch($node, $scope);
        if ($via !== []) {
            $event['via'] = $via;
        }

        return $event;
    }
}
