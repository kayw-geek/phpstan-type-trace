<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Node\InClosureNode;

/**
 * @implements Collector<InClosureNode, list<array{
 *     line: int, functionKey: string, path: string, type: string, origin: string
 * }>>
 */
final class ParamInClosureCollector extends AbstractParamCollector implements Collector
{
    public function getNodeType(): string
    {
        return InClosureNode::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        $events = $this->collectFrom($node->getOriginalNode(), $scope);
        return $events === [] ? null : $events;
    }
}
