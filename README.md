# phpstan-type-trace

> See the full inference chain of any value, not just a single snapshot.

PHPStan's built-in `\PHPStan\dumpType($x)` prints the type at one line. When
PHPStan says *"expected `int`, got `int|null`"* and the actual assignment
happened 200 lines earlier, that snapshot is not enough.

This extension adds a `traceType()` marker that prints the **delta chain** of
how a value's type evolved up to the call site — param entry, every
assignment, every compound op, every narrowing.

## Install

```bash
composer require --dev kayw-geek/phpstan-type-trace
```

Auto-registered via
[phpstan-extension-installer](https://github.com/phpstan/extension-installer).
Otherwise add to `phpstan.neon`:

```neon
includes:
    - vendor/kayw-geek/phpstan-type-trace/extension.neon
```

## Use

```php
final class PriceCalculator
{
    public function compute(int $base, ?float $discount = null): float
    {
        $base = $base + 10;
        $base *= 2;
        $discount ??= 0.1;
        traceType($discount, 'after ??=');
        return $base * (1 - $discount);
    }
}
```

Run `vendor/bin/phpstan analyse`:

```
  Line   PriceCalculator.php
 ------ -----------------------------------------------------------------
  9      Type chain for $discount in App\PriceCalculator::compute — after ??=
           L4     param      float|null
           L8     assign-op  float
```

The narrowing from `float|null` to `float` is now obvious — the `??=`
defaulted away the null.

## Use from an agent (no source edits)

When an LLM agent (Claude Code, Cursor, etc.) is fixing PHPStan errors, it
doesn't have time to inject `traceType()` markers and re-run analysis. The
`phpstan-trace` CLI gives the same chain for any variable at any line, on
demand, without touching source:

```bash
./vendor/bin/phpstan-trace inspect src/PriceCalculator.php:9 discount --json
```

```json
{
  "found": true,
  "file": "/abs/path/src/PriceCalculator.php",
  "line": 9,
  "path": "$discount",
  "functionKey": "App\\PriceCalculator::compute",
  "chain": [
    {"line": 4, "origin": "param",     "type": "float|null"},
    {"line": 8, "origin": "assign-op", "type": "float"}
  ]
}
```

The CLI internally re-runs `phpstan analyse` on the target file with a dump
env var set, parses its JSON output, and filters to the requested
`(file, line, variable)`. Drop `--json` for a human-readable chain.

### Claude Code skill

A ready-to-use [Claude Code](https://docs.claude.com/claude-code) skill ships
in `skills/phpstan-trace/`. It teaches the agent **when** to call the CLI
(on any PHPStan error involving a variable type) and **how** to interpret the
chain.

Install into your shell-wide skills directory:

```bash
cp -r skills/phpstan-trace ~/.claude/skills/
```

Or project-local:

```bash
mkdir -p .claude/skills
cp -r skills/phpstan-trace .claude/skills/
```

The agent will now invoke the trace automatically when it sees PHPStan
errors about types, narrowing, generics, or array shapes — fixing those
errors with the actual upstream type evidence instead of guesses.

## Signature

```php
function traceType(mixed $value, ?string $reason = null): void
```

- `$value` — a variable, property fetch (`$this->x`), or static property
  (`Foo::$bar`). For arbitrary expressions, only the snapshot type at the
  call site is printed.
- `$reason` — optional label shown in the chain header. String literal only;
  dynamic values are ignored.

At runtime `traceType()` is a no-op (autoloaded from `src/runtime.php`), so
a stray `traceType()` won't break production — it just does nothing until
the next PHPStan run.

## What gets captured

| Source                 | Origin label  | Example                  |
| ---------------------- | ------------- | ------------------------ |
| Function/method params | `param`       | `function f(int $x)`     |
| Closure / arrow-fn params | `param`    | `fn(int $x) => ...`      |
| Variable assignment    | `assign`      | `$x = 5;`                |
| Compound assignment    | `assign-op`   | `$x += 1; $x ??= 'def';` |
| Reference assignment   | `assign-ref`  | `$x = &$other;`          |
| Property fetch         | `read`        | `$this->foo`             |
| Static property fetch  | `read`        | `Foo::$bar`              |
| Variable read          | `read`        | bare `$x` usage          |

Property and static-property mutations are captured by the same
`assign` / `assign-op` collectors — `$obj->prop = 'x'` and `Foo::$bar += 1`
both appear in the chain.

Narrowing via `if`, `instanceof`, `=== null`, `is_*`, early-returns is free —
PHPStan's `Scope` is already narrowed by the time collectors run, so reads
reflect whatever narrowing was in effect.

## Known limitations

- Loops report the post-fixpoint type, not per-iteration deltas.
- Multiple closures inside the same enclosing function share one
  `functionKey` bucket. Currently this *happens to* render correctly because
  outer-scope captures join cleanly, but two same-named vars across sibling
  closures may collide. Closure-line disambiguation is on the roadmap.
- `traceType()` cannot follow values across function boundaries — it only
  sees the function where the call lives.
- Ref-aliases (`$alias = &$x; $alias[] = 'y';`) show only the snapshot at
  the call; the mutation through the alias isn't traced back to `$x`.

## How it works

Two-phase PHPStan pipeline:

1. **Collectors** (one per event kind) record every relevant AST event with
   `(file, functionKey, path, line, type, origin)`:
   - Param entry: `ParamInFunctionCollector`, `ParamInMethodCollector`,
     `ParamInClosureCollector`, `ParamInArrowFunctionCollector` — hooked on
     PHPStan's `In*Node` virtual nodes so scope is already inside the
     function when params are read.
   - Reads: `VarReadCollector`, `PropertyFetchCollector`,
     `StaticPropertyFetchCollector`.
   - Writes: `AssignCollector`, `AssignOpCollector` (covers all 13
     compound-op subclasses via `AssignOp` base + PHPStan registry's
     `class_parents` dispatch), `AssignRefCollector`.
   - Call sites: `TraceCallCollector`.
2. **`TraceReportRule`** runs once at the end on the virtual
   `CollectedDataNode`. For each `traceType()` call it joins the recorded
   events on `(functionKey, path)` filtered to lines `<=` the call line,
   sorts by line (mutations win on ties), collapses *only* boring repeated
   reads of the same type, and emits the delta chain as a PHPStan error.

## License

MIT
