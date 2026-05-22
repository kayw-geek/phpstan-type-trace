# phpstan-type-trace

> Trace any variable's type history through your code — no more guessing why PHPStan thinks it's `mixed`.

![Hero](docs/hero.png)

Above: the type evolution of `$modelType` in real larastan code. Five events, one command, zero source edits.

## Install

```bash
composer require --dev kayw-geek/phpstan-type-trace
```

Auto-registered via [phpstan-extension-installer](https://github.com/phpstan/extension-installer). Otherwise add to `phpstan.neon`:

```neon
includes:
    - vendor/kayw-geek/phpstan-type-trace/extension.neon
```

## Two ways to use it

### 1. CLI — inspect any line, no source edits

```bash
./vendor/bin/phpstan-trace inspect src/Foo.php:42 myVar
```

Output:

```
$myVar · doStuff [src/Foo.php] (up to L42)

  L18  param    int|null
  L25  assign   int
  L31  read     positive-int
  L42  read     positive-int

4 events · final type: positive-int
```

Variable name is optional — if only one variable has events at the target line, it's auto-picked. Otherwise the candidates are listed.

Pass `--json` for machine-readable output (handy for tooling).

### 2. `traceType()` — drop in a marker

```php
function compute(?float $discount = null): float
{
    $discount ??= 0.1;
    traceType($discount, 'after ??=');  // prints chain on next phpstan run
    return 1 - $discount;
}
```

`traceType()` is a runtime no-op (autoloaded), so leaving it in won't break production.

## Use it with Claude Code

When Claude Code (or any LLM agent) is chasing PHPStan errors, it usually guesses at types. With this extension installed as a [Claude Code plugin](https://docs.claude.com/claude-code), Claude invokes the trace automatically — fixes are grounded in real upstream type evidence, not pattern-matching.

```
/plugin marketplace add kayw-geek/phpstan-type-trace
/plugin install phpstan-type-trace@kayw-geek
```

Installed into `~/.claude/plugins/cache/`, auto-discovered across every project. Updates: `/plugin marketplace update kayw-geek` then reinstall.

## Signature

```php
function traceType(mixed $value, ?string $reason = null): void
```

- `$value` — a variable, property fetch (`$this->x`), or static property (`Foo::$bar`). For arbitrary expressions, only the snapshot type is printed.
- `$reason` — optional label shown in the chain header. String literal only.

## What gets captured

| Source                    | Origin label  | Example                  |
| ------------------------- | ------------- | ------------------------ |
| Function/method params    | `param`       | `function f(int $x)`     |
| Closure / arrow-fn params | `param`       | `fn(int $x) => ...`      |
| Variable assignment       | `assign`      | `$x = 5;`                |
| Compound assignment       | `assign-op`   | `$x += 1; $x ??= 'def';` |
| Reference assignment      | `assign-ref`  | `$x = &$other;`          |
| Property fetch            | `read`        | `$this->foo`             |
| Static property fetch     | `read`        | `Foo::$bar`              |
| Variable read             | `read`        | bare `$x` usage          |

Narrowing via `if`, `instanceof`, `=== null`, `is_*`, early-returns is free — PHPStan's `Scope` is already narrowed by the time collectors run.

## Limitations

- Loops report the post-fixpoint type, not per-iteration deltas.
- Multiple closures inside the same enclosing function share one bucket. Same-named vars across sibling closures may collide.
- Cannot follow values across function boundaries.
- Ref-aliases (`$alias = &$x; $alias[] = 'y';`) show only the snapshot at the call.

<details>
<summary><strong>How it works</strong></summary>

Two-phase PHPStan pipeline:

1. **Collectors** (one per event kind) record every relevant AST event with `(file, functionKey, path, line, type, origin)`:
   - Param entry: `ParamInFunctionCollector`, `ParamInMethodCollector`, `ParamInClosureCollector`, `ParamInArrowFunctionCollector` — hooked on PHPStan's `In*Node` virtual nodes so scope is already inside the function when params are read.
   - Reads: `VarReadCollector`, `PropertyFetchCollector`, `StaticPropertyFetchCollector`.
   - Writes: `AssignCollector`, `AssignOpCollector` (covers all 13 compound-op subclasses), `AssignRefCollector`.
   - Call sites: `TraceCallCollector`.
2. **`TraceReportRule`** runs once at the end on the virtual `CollectedDataNode`. For each `traceType()` call it joins the recorded events on `(functionKey, path)` filtered to lines `<=` the call line, sorts by line (mutations win on ties), collapses *only* boring repeated reads of the same type, and emits the delta chain as a PHPStan error.

The CLI runs the same pipeline with a dump env var set, captures every chain as a JSON sentinel error, then filters to the `(file, line, variable)` you asked about.

</details>

## License

MIT
