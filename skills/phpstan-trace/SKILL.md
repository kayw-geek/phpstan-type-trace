---
name: phpstan-trace
description: Use when a PHPStan/Larastan type error is NON-TRIVIAL to fix by reading the source alone — the variable crosses multiple scopes (closure, callback, helper), is shaped by a third-party extension (larastan Eloquent magic attributes, webmozart/assert, doctrine generics, dynamic-return-type extensions), lives in a method longer than ~30 lines, involves generics / array shapes / template parameters, or a first read of the source already failed to explain the inferred type. Call the phpstan-trace CLI to retrieve the full type-inference chain BEFORE guessing a fix. Skip for trivial errors where the source obviously explains the type at the error line.
allowed-tools: Bash(vendor/bin/phpstan-trace:*), Bash(./vendor/bin/phpstan-trace:*), Read
---

# phpstan-trace

Inspect the full type-inference chain of a variable at a PHP source location.

## When to use

Invoke this skill **before** attempting to fix a PHPStan type error when **any** of these signals are present:

- The variable is touched in **2+ scopes** (passed into a closure, callback, helper, or array map).
- A **third-party extension** is plausibly involved: larastan Eloquent magic attributes (`$user->name`, `$model->created_at`), webmozart/assert or beberlei/assert guards, doctrine collections, dynamic-return-type extensions (e.g. `Model::query()`).
- The enclosing method is **longer than ~30 lines** OR the file exceeds ~100 lines.
- The error involves **generics, `array{...}` shapes, or template parameters** that don't match what you expect from reading the signature.
- You **already read the source** and the inferred type at the error line still doesn't make sense.
- The error is `Cannot access property X on string`, `... expects Foo, Foo|null given`, `... always evaluates to true/false`, or a similar "where did this type come from?" message in any of the above contexts.

The CLI returns every event (parameter binding, assign, compound-op, narrowing, read) that shaped the variable from function entry up to the failing line — so you can see *where* the wrong type came in, instead of guessing.

## When NOT to use

Skip the CLI and just read the source if **all** of these hold:

- The file is short (< 100 lines) AND the method is short (< 30 lines).
- The error message names a single line and the variable is assigned/parameter-bound in plain sight nearby.
- No third-party extension is shaping the type (vanilla PHP, no Eloquent magic, no assert library).
- The fix is obviously a missing `??` default, a missing null guard, or a typo in a property name.

Also skip for non-type errors: `Call to undefined method`, `Class X not found`, `Access to undefined constant` — these don't need a type-inference chain.

Rule of thumb: if you'd confidently write the fix in under 30 seconds from reading the source, skip the trace. If you'd be guessing, run it.

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

## Example — when the trace earns its keep

PHPStan error in a Laravel/larastan project:

```
app/Http/Controllers/ReportController.php:84:
Parameter #1 $value of function number_format expects float, mixed given.
```

The relevant code:

```php
public function export(Request $request): StreamedResponse
{
    $users = User::query()->whereActive()->get();

    return response()->streamDownload(function () use ($users, $request) {
        foreach ($users as $user) {
            $amount = $this->resolveAmount($user, $request->input('period'));
            echo number_format($amount, 2); // line 84
        }
    }, 'report.csv');
}

private function resolveAmount(User $user, mixed $period): float
{
    return $user->lifetime_value ?? 0.0;
}
```

Reading the source: `resolveAmount()` is typed `float`. The error says `mixed`. What?

Three scopes are involved (`export` → closure → `resolveAmount`), and `lifetime_value` is a larastan-typed Eloquent magic attribute. **Source-only reading would either miss the cause or take 10 minutes of grep.** Run the trace:

```bash
./vendor/bin/phpstan-trace inspect app/Http/Controllers/ReportController.php:84 amount --json
```

Returned chain:

```json
{
  "found": true,
  "chain": [
    {"line": 83, "origin": "assign", "type": "mixed",
     "via": ["ModelDynamicMethodReturnTypeExtension"]},
    {"line": 84, "origin": "read", "type": "mixed"}
  ]
}
```

The `via` tells you instantly: larastan's `ModelDynamicMethodReturnTypeExtension` resolved `resolveAmount(...)` to `mixed`, not `float`. Why? Because in this codebase `User` is a generic stub without a registered Eloquent ide-helper, and larastan widens the return when the receiver type is ambiguous. The fix is not in `export()` — it's adding a proper `@property float $lifetime_value` PHPDoc on `User`, or running `php artisan ide-helper:models`.

Without the trace you'd cast in `export()` (wrong fix, masks the root cause) or rewrite `resolveAmount()` to return `mixed` (cascades the problem). The chain points directly at the extension responsible.

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
