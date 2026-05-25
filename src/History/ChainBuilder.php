<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\History;

final class ChainBuilder
{
    /**
     * Sort events for display and collapse only noise.
     *
     * Sort: line ascending, then source file position ascending, then rank
     * (param < narrow < read < assign/assign-op/assign-ref/array-write). The
     * position tiebreak is what makes inline ternaries readable: the cond
     * read of `$x` precedes the narrow event (anchored at the if-branch's
     * file pos), which precedes the then-branch read of `$x`. Without it,
     * rank alone would put narrow first and a `string|false` read would
     * appear *after* a narrow that just claimed `string` — confusing.
     *
     * Dedup rule: collapse a repeat read whose type matches the immediately
     * preceding entry, EXCEPT when the previous entry is a narrow — narrow is
     * evidence ("PHPStan now knows X here"), read is actual usage ("your code
     * accesses it here"). They convey different things and both stay. Mutation,
     * param, and narrow events always survive — the user is tracing *flow*,
     * not just type-changes.
     *
     * @param list<array{line: int, pos?: int, type: string, origin: string, reason?: string}> $events
     * @return list<array{line: int, pos?: int, type: string, origin: string, reason?: string}>
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
            $posA = $a['pos'] ?? PHP_INT_MAX;
            $posB = $b['pos'] ?? PHP_INT_MAX;
            if ($posA !== $posB) {
                return $posA <=> $posB;
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
