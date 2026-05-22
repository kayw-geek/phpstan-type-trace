<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\History;

final class ChainBuilder
{
    /**
     * Sort events for display and collapse only noise.
     *
     * Sort: line ascending; on tie, param < assign/assign-op/assign-ref/array-write
     * < narrow < read. Mutations win over the pre-mutation read at the same line;
     * a narrow guard slots between mutations and reads so the chain reads as
     * cause → effect.
     *
     * Dedup rule: only collapse a repeat read whose type matches the immediately
     * preceding entry. Mutation, param, and narrow events always survive — the
     * user is tracing *flow*, not just type-changes.
     *
     * @param list<array{line: int, type: string, origin: string, reason?: string}> $events
     * @return list<array{line: int, type: string, origin: string, reason?: string}>
     */
    public function build(array $events): array
    {
        $rank = static fn (string $o): int => match ($o) {
            'param' => 0,
            'assign', 'assign-op', 'assign-ref', 'array-write' => 1,
            'narrow' => 2,
            default => 3,
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
