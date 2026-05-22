<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\If_;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\Type\VerbosityLevel;

/**
 * Captures conditional guards (=== null / !== null / instanceof / is_*) that
 * cause PHPStan to narrow a value's type. Emitted at the `if` line so the
 * chain can show *where* a narrowing was justified, not just *that* the
 * subsequent read had a smaller type than the prior assign.
 *
 * Recurses through logical AND/OR so compound guards each contribute an event.
 *
 * @implements Collector<If_, list<array{
 *     line: int,
 *     functionKey: string,
 *     path: string,
 *     type: string,
 *     origin: string,
 *     reason: string,
 * }>>
 */
final class NarrowingCollector implements Collector
{
    /** Type predicates whose first argument PHPStan uses to narrow scope. */
    private const TYPE_PREDICATES = [
        'is_array', 'is_bool', 'is_callable', 'is_countable', 'is_float',
        'is_int', 'is_integer', 'is_iterable', 'is_long', 'is_null',
        'is_numeric', 'is_object', 'is_resource', 'is_scalar', 'is_string',
    ];

    public function getNodeType(): string
    {
        return If_::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        $line = $node->getStartLine();
        $events = [];
        foreach ($this->collectGuards($node->cond) as [$expr, $reason]) {
            $path = ExprPath::of($expr);
            if ($path === null) {
                continue;
            }
            $events[] = [
                'line' => $line,
                'functionKey' => ScopeKey::of($scope),
                'path' => $path,
                'type' => $scope->getType($expr)->describe(VerbosityLevel::precise()),
                'origin' => 'narrow',
                'reason' => $reason,
            ];
        }
        return $events === [] ? null : $events;
    }

    /**
     * Walk the if-condition, yielding (target-expression, human-readable guard) pairs.
     *
     * @return iterable<array{0: Expr, 1: string}>
     */
    private function collectGuards(Expr $cond): iterable
    {
        if (
            $cond instanceof BinaryOp\BooleanAnd
            || $cond instanceof BinaryOp\BooleanOr
            || $cond instanceof BinaryOp\LogicalAnd
            || $cond instanceof BinaryOp\LogicalOr
        ) {
            yield from $this->collectGuards($cond->left);
            yield from $this->collectGuards($cond->right);
            return;
        }

        if ($cond instanceof BinaryOp\Identical || $cond instanceof BinaryOp\NotIdentical) {
            $op = $cond instanceof BinaryOp\Identical ? '===' : '!==';
            if ($this->isNullConst($cond->right)) {
                yield [$cond->left, $op . ' null'];
            } elseif ($this->isNullConst($cond->left)) {
                yield [$cond->right, $op . ' null'];
            }
            return;
        }

        if ($cond instanceof Instanceof_) {
            $className = $cond->class instanceof Name ? $cond->class->toString() : '?';
            yield [$cond->expr, 'instanceof ' . $className];
            return;
        }

        if (
            $cond instanceof FuncCall
            && $cond->name instanceof Name
            && in_array($cond->name->toLowerString(), self::TYPE_PREDICATES, true)
            && isset($cond->args[0])
            && $cond->args[0] instanceof Arg
        ) {
            yield [$cond->args[0]->value, $cond->name->toLowerString() . '()'];
        }
    }

    private function isNullConst(Expr $expr): bool
    {
        return $expr instanceof ConstFetch
            && $expr->name->toLowerString() === 'null';
    }
}
