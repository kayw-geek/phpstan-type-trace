# `phpstan-trace inspect --json` — JSON API

Stable, versioned JSON contract for tooling (IDE plugins, AI agents, CI).

```bash
phpstan-trace inspect <file>:<line> [<var>] --json [--api-version=N]
```

- `--api-version=N` pins the output schema. Omitted ⇒ latest. Unknown version ⇒ exit 1.
- Exit code is `0` for any well-formed JSON response (success or miss). `1` is reserved for hard failures (bad args, phpstan not found, JSON parse failure). Consumers should not branch on exit code alone — inspect `found` and `reason`.

## Versioning policy

- **Additive changes** (new optional fields) ship under the current `apiVersion`.
- **Breaking changes** (renamed/removed fields, changed types) ship under a new `apiVersion`. Previous version stays supported for at least one minor release.
- All responses carry `apiVersion: <int>` as the first field so consumers can dispatch.

Current versions: `1` (latest).

## Response shapes (v1)

Three mutually exclusive shapes, distinguished by `found` and (when `found=false`) `reason`.

### 1. Success — chain found

```json
{
  "apiVersion": 1,
  "found": true,
  "file": "/abs/path/to/src/User.php",
  "line": 42,
  "path": "$user",
  "functionKey": "/abs/path/to/src/User.php::App\\User::profile",
  "chain": [
    { "line": 10, "origin": "param",     "type": "App\\User|null" },
    { "line": 15, "origin": "narrow",    "type": "App\\User", "reason": "$user !== null" },
    { "line": 16, "origin": "read",      "type": "App\\User", "via": ["NewModelQueryDynamicMethodReturnTypeExtension"] }
  ]
}
```

| Field         | Type                                | Notes |
|---------------|-------------------------------------|-------|
| `apiVersion`  | `int`                               | Always `1` in v1. |
| `found`       | `true`                              | Discriminator. |
| `file`        | `string` (abs path)                 | Resolved absolute path. |
| `line`        | `int`                               | Queried line. |
| `path`        | `string`                            | Normalized variable path (e.g. `$user`, `$this->foo`, `Foo::$bar`). |
| `functionKey` | `string`                            | `{absFile}::{fqn}`. `__top__` for file scope. |
| `chain`       | `list<ChainEvent>`                  | Ordered events, truncated to `line <= <line>`. May be empty only if a logic bug — successful responses generally have ≥1 event. |

#### `ChainEvent`

| Field    | Type                  | Required | Notes |
|----------|-----------------------|----------|-------|
| `line`   | `int`                 | yes      | Source line of the event. |
| `origin` | `string` (enum below) | yes      | What kind of event. |
| `type`   | `string`              | yes      | PHPStan-inferred type at this point. |
| `reason` | `string`              | no       | Human-readable predicate for `narrow` (e.g. `$x !== null`, `is_string($x)`), or compound-op kind for `assign-op`. |
| `via`    | `list<string>`        | no       | Third-party PHPStan extensions (short class names) that shaped this type. Absent if none attributed. |

`origin` is one of:

| Value         | Meaning                                    |
|---------------|--------------------------------------------|
| `param`       | Function / method / closure / arrow-fn parameter entry. |
| `assign`      | `$x = …` |
| `assign-op`   | `$x += …`, `$x ??= …`, etc. |
| `assign-ref`  | `$x = &$other` |
| `array-write` | `$x[] = …`, `$x['k'] = …` |
| `narrow`      | Type narrowed by `if` / ternary guard. Carries `reason`. |
| `read`        | Bare read / property fetch / static prop fetch. |

### 2. Miss — variable specified but no chain found

```json
{
  "apiVersion": 1,
  "found": false,
  "file": "/abs/path/to/src/User.php",
  "line": 42,
  "path": "$user",
  "reason": "path_not_tracked",
  "message": "No events recorded for $user in this file. The variable may be array-dim only ($x[] = ...), dynamic, or defined after the queried line.",
  "availablePaths": ["$this", "$id"]
}
```

| Field            | Type             | Notes |
|------------------|------------------|-------|
| `apiVersion`     | `int`            | |
| `found`          | `false`          | |
| `file`           | `string`         | |
| `line`           | `int`            | |
| `path`           | `string`         | The path that was queried. |
| `reason`         | `string` (enum)  | Machine-readable miss code. |
| `message`        | `string`         | Human-readable diagnostic. Do not parse. |
| `availablePaths` | `list<string>`   | Variable paths the extension *did* track in this file. May be empty. |

`reason` enum (when `path` is present):

| Value                    | Meaning                                    |
|--------------------------|--------------------------------------------|
| `extension_not_loaded`   | phpstan emitted zero trace events. Likely the extension isn't registered. |
| `file_path_mismatch`     | phpstan returned chains for other files only — argument resolution issue. |
| `path_not_tracked`       | File was scanned, but no events match the queried variable path. |

### 3. Ambiguous — no `<var>` supplied, line has 0 or >1 candidates

```json
{
  "apiVersion": 1,
  "found": false,
  "file": "/abs/path/to/src/User.php",
  "line": 42,
  "reason": "ambiguous",
  "message": "Multiple variables are tracked at this line; specify <var>.",
  "candidates": ["$user", "$id"]
}
```

| Field        | Type            | Notes |
|--------------|-----------------|-------|
| `apiVersion` | `int`           | |
| `found`      | `false`         | |
| `file`       | `string`        | |
| `line`       | `int`           | |
| `reason`     | `string` (enum) | `ambiguous` or `no_var_at_line`. |
| `message`    | `string`        | Human-readable. |
| `candidates` | `list<string>`  | Variable paths tracked at this line. Empty when `reason=no_var_at_line`. |

Note: this shape has **no `path` field** (the user didn't supply one). Consumers should branch on `path` presence or on the `reason` value.

## Consumer guidance

```ts
type Response = Success | Miss | Ambiguous;

function dispatch(r: Response) {
  if (r.apiVersion !== 1) throw new Error(`Unsupported apiVersion ${r.apiVersion}`);
  if (r.found) return renderChain(r);
  if (r.reason === "ambiguous" || r.reason === "no_var_at_line") return promptPick(r.candidates);
  return renderMiss(r); // path_not_tracked, file_path_mismatch, extension_not_loaded
}
```

### Forward compatibility

- New optional fields may appear on existing shapes under the same `apiVersion`. Ignore unknown fields.
- New `origin` values may appear in `chain[].origin`. Treat unknown origins as opaque events.
- New `reason` values may appear in miss responses. Default to a generic "no chain" branch.
- `apiVersion` will only increment on **breaking** changes.

## Stability scope

What's covered by the version contract:

- Top-level field names and types on each response shape.
- `chain[].origin` enum values listed above.
- `reason` enum values listed above.

What's **not** covered (may change without bumping `apiVersion`):

- `message` wording.
- `functionKey` exact format (treat as opaque identifier).
- PHPStan-inferred `type` string formatting (mirrors PHPStan internals).
- `via` short-name list contents (depends on installed extensions).
