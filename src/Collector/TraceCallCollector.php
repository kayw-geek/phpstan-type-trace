<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\VerbosityLevel;

/**
 * @implements Collector<FuncCall, array{
 *     line: int,
 *     functionKey: string,
 *     functionLabel: string,
 *     varName: string|null,
 *     argType: string,
 *     reason: string|null,
 * }>
 */
final class TraceCallCollector implements Collector
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        if (!$node->name instanceof Node\Name) {
            return null;
        }

        $functionName = $this->reflectionProvider->resolveFunctionName($node->name, $scope);
        if ($functionName === null || strtolower($functionName) !== 'tracetype') {
            return null;
        }

        $args = $node->getArgs();
        if (count($args) === 0) {
            return null;
        }

        $valueExpr = $args[0]->value;
        $varName = $valueExpr instanceof Variable && is_string($valueExpr->name)
            ? $valueExpr->name
            : null;

        $reason = null;
        if (isset($args[1])) {
            $reasonType = $scope->getType($args[1]->value);
            if ($reasonType instanceof ConstantStringType) {
                $reason = $reasonType->getValue();
            }
        }

        return [
            'line' => $node->getStartLine(),
            'functionKey' => ScopeKey::of($scope),
            'functionLabel' => ScopeKey::label($scope),
            'varName' => $varName,
            'argType' => $scope->getType($valueExpr)->describe(VerbosityLevel::precise()),
            'reason' => $reason,
        ];
    }
}
