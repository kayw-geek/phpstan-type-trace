<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;

/**
 * Shared guard extraction for any conditional (`if`, ternary, …). Walks a
 * condition expression and yields the target sub-expression each guard
 * narrows, paired with a human-readable predicate fragment.
 */
final class NarrowGuardScanner
{
    /** Type predicates whose first argument PHPStan uses to narrow scope. */
    private const TYPE_PREDICATES = [
        'is_array', 'is_bool', 'is_callable', 'is_countable', 'is_float',
        'is_int', 'is_integer', 'is_iterable', 'is_long', 'is_null',
        'is_numeric', 'is_object', 'is_resource', 'is_scalar', 'is_string',
    ];

    /**
     * @return iterable<array{0: Expr, 1: string}>
     */
    public static function scan(Expr $cond): iterable
    {
        if (
            $cond instanceof BinaryOp\BooleanAnd
            || $cond instanceof BinaryOp\BooleanOr
            || $cond instanceof BinaryOp\LogicalAnd
            || $cond instanceof BinaryOp\LogicalOr
        ) {
            yield from self::scan($cond->left);
            yield from self::scan($cond->right);
            return;
        }

        if ($cond instanceof BinaryOp\Identical || $cond instanceof BinaryOp\NotIdentical) {
            $op = $cond instanceof BinaryOp\Identical ? '===' : '!==';
            if (self::isNullConst($cond->right)) {
                yield [$cond->left, $op . ' null'];
            } elseif (self::isNullConst($cond->left)) {
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

    /**
     * Combine the narrowed path and the guard kind into a self-contained
     * predicate string, e.g. "$x !== null", "$x instanceof Foo", "is_int($x)".
     */
    public static function predicate(string $path, string $reason): string
    {
        if (str_ends_with($reason, '()')) {
            return substr($reason, 0, -2) . '(' . $path . ')';
        }
        return $path . ' ' . $reason;
    }

    /**
     * Walk a condition expression and yield every method/static/function call
     * that is *not* already covered by {@see scan} (built-in `is_*` predicates).
     * Used to surface third-party type-specifying extensions like webmozart
     * Assert, beberlei Assert, larastan auth checks, etc.
     *
     * @return iterable<FuncCall|MethodCall|StaticCall>
     */
    public static function callsIn(Expr $cond): iterable
    {
        if (
            $cond instanceof BinaryOp\BooleanAnd
            || $cond instanceof BinaryOp\BooleanOr
            || $cond instanceof BinaryOp\LogicalAnd
            || $cond instanceof BinaryOp\LogicalOr
        ) {
            yield from self::callsIn($cond->left);
            yield from self::callsIn($cond->right);
            return;
        }

        if (
            $cond instanceof FuncCall
            && $cond->name instanceof Name
            && in_array($cond->name->toLowerString(), self::TYPE_PREDICATES, true)
            && isset($cond->args[0])
            && $cond->args[0] instanceof Arg
        ) {
            return;
        }

        if ($cond instanceof FuncCall || $cond instanceof MethodCall || $cond instanceof StaticCall) {
            yield $cond;
        }
    }

    private static function isNullConst(Expr $expr): bool
    {
        return $expr instanceof ConstFetch
            && $expr->name->toLowerString() === 'null';
    }
}
