# phpstan-type-trace

> See the full inference chain of any value, not just a single snapshot.

PHPStan's built-in `\PHPStan\dumpType($x)` prints the type at one line. When
PHPStan says *"expected `int`, got `int|null`"* and the actual assignment
happened 200 lines earlier, that single snapshot is not enough.

This extension adds a `traceType()` marker that prints the **delta chain** of
how a variable's type evolved up to the call site.

## Install

```bash
composer require --dev kayw/phpstan-type-trace
```

The extension is auto-registered if you have
[phpstan-extension-installer](https://github.com/phpstan/extension-installer).
Otherwise add to your `phpstan.neon`:

```neon
includes:
    - vendor/kayw/phpstan-type-trace/extension.neon
```

## Use

```php
function lookup(?string $name): string
{
    $name ??= 'guest';
    if ($name === '') {
        $name = $_GET['fallback'] ?? null;
    }
    traceType($name, 'before strtolower');
    return strtolower($name);
}
```

Run `vendor/bin/phpstan analyse` and you'll get:

```
 ------ ------------------------------------------------------------ 
  Line   src/Foo.php                                                 
 ------ ------------------------------------------------------------ 
  8      Type chain for $name in App\Foo::lookup — before strtolower
         L3     read     string|null
         L4     assign   string|'guest'
         L5     read     string
         L6     assign   string|null
         L8     read     string|null
 ------ ------------------------------------------------------------ 
```

Every type transition is on its own line — you immediately see where the
`null` snuck back in.

## Signature

```php
function traceType(mixed $value, ?string $reason = null): void
```

- `$value` — usually a variable. If you pass an expression (like
  `traceType($a + $b)`), the extension prints only the snapshot type at the
  call site.
- `$reason` — optional label shown in the chain header. Pass a string literal;
  dynamic values are ignored.

At runtime `traceType()` is a no-op (autoloaded from `src/runtime.php`), so
you can safely commit a `traceType()` call temporarily without breaking the
app — it just won't do anything until the next PHPStan run.

## What's in scope (MVP)

- Local variables in functions, methods, closures.
- Type narrowing via `if`, `instanceof`, `=== null`, `is_*`, early returns.
- Reassignments (`=`, `??=` — coalesce assign captured via the prior read +
  the subsequent read).

## Known limitations

- `$this->foo` and other property/static-property traces are **not** yet
  supported. Roadmap: dedicated `PropertyFetchCollector`.
- Loops report the post-fixpoint type, not per-iteration deltas.
- Multiple closures inside the same enclosing function share one bucket and
  may collide in the chain; disambiguation by closure source line is on the
  roadmap.
- `traceType()` cannot follow values across function boundaries — it only
  sees the function where the call lives.

## How it works

Two-phase PHPStan pipeline:

1. **Collectors** (`TraceCallCollector`, `VarReadCollector`,
   `AssignCollector`) record every variable read, every assignment, and every
   `traceType()` call site as PHPStan walks the AST.
2. **`TraceReportRule`** runs once at the end on the virtual
   `CollectedDataNode`. For each `traceType()` call it joins the recorded
   events on `(file, function-scope, variable-name)` filtered to lines `<=`
   the call line, sorts by line, collapses adjacent same-type entries, and
   emits the delta chain as a PHPStan error.

Branching (`if`/`else`) and narrowing fall out for free: when
`NodeScopeResolver` enters the true branch, the `Scope` it passes to
collectors is already narrowed, so the recorded type at each read reflects
whatever narrowing was in effect.

## License

MIT
