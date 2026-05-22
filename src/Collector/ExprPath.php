<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\VarLikeIdentifier;

final class ExprPath
{
    /**
     * Formats a variable/property access expression into a stable string key used
     * to group history events. Returns null for expressions we can't statically
     * resolve (variable-variable, dynamic property name, expression base).
     */
    public static function of(Expr $expr): ?string
    {
        if ($expr instanceof Variable) {
            return is_string($expr->name) ? '$' . $expr->name : null;
        }

        if ($expr instanceof PropertyFetch) {
            if (!$expr->name instanceof Identifier) {
                return null;
            }
            $base = self::of($expr->var);
            return $base !== null ? $base . '->' . $expr->name->toString() : null;
        }

        if ($expr instanceof StaticPropertyFetch) {
            if (!$expr->class instanceof Name || !$expr->name instanceof VarLikeIdentifier) {
                return null;
            }
            return $expr->class->toString() . '::$' . $expr->name->toString();
        }

        return null;
    }
}
