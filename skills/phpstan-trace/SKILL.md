---
name: phpstan-trace
description: Use when fixing PHPStan errors in a PHP project, especially errors involving variable types, narrowing, nullability, generics, array shapes, or "X expects Y, Z given" — call the phpstan-trace CLI to retrieve the full type-inference chain (every assignment, parameter binding, narrowing, and read) that shaped the variable up to the failing line, before attempting a fix. Triggers on PHPStan/Larastan/phpstan-strict-rules error messages mentioning types.
allowed-tools: Bash(vendor/bin/phpstan-trace:*), Bash(./vendor/bin/phpstan-trace:*), Read
---

# phpstan-trace

Inspect the full type-inference chain of a variable at a PHP source location.

## When to use

Invoke this skill **before** attempting to fix any PHPStan error that involves a
variable's type — for example:

- `Parameter #1 $x of method Foo::bar() expects Baz, Qux|null given.`
- `Cannot access property $name on string.`
- `Argument of an invalid type array<string, mixed> supplied for foreach.`
- `Strict comparison using === between int and string will always evaluate to false.`
- Any `array{...}` / generic / template mismatch.

The CLI returns every event (parameter binding, assign, compound-op, narrowing,
read) that shaped the variable from function entry up to the failing line — so
you can see *where* the wrong type came in, instead of guessing.

## How to use

1. From the error message, extract:
   - the file path (relative to the project root or absolute)
   - the line number
   - the variable name (just the name; `$user`, `user->profile`, `self::$count`
     are all valid)

2. Call the CLI in JSON mode:

   ```bash
   ./vendor/bin/phpstan-trace inspect <file>:<line> <var> --json
   ```

   Quote the variable if it contains `>` or `;` to keep your shell from
   misinterpreting it:

   ```bash
   ./vendor/bin/phpstan-trace inspect src/Service.php:42 'user->profile' --json
   ```

3. Read the returned `chain` array. Each entry has `line`, `origin` (`param`,
   `assign`, `assign-op`, `assign-ref`, `array-write`, `narrow`, `read`), and
   the **inferred type at that point** (PHPStan's full description — including
   generics, array shapes, union narrowing, template parameters). Two optional
   fields may also appear:
   - `reason` — on `narrow` events, the predicate that justified the narrowing
     (`is_string($x)`, `$x !== null`, `$x instanceof Foo`, ...).
   - `via` — third-party PHPStan extensions that shaped the type at this
     event. Attached to:
       - `assign` / `assign-op` when the RHS is a call resolved by a
         dynamic-return-type extension (e.g.
         `["NewModelQueryDynamicMethodReturnTypeExtension"]`).
       - `narrow` when an `if` / ternary condition contains a call resolved by
         a type-specifying extension (e.g.
         `["AssertTypeSpecifyingExtension"]` for webmozart Assert).
       - `read` when a magic / virtual property is owned by a properties
         class-reflection extension (e.g. larastan's model attribute
         extensions).
     Built-ins shipped by `phpstan/phpstan` core are filtered out; official
     add-on packages (e.g. `phpstan/phpstan-webmozart-assert`) are listed even
     though they live under the `PHPStan\` namespace.

4. Use the chain to decide the fix:
   - If the wrong type entered as a **`param`**: fix the caller, or add a type
     guard at function entry.
   - If a **`read`** event shows the type narrowed unexpectedly: an earlier
     assignment widened it — fix that assignment.
   - If a `?Foo` reached the failing line without an `assert`/`if ($x !== null)`
     narrowing event: add the null check.
   - For generics/array-shape mismatches: the chain shows the exact concrete
     shape PHPStan inferred — match the consumer signature to it (or fix the
     shape).
   - If any event carries **`via`** and the inferred type looks wrong or
     surprising: the cited extension is the source. Read that extension's
     source (or its docs) before assuming a PHPStan bug or rewriting the call.
     On a `narrow` event, `via` together with the `reason` (the call signature)
     tells you exactly which specifier produced the post-guard type; on a
     `read` event, `via` names the properties-reflection extension that owns
     the magic / virtual attribute.

## Example

PHPStan error:

```
src/PriceCalculator.php:25: Parameter #1 $amount of method format() expects float, float|null given.
```

Step 1: Call the trace.

```bash
./vendor/bin/phpstan-trace inspect src/PriceCalculator.php:25 amount --json
```

Step 2: Read the chain.

```json
{
  "found": true,
  "chain": [
    {"line": 16, "origin": "param", "type": "float|null"},
    {"line": 25, "origin": "read", "type": "float|null"}
  ]
}
```

Step 3: The chain confirms `$amount` is still `float|null` at L25 — no
narrowing happened. The fix is to add a `??` default or a null guard between
the param and the call, not to change the signature of `format()`.

## Notes

- The CLI runs `phpstan` internally on the target file with a dump environment
  variable set; it does **not** modify your source or your phpstan config.
- A `found: false` JSON response means the variable is not trackable (e.g.
  `$arr['key']` array-dim access, dynamic property fetch) or no events occurred
  before the queried line. In that case fall back to reading the source.
- For interactive (non-agent) use, omit `--json` for a human-readable chain.
- `traceType($value)` marker calls in source still work for ad-hoc human
  debugging — the CLI and the marker share the same collector pipeline.
- **PHPStan result cache caveat.** PHPStan caches analysis per source file. If
  you change the *target file* between runs, the chain updates automatically;
  but if the chain output looks **identical to a previous run** even though you
  expect a change (e.g. you edited a callee, a stub, a config, or upgraded the
  extension), the cache is likely stale. Clear it once and re-run:

  ```bash
  ./vendor/bin/phpstan clear-result-cache
  ./vendor/bin/phpstan-trace inspect <file>:<line> <var> --json
  ```

  Symptom: dump-mode emits zero `__TYPETRACE_DUMP__` errors for a file you know
  has tracked variables → cache miss. Clear and retry before assuming a bug.
