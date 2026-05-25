<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Type\VerbosityLevel;

/**
 * Builds narrow-event payloads for third-party type-specifying extensions.
 *
 * `ofExpr`-style return-type extensions ship via {@see ExtensionAttribution};
 * specifier extensions need additional work: we have to figure out *which*
 * arg's type changed under the truthy scope, since the call itself doesn't
 * directly point at a path the way an `if ($x !== null)` does.
 */
final class SpecifierNarrow
{
    /**
     * For each arg of the call whose type narrowed between $before and $after,
     * yield `[path, narrowed-type-description]`.
     *
     * @return iterable<array{0: string, 1: string}>
     */
    public static function narrowedArgs(Expr $call, Scope $before, Scope $after): iterable
    {
        if (!$call instanceof FuncCall && !$call instanceof MethodCall && !$call instanceof StaticCall) {
            return;
        }
        foreach ($call->getArgs() as $arg) {
            $path = ExprPath::of($arg->value);
            if ($path === null) {
                continue;
            }
            $beforeType = $before->getType($arg->value);
            $afterType = $after->getType($arg->value);
            if ($beforeType->equals($afterType)) {
                continue;
            }
            yield [$path, $afterType->describe(VerbosityLevel::precise())];
        }
    }

    /**
     * Build a compact human-readable reason string for the narrow row:
     *   FuncCall    -> "funcName($path)"
     *   StaticCall  -> "Class::method($path)"
     *   MethodCall  -> "$receiver->method($path)"
     */
    public static function reason(Expr $call, string $path): string
    {
        if ($call instanceof FuncCall) {
            $name = $call->name instanceof Name ? $call->name->toString() : '?';
            return $name . '(' . $path . ')';
        }
        if ($call instanceof StaticCall) {
            $class = $call->class instanceof Name ? $call->class->toString() : '?';
            $method = $call->name instanceof Identifier ? $call->name->toString() : '?';
            return $class . '::' . $method . '(' . $path . ')';
        }
        if ($call instanceof MethodCall) {
            $receiver = ExprPath::of($call->var) ?? '?';
            $method = $call->name instanceof Identifier ? $call->name->toString() : '?';
            return $receiver . '->' . $method . '(' . $path . ')';
        }
        return '?';
    }
}
