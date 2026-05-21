<?php

/**
 * Marker function recognized by phpstan-type-trace.
 *
 * Calls to this function are detected during PHPStan analysis and each
 * call site emits the full inference chain of $value as a PHPStan error.
 * At runtime this is a no-op.
 *
 * @param mixed       $value  The value whose type chain you want to see.
 * @param string|null $reason Optional label printed next to the chain header.
 */
function traceType(mixed $value, ?string $reason = null): void {}
