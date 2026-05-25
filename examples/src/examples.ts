export interface Example {
  id: string;
  title: string;
  description: string;
  code: string;
  command: string;
  output: string;
}

const NOTE = `# captured from a real phpstan-trace run · src/non_trivial_bug.php and friends`;

export const EXAMPLES: Example[] = [
  {
    id: 'via-magic-prop',
    title: '01 · Magic property — via PropertiesClassReflectionExtension',
    description:
      "Eloquent-style. The model defines no real `virtual` property — a PHPStan extension does. The chain names which one.",
    command: "phpstan-trace inspect src/non_trivial_bug.php:26 'record->virtual'",
    code: `<?php

declare(strict_types=1);

namespace Demo;

use App\\Magic;
use Webmozart\\Assert\\Assert;

/**
 * Realistic report builder: pulls a magic-typed value, threads it through a
 * closure, normalizes via a helper, then formats. Mirrors a common pattern
 * in larastan codebases where Eloquent magic attributes flow through helpers.
 */
final class ReportBuilder
{
    /**
     * @param list<Magic> $records
     * @return list<string>
     */
    public function buildRows(array $records, ?string $currency, bool $strict): array
    {
        $rows = [];

        foreach ($records as $record) {
            $raw = $record->virtual;             // ← inspect this property

            if ($strict) {
                Assert::notNull($raw);
            }

            $normalized = $this->normalize($raw);

            $rows[] = array_map(function (string $segment) use ($normalized, $currency): string {
                $value = $this->decorate($segment, $normalized);

                if ($currency !== null && $currency !== '') {
                    $value = $currency . ' ' . $value;
                }

                return $this->finalize($value);
            }, $this->splitSegments($normalized));
        }

        return array_merge(...$rows);
    }

    private function normalize(mixed $raw): string
    {
        if ($raw === null) {
            return 'n/a';
        }

        return (string) $raw;
    }
}
`,
    output: `${NOTE}

$record->virtual · buildRows [src/non_trivial_bug.php] (up to L26)
────────────────────────────────────────────────────────────────────────────────
  L26  read  string|null  via MagicPropsExt
────────────────────────────────────────────────────────────────────────────────
1 event · final type: string|null

# Reading the source alone, you'd never know which extension owns the type.
# 'via MagicPropsExt' tells you exactly where to look to change it.
`,
  },
  {
    id: 'via-assert',
    title: '02 · Webmozart Assert — via type-specifying extension',
    description:
      'Assert::notNull narrows `?string` to `string`. The narrow event names the extension that did it.',
    command: 'phpstan-trace inspect src/demo.php:12 x',
    code: `<?php

declare(strict_types=1);

namespace Demo;

use Webmozart\\Assert\\Assert;

function viaIfStatic(?string $x): void
{
    if (Assert::notNull($x)) {
        \\traceType($x);                          // ← chain anchors here
    }
}
`,
    output: `${NOTE}

$x · Demo\\viaIfStatic [src/demo.php] (up to L12)
────────────────────────────────────────────────────────────────────────────────
  L9   param   string|null
  L12  narrow  Webmozart\\Assert\\Assert::notNull($x)  =>  string  via AssertTypeSpecifyingExtension
  L12  read    string
────────────────────────────────────────────────────────────────────────────────
3 events · final type: string

# 'via AssertTypeSpecifyingExtension' = phpstan-webmozart-assert did the narrow.
# Without this, "Assert::notNull then read $x" looks like magic. Now it's traceable.
`,
  },
  {
    id: 'closure-capture',
    title: '03 · Closure capture + helper roundtrip',
    description:
      'Variable enters at the outer function, gets captured by `use`, read inside the callback. Real multi-scope.',
    command: 'phpstan-trace inspect src/non_trivial_bug.php:37 currency',
    code: `<?php

declare(strict_types=1);

namespace Demo;

use App\\Magic;
use Webmozart\\Assert\\Assert;

/**
 * Realistic report builder: pulls a magic-typed value, threads it through a
 * closure, normalizes via a helper, then formats. Mirrors a common pattern
 * in larastan codebases where Eloquent magic attributes flow through helpers.
 */
final class ReportBuilder
{
    /**
     * @param list<Magic> $records
     * @return list<string>
     */
    public function buildRows(array $records, ?string $currency, bool $strict): array
    {
        $rows = [];

        foreach ($records as $record) {
            $raw = $record->virtual;

            if ($strict) {
                Assert::notNull($raw);
            }

            $normalized = $this->normalize($raw);

            $rows[] = array_map(function (string $segment) use ($normalized, $currency): string {
                $value = $this->decorate($segment, $normalized);

                if ($currency !== null && $currency !== '') {  // ← inspect $currency here (L37)
                    $value = $currency . ' ' . $value;
                }

                return $this->finalize($value);
            }, $this->splitSegments($normalized));
        }

        return array_merge(...$rows);
    }

    private function normalize(mixed $raw): string
    {
        if ($raw === null) {
            return 'n/a';
        }

        return (string) $raw;
    }
}
`,
    output: `${NOTE}

$currency · buildRows [src/non_trivial_bug.php] (up to L37)
────────────────────────────────────────────────────────────────────────────────
  L21  param  string|null
  L37  read   string
────────────────────────────────────────────────────────────────────────────────
2 events · final type: string

# $currency was string|null at the outer signature (L21). By the time it's
# read inside the closure (L37), PHPStan already narrowed it to string.
# The chain crosses scope boundaries — \`use ($currency)\` is just an edge to it.
`,
  },
  {
    id: 'invisible-narrowing',
    title: '04 · The narrowing you never wrote yourself',
    description:
      'PHPStan silently promoted `string` to `non-falsy-string` after one innocent concat. The chain surfaces the inference your eyes would skip.',
    command: 'phpstan-trace inspect src/non_trivial_bug.php:41 value',
    code: `<?php

declare(strict_types=1);

namespace Demo;

use App\\Magic;
use Webmozart\\Assert\\Assert;

/**
 * Realistic report builder: pulls a magic-typed value, threads it through a
 * closure, normalizes via a helper, then formats. Mirrors a common pattern
 * in larastan codebases where Eloquent magic attributes flow through helpers.
 */
final class ReportBuilder
{
    /**
     * @param list<Magic> $records
     * @return list<string>
     */
    public function buildRows(array $records, ?string $currency, bool $strict): array
    {
        $rows = [];

        foreach ($records as $record) {
            $raw = $record->virtual;

            if ($strict) {
                Assert::notNull($raw);
            }

            $normalized = $this->normalize($raw);

            $rows[] = array_map(function (string $segment) use ($normalized, $currency): string {
                $value = $this->decorate($segment, $normalized);

                if ($currency !== null && $currency !== '') {
                    $value = $currency . ' ' . $value;
                }

                return $this->finalize($value);                 // ← inspect $value here (L41)
            }, $this->splitSegments($normalized));
        }

        return array_merge(...$rows);
    }
}
`,
    output: `${NOTE}

$value · buildRows [src/non_trivial_bug.php] (up to L41)
────────────────────────────────────────────────────────────────────────────────
  L35  assign  string
  L38  assign  non-falsy-string
  L38  read    string
────────────────────────────────────────────────────────────────────────────────
3 events · final type: string

# L35: $value starts as plain 'string' (decorate() returns string).
# L38: after one concat with literal ' ' in the middle — PHPStan promoted it to
#      'non-falsy-string'. That literal space is enough to prove the result is
#      never empty. Source: zero ceremony. Chain: explicit evidence.
# L38: final read sees 'string' (because the if-branch is conditional — outside
#      the branch the type stays plain string, and the union collapses).
#
# Why this matters: when a downstream function expects 'non-empty-string', the
# branch that ran concat *would* satisfy it, the branch that didn't *would not*.
# The chain shows you the bifurcation — eyeballing the source hides it.
`,
  },
  {
    id: 'marker-mode',
    title: '05 · traceType() marker — chain shows up as a phpstan error',
    description:
      'No CLI needed. Drop a `traceType($var)` call anywhere in source, then run `phpstan analyse` like always.',
    command: 'phpstan analyse src/demo.php --no-progress',
    code: `<?php

declare(strict_types=1);

namespace Demo;

use Webmozart\\Assert\\Assert;

function viaIfStatic(?string $x): void
{
    if (Assert::notNull($x)) {
        \\traceType($x);                       // ← marker on L12
    }
}

function viaTernary(?string $x): string
{
    return Assert::notNull($x) ? \\traceType($x) ?? $x : 'd';
}

function viaProps(\\App\\Magic $m): void
{
    $v = $m->virtual;
    \\traceType($v);                           // ← marker on L24
}
`,
    output: `${NOTE}

 ------ -----------------------------------------------------------------------
  Line   demo.php
 ------ -----------------------------------------------------------------------
  12     Type chain for $x in Demo\\viaIfStatic
           L9     param      string|null
           L12    narrow     Webmozart\\Assert\\Assert::notNull($x)  =>  string
         via AssertTypeSpecifyingExtension
           L12    read       string
         🪪  typeTrace.chain (non-ignorable)

  24     Type chain for $v in Demo\\viaProps
           L23    assign     string|null
         🪪  typeTrace.chain (non-ignorable)
 ------ -----------------------------------------------------------------------

# traceType() is a runtime no-op. Leaving it in production = zero impact.
# It only emits during static analysis. Great for narrative-driven debugging:
# leave breadcrumbs through a confusing flow, run phpstan, read the chain.
`,
  },
];
