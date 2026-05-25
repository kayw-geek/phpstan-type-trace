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
use PHPStan\DependencyInjection\Type\DynamicReturnTypeExtensionRegistryProvider;
use PHPStan\Reflection\ReflectionProvider;
use Throwable;

/**
 * Identify the dynamic return type extensions that *could* have influenced the
 * type of a method/static-method/function call. We use `isXxxSupported()` as
 * the cheap filter — we don't actually invoke `getTypeFromXxx()` to avoid
 * recursive scope work and potential side effects.
 *
 * Returns short class basenames (e.g. `BuilderModelFinderExtension`) rather
 * than FQCNs to keep the trace compact. Only third-party extensions are
 * reported; PHPStan's own internal extensions are filtered out.
 */
final class ExtensionAttribution
{
    /**
     * @return list<string> short class names (basenames), empty if none matched
     */
    public static function ofExpr(
        Expr $expr,
        Scope $scope,
        DynamicReturnTypeExtensionRegistryProvider $registryProvider,
        ReflectionProvider $reflectionProvider,
    ): array {
        if ($expr instanceof MethodCall) {
            return self::ofMethodCall($expr, $scope, $registryProvider);
        }
        if ($expr instanceof StaticCall) {
            return self::ofStaticCall($expr, $scope, $registryProvider, $reflectionProvider);
        }
        if ($expr instanceof FuncCall) {
            return self::ofFuncCall($expr, $scope, $registryProvider, $reflectionProvider);
        }
        return [];
    }

    /**
     * @return list<string>
     */
    private static function ofMethodCall(
        MethodCall $call,
        Scope $scope,
        DynamicReturnTypeExtensionRegistryProvider $registryProvider,
    ): array {
        if (!$call->name instanceof Identifier) {
            return [];
        }
        $methodName = $call->name->toString();
        $varType = $scope->getType($call->var);
        if (!$varType->hasMethod($methodName)->yes()) {
            return [];
        }
        try {
            $methodReflection = $varType->getMethod($methodName, $scope);
        } catch (Throwable) {
            return [];
        }
        $registry = $registryProvider->getRegistry();
        $names = [];
        foreach ($varType->getObjectClassNames() as $className) {
            foreach ($registry->getDynamicMethodReturnTypeExtensionsForClass($className) as $ext) {
                if (self::isThirdParty($ext::class) && $ext->isMethodSupported($methodReflection)) {
                    $names[] = self::shortName($ext::class);
                }
            }
        }
        return self::dedup($names);
    }

    /**
     * @return list<string>
     */
    private static function ofStaticCall(
        StaticCall $call,
        Scope $scope,
        DynamicReturnTypeExtensionRegistryProvider $registryProvider,
        ReflectionProvider $reflectionProvider,
    ): array {
        if (!$call->name instanceof Identifier || !$call->class instanceof Name) {
            return [];
        }
        $methodName = $call->name->toString();
        $className = $scope->resolveName($call->class);
        if (!$reflectionProvider->hasClass($className)) {
            return [];
        }
        $classReflection = $reflectionProvider->getClass($className);
        if (!$classReflection->hasMethod($methodName)) {
            return [];
        }
        try {
            $methodReflection = $classReflection->getMethod($methodName, $scope);
        } catch (Throwable) {
            return [];
        }
        $registry = $registryProvider->getRegistry();
        $names = [];
        foreach ($registry->getDynamicStaticMethodReturnTypeExtensionsForClass($className) as $ext) {
            if (self::isThirdParty($ext::class) && $ext->isStaticMethodSupported($methodReflection)) {
                $names[] = self::shortName($ext::class);
            }
        }
        return self::dedup($names);
    }

    /**
     * @return list<string>
     */
    private static function ofFuncCall(
        FuncCall $call,
        Scope $scope,
        DynamicReturnTypeExtensionRegistryProvider $registryProvider,
        ReflectionProvider $reflectionProvider,
    ): array {
        if (!$call->name instanceof Name) {
            return [];
        }
        if (!$reflectionProvider->hasFunction($call->name, $scope)) {
            return [];
        }
        $functionReflection = $reflectionProvider->getFunction($call->name, $scope);
        $registry = $registryProvider->getRegistry();
        $names = [];
        foreach ($registry->getDynamicFunctionReturnTypeExtensions($functionReflection) as $ext) {
            if (self::isThirdParty($ext::class) && $ext->isFunctionSupported($functionReflection)) {
                $names[] = self::shortName($ext::class);
            }
        }
        return self::dedup($names);
    }

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * Keep only third-party extensions — PHPStan's built-ins are filtered out.
     */
    private static function isThirdParty(string $fqcn): bool
    {
        return !str_starts_with($fqcn, 'PHPStan\\');
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function dedup(array $names): array
    {
        return array_values(array_unique($names));
    }
}
