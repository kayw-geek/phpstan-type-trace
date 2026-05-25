<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\History;

final class ChainBuilder
{
    /**
     * Sort events for display and collapse only noise.
     *
     * Sort: line ascending; on tie, param < narrow < read < assign/assign-op/assign-ref/array-write.
     * Narrow events are anchored to the if-body's first line and represent what's
     * true on entry to that body. Within a statement like `$x = $x->foo()`, the
     * RHS is evaluated (read) before the LHS commits (assign), so reads precede
     * mutations at the same line — the chain reads as cause → effect.
     *
     * Dedup rule: collapse a repeat read whose type matches the immediately
     * preceding entry, EXCEPT when the previous entry is a narrow — narrow is
     * evidence ("PHPStan now knows X here"), read is actual usage ("your code
     * accesses it here"). They convey different things and both stay. Mutation,
     * param, and narrow events always survive — the user is tracing *flow*,
     * not just type-changes.
     *
     * @param list<array{line: int, type: string, origin: string, reason?: string}> $events
     * @return list<array{line: int, type: string, origin: string, reason?: string}>
     */
    public function build(array $events): array
    {
        $rank = static fn (string $o): int => match ($o) {
            'param' => 0,
            'narrow' => 1,
            'assign', 'assign-op', 'assign-ref', 'array-write' => 3,
            default => 2,
        };

        usort($events, static function (array $a, array $b) use ($rank): int {
            if ($a['line'] !== $b['line']) {
                return $a['line'] <=> $b['line'];
            }
            return $rank($a['origin']) <=> $rank($b['origin']);
        });

        $chain = [];
        $prev = null;
        foreach ($events as $event) {
            if (
                $event['origin'] === 'read'
                && $prev !== null
                && $prev['origin'] !== 'narrow'
                && $event['type'] === $prev['type']
            ) {
                continue;
            }
            $chain[] = $event;
            $prev = $event;
        }

        return $chain;
    }
}
