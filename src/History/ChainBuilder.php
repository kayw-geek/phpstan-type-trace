<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\History;

final class ChainBuilder
{
    /**
     * Collapses consecutive same-type events into one chain entry.
     *
     * Sort key: line ascending; on tie, assigns come before reads at the same
     * line (so "$x = 5;" reads in subsequent statements show the post-assign type).
     *
     * @param list<array{line: int, type: string, origin: string}> $events
     * @return list<array{line: int, type: string, origin: string}>
     */
    public function build(array $events): array
    {
        usort($events, static function (array $a, array $b): int {
            if ($a['line'] !== $b['line']) {
                return $a['line'] <=> $b['line'];
            }
            $rank = static fn (string $o): int => $o === 'assign' ? 0 : 1;
            return $rank($a['origin']) <=> $rank($b['origin']);
        });

        $chain = [];
        $prevType = null;
        foreach ($events as $event) {
            if ($event['type'] === $prevType) {
                continue;
            }
            $chain[] = $event;
            $prevType = $event['type'];
        }
        return $chain;
    }
}
