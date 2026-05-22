<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InArrowFunctionNode;

/**
 * @implements Collector<InArrowFunctionNode, list<array{
 *     line: int, functionKey: string, path: string, type: string, origin: string
 * }>>
 */
final class ParamInArrowFunctionCollector extends AbstractParamCollector implements Collector
{
    public function getNodeType(): string
    {
        return InArrowFunctionNode::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        $events = $this->collectFrom($node->getOriginalNode(), $scope);
        return $events === [] ? null : $events;
    }
}
