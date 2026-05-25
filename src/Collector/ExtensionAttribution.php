<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Collector;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\Type\DynamicReturnTypeExtensionRegistryProvider;
use PHPStan\Reflection\PropertiesClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\FunctionTypeSpecifyingExtension;
use PHPStan\Type\MethodTypeSpecifyingExtension;
use PHPStan\Type\StaticMethodTypeSpecifyingExtension;
use ReflectionClass;
use Throwable;

/**
 * Attributes type changes in the chain back to the third-party PHPStan
 * extension that caused them. Three extension categories are supported:
 *
 *   - Dynamic return type extensions (via {@see ofExpr}) — for `assign` /
 *     `assign-op` events whose RHS is a call.
 *   - Type specifying extensions (via {@see ofTypeSpecifyingCall}) — for
 *     `narrow` events emitted from `Assert::notNull($x)`-style guards.
 *   - Properties class reflection extensions (via {@see ofPropertyFetch}) —
 *     for `read` events on dynamic / magic properties (e.g. Eloquent models).
 *
 * We use `isXxxSupported()` as the cheap filter — never invoke `specifyTypes()`
 * or `getProperty()` to avoid recursive scope work and side effects. Returns
 * short class basenames to keep the trace compact. Only third-party extensions
 * are reported; PHPStan's own internals are filtered out.
 */
final class ExtensionAttribution
{
    public function __construct(
        private readonly Container $container,
        private readonly DynamicReturnTypeExtensionRegistryProvider $registryProvider,
        private readonly ReflectionProvider $reflectionProvider,
    ) {}

    /**
     * @return list<string> short class names, empty if none matched
     */
    public function ofExpr(Expr $expr, Scope $scope): array
    {
        if ($expr instanceof MethodCall) {
            return $this->ofMethodCall($expr, $scope);
        }
        if ($expr instanceof StaticCall) {
            return $this->ofStaticCall($expr, $scope);
        }
        if ($expr instanceof FuncCall) {
            return $this->ofFuncCall($expr, $scope);
        }
        return [];
    }

    /**
     * @return list<string>
     */
    public function ofTypeSpecifyingCall(Expr $call, Scope $scope): array
    {
        if ($call instanceof MethodCall) {
            return $this->ofMethodSpecifier($call, $scope);
        }
        if ($call instanceof StaticCall) {
            return $this->ofStaticMethodSpecifier($call, $scope);
        }
        if ($call instanceof FuncCall) {
            return $this->ofFunctionSpecifier($call, $scope);
        }
        return [];
    }

    /**
     * @return list<string>
     */
    public function ofPropertyFetch(PropertyFetch $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }
        $propName = $node->name->toString();
        $varType = $scope->getType($node->var);
        $extensions = $this->container->getServicesByTag('phpstan.broker.propertiesClassReflectionExtension');
        $names = [];
        foreach ($varType->getObjectClassNames() as $className) {
            if (!$this->reflectionProvider->hasClass($className)) {
                continue;
            }
            $classReflection = $this->reflectionProvider->getClass($className);
            foreach ($extensions as $ext) {
                if (!$ext instanceof PropertiesClassReflectionExtension) {
                    continue;
                }
                if (!self::isThirdParty($ext::class)) {
                    continue;
                }
                try {
                    if ($ext->hasProperty($classReflection, $propName)) {
                        $names[] = self::shortName($ext::class);
                    }
                } catch (Throwable) {
                    // hostile extension; ignore.
                }
            }
        }
        return self::dedup($names);
    }

    /**
     * @return list<string>
     */
    private function ofMethodCall(MethodCall $call, Scope $scope): array
    {
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
        $registry = $this->registryProvider->getRegistry();
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
    private function ofStaticCall(StaticCall $call, Scope $scope): array
    {
        if (!$call->name instanceof Identifier || !$call->class instanceof Name) {
            return [];
        }
        $methodName = $call->name->toString();
        $className = $scope->resolveName($call->class);
        if (!$this->reflectionProvider->hasClass($className)) {
            return [];
        }
        $classReflection = $this->reflectionProvider->getClass($className);
        if (!$classReflection->hasMethod($methodName)) {
            return [];
        }
        try {
            $methodReflection = $classReflection->getMethod($methodName, $scope);
        } catch (Throwable) {
            return [];
        }
        $registry = $this->registryProvider->getRegistry();
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
    private function ofFuncCall(FuncCall $call, Scope $scope): array
    {
        if (!$call->name instanceof Name) {
            return [];
        }
        if (!$this->reflectionProvider->hasFunction($call->name, $scope)) {
            return [];
        }
        $functionReflection = $this->reflectionProvider->getFunction($call->name, $scope);
        $registry = $this->registryProvider->getRegistry();
        $names = [];
        foreach ($registry->getDynamicFunctionReturnTypeExtensions($functionReflection) as $ext) {
            if (self::isThirdParty($ext::class) && $ext->isFunctionSupported($functionReflection)) {
                $names[] = self::shortName($ext::class);
            }
        }
        return self::dedup($names);
    }

    /**
     * @return list<string>
     */
    private function ofMethodSpecifier(MethodCall $call, Scope $scope): array
    {
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
        $context = TypeSpecifierContext::createTruthy();
        $extensions = $this->container->getServicesByTag('phpstan.typeSpecifier.methodTypeSpecifyingExtension');
        $names = [];
        foreach ($varType->getObjectClassNames() as $className) {
            $hierarchy = $this->classHierarchy($className);
            foreach ($extensions as $ext) {
                if (!$ext instanceof MethodTypeSpecifyingExtension) {
                    continue;
                }
                if (!self::isThirdParty($ext::class)) {
                    continue;
                }
                if (!in_array($ext->getClass(), $hierarchy, true)) {
                    continue;
                }
                try {
                    if ($ext->isMethodSupported($methodReflection, $call, $context)) {
                        $names[] = self::shortName($ext::class);
                    }
                } catch (Throwable) {
                    // skip
                }
            }
        }
        return self::dedup($names);
    }

    /**
     * @return list<string>
     */
    private function ofStaticMethodSpecifier(StaticCall $call, Scope $scope): array
    {
        if (!$call->name instanceof Identifier || !$call->class instanceof Name) {
            return [];
        }
        $methodName = $call->name->toString();
        $className = $scope->resolveName($call->class);
        if (!$this->reflectionProvider->hasClass($className)) {
            return [];
        }
        $classReflection = $this->reflectionProvider->getClass($className);
        if (!$classReflection->hasMethod($methodName)) {
            return [];
        }
        try {
            $methodReflection = $classReflection->getMethod($methodName, $scope);
        } catch (Throwable) {
            return [];
        }
        $hierarchy = $this->classHierarchy($className);
        $context = TypeSpecifierContext::createTruthy();
        $extensions = $this->container->getServicesByTag('phpstan.typeSpecifier.staticMethodTypeSpecifyingExtension');
        $names = [];
        foreach ($extensions as $ext) {
            if (!$ext instanceof StaticMethodTypeSpecifyingExtension) {
                continue;
            }
            if (!self::isThirdParty($ext::class)) {
                continue;
            }
            if (!in_array($ext->getClass(), $hierarchy, true)) {
                continue;
            }
            try {
                if ($ext->isStaticMethodSupported($methodReflection, $call, $context)) {
                    $names[] = self::shortName($ext::class);
                }
            } catch (Throwable) {
                // skip
            }
        }
        return self::dedup($names);
    }

    /**
     * @return list<string>
     */
    private function ofFunctionSpecifier(FuncCall $call, Scope $scope): array
    {
        if (!$call->name instanceof Name) {
            return [];
        }
        if (!$this->reflectionProvider->hasFunction($call->name, $scope)) {
            return [];
        }
        $functionReflection = $this->reflectionProvider->getFunction($call->name, $scope);
        $context = TypeSpecifierContext::createTruthy();
        $extensions = $this->container->getServicesByTag('phpstan.typeSpecifier.functionTypeSpecifyingExtension');
        $names = [];
        foreach ($extensions as $ext) {
            if (!$ext instanceof FunctionTypeSpecifyingExtension) {
                continue;
            }
            if (!self::isThirdParty($ext::class)) {
                continue;
            }
            try {
                if ($ext->isFunctionSupported($functionReflection, $call, $context)) {
                    $names[] = self::shortName($ext::class);
                }
            } catch (Throwable) {
                // skip
            }
        }
        return self::dedup($names);
    }

    /**
     * Mirror PHPStan's own filtering: an extension applies if its getClass()
     * appears in [className, ...parents, ...interfaces].
     *
     * @return list<string>
     */
    private function classHierarchy(string $className): array
    {
        if (!$this->reflectionProvider->hasClass($className)) {
            return [$className];
        }
        $class = $this->reflectionProvider->getClass($className);
        $names = [$className];
        foreach ($class->getParentClassesNames() as $parent) {
            $names[] = $parent;
        }
        foreach ($class->getNativeReflection()->getInterfaceNames() as $interface) {
            $names[] = $interface;
        }
        return $names;
    }

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * Keep only third-party extensions — PHPStan core built-ins are filtered.
     *
     * Detection is by source-file location, not namespace: official add-on
     * packages such as `phpstan/phpstan-webmozart-assert` ship classes under
     * the `PHPStan\` namespace but are genuinely third-party from the user's
     * perspective. Core ships from `vendor/phpstan/phpstan/...` (or the same
     * path inside `phpstan.phar`).
     */
    private static function isThirdParty(string $fqcn): bool
    {
        if (str_starts_with($fqcn, 'Kayw\\PhpstanTypeTrace\\')) {
            return false;
        }
        if (!class_exists($fqcn) && !interface_exists($fqcn)) {
            return false;
        }
        try {
            /** @var class-string $fqcn */
            $file = (new ReflectionClass($fqcn))->getFileName();
        } catch (Throwable) {
            return false;
        }
        if ($file === false) {
            return false;
        }
        return !str_contains($file, '/phpstan/phpstan/')
            && !str_contains($file, '/phpstan.phar/');
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
