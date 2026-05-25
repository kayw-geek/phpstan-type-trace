<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PHPStan\Analyser\Scope;
use PHPStan\Type\VerbosityLevel;

/**
 * Shared logic for capturing entry-point parameter types.
 *
 * Hooked from PHPStan's In*Node virtual nodes, not from PhpParser's Param,
 * because at the Param node visit time the scope is still the enclosing
 * file/class scope — the function hasn't been entered yet, so params have
 * type `mixed` and functionKey resolves to "__top__".
 *
 * @phpstan-type ParamEvent array{line:int, pos:int, functionKey:string, path:string, type:string, origin:string}
 */
abstract class AbstractParamCollector
{
    /**
     * @return list<ParamEvent>
     */
    protected function collectFrom(Node\FunctionLike $fnNode, Scope $scope): array
    {
        $events = [];
        foreach ($fnNode->getParams() as $param) {
            $event = $this->paramEvent($param, $scope);
            if ($event !== null) {
                $events[] = $event;
            }
        }
        return $events;
    }

    /**
     * @return ParamEvent|null
     */
    private function paramEvent(Param $param, Scope $scope): ?array
    {
        if (!$param->var instanceof Variable || !is_string($param->var->name)) {
            return null;
        }
        $name = $param->var->name;
        if (!$scope->hasVariableType($name)->yes()) {
            return null;
        }
        return [
            'line' => $param->getStartLine(),
            'pos' => $param->getStartFilePos(),
            'functionKey' => ScopeKey::of($scope),
            'path' => '$' . $name,
            'type' => $scope->getVariableType($name)->describe(VerbosityLevel::precise()),
            'origin' => 'param',
        ];
    }
}
