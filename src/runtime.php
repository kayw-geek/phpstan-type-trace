<?php

declare(strict_types=1);

if (!function_exists('traceType')) {
    /**
     * Runtime no-op. The PHPStan extension picks up call sites statically.
     */
    function traceType(mixed $value, ?string $reason = null): void {}
}
