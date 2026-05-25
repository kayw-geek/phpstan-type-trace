<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Rule;

use Kayw\PhpstanTypeTrace\Collector\ArrayWriteCollector;
use Kayw\PhpstanTypeTrace\Collector\AssignCollector;
use Kayw\PhpstanTypeTrace\Collector\AssignOpCollector;
use Kayw\PhpstanTypeTrace\Collector\AssignRefCollector;
use Kayw\PhpstanTypeTrace\Collector\NarrowingCollector;
use Kayw\PhpstanTypeTrace\Collector\ParamInArrowFunctionCollector;
use Kayw\PhpstanTypeTrace\Collector\ParamInClosureCollector;
use Kayw\PhpstanTypeTrace\Collector\ParamInFunctionCollector;
use Kayw\PhpstanTypeTrace\Collector\ParamInMethodCollector;
use Kayw\PhpstanTypeTrace\Collector\PropertyFetchCollector;
use Kayw\PhpstanTypeTrace\Collector\StaticPropertyFetchCollector;
use Kayw\PhpstanTypeTrace\Collector\TernaryNarrowingCollector;
use Kayw\PhpstanTypeTrace\Collector\TraceCallCollector;
use Kayw\PhpstanTypeTrace\Collector\VarReadCollector;
use Kayw\PhpstanTypeTrace\History\ChainBuilder;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<CollectedDataNode>
 */
final class TraceReportRule implements Rule
{
    /** @var list<class-string> */
    private const EVENT_COLLECTORS = [
        ParamInFunctionCollector::class,
        ParamInMethodCollector::class,
        ParamInClosureCollector::class,
        ParamInArrowFunctionCollector::class,
        VarReadCollector::class,
        PropertyFetchCollector::class,
        StaticPropertyFetchCollector::class,
        AssignCollector::class,
        AssignOpCollector::class,
        AssignRefCollector::class,
        ArrayWriteCollector::class,
        NarrowingCollector::class,
        TernaryNarrowingCollector::class,
    ];

    /** @var list<class-string> Collectors that emit a list of events per node visit. */
    private const LIST_EMITTING_COLLECTORS = [
        ParamInFunctionCollector::class,
        ParamInMethodCollector::class,
        ParamInClosureCollector::class,
        ParamInArrowFunctionCollector::class,
        NarrowingCollector::class,
        TernaryNarrowingCollector::class,
    ];

    public function __construct(private readonly ChainBuilder $chainBuilder) {}

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $eventsByFile = $this->collectEventsByFile($node);

        if (getenv('PHPSTAN_TYPE_TRACE_DUMP') === '1') {
            return $this->dumpAllChains($eventsByFile);
        }

        /** @var array<string, list<array{line:int,functionKey:string,functionLabel:string,path:?string,argType:string,reason:?string}>> $traces */
        $traces = $node->get(TraceCallCollector::class);

        $errors = [];
        foreach ($traces as $file => $fileTraces) {
            $fileEvents = $eventsByFile[$file] ?? [];
            foreach ($fileTraces as $trace) {
                $errors[] = $this->buildError($file, $trace, $fileEvents);
            }
        }
        return $errors;
    }

    /**
     * @return array<string, list<array{line:int,pos?:int,functionKey:string,path:string,type:string,origin:string,reason?:string,via?:list<string>}>>
     */
    private function collectEventsByFile(CollectedDataNode $node): array
    {
        $eventsByFile = [];
        foreach (self::EVENT_COLLECTORS as $collectorClass) {
            $collected = $node->get($collectorClass);
            $emitsLists = in_array($collectorClass, self::LIST_EMITTING_COLLECTORS, true);
            foreach ($collected as $file => $items) {
                foreach ($items as $item) {
                    if ($emitsLists) {
                        /** @var list<array{line:int,pos?:int,functionKey:string,path:string,type:string,origin:string,reason?:string,via?:list<string>}> $item */
                        foreach ($item as $event) {
                            $eventsByFile[$file][] = $event;
                        }
                    } else {
                        /** @var array{line:int,pos?:int,functionKey:string,path:string,type:string,origin:string,via?:list<string>} $item */
                        $eventsByFile[$file][] = $item;
                    }
                }
            }
        }
        return $eventsByFile;
    }

    /**
     * Dump every chain per (file, functionKey, path) as a JSON sentinel error.
     * Consumed by the phpstan-trace CLI, not meant for human reading.
     *
     * @param array<string, list<array{line:int,pos?:int,functionKey:string,path:string,type:string,origin:string,reason?:string,via?:list<string>}>> $eventsByFile
     * @return list<IdentifierRuleError>
     */
    private function dumpAllChains(array $eventsByFile): array
    {
        $errors = [];
        foreach ($eventsByFile as $file => $events) {
            $byKey = [];
            foreach ($events as $event) {
                $byKey[$event['functionKey'] . "\0" . $event['path']][] = $event;
            }
            foreach ($byKey as $events) {
                $chain = $this->chainBuilder->build($events);
                if ($chain === []) {
                    continue;
                }
                $first = $events[0];
                $last = $chain[count($chain) - 1];
                $eventLines = [];
                foreach ($events as $event) {
                    $eventLines[$event['line']] = true;
                }
                $eventLines = array_keys($eventLines);
                sort($eventLines);
                $payload = [
                    '_typetrace' => true,
                    'functionKey' => $first['functionKey'],
                    'path' => $first['path'],
                    'chain' => $chain,
                    'eventLines' => $eventLines,
                ];
                $errors[] = RuleErrorBuilder::message(
                    self::DUMP_SENTINEL . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                )
                    ->file($file)
                    ->line($last['line'])
                    ->identifier('typeTrace.dump')
                    ->nonIgnorable()
                    ->build();
            }
        }
        return $errors;
    }

    public const DUMP_SENTINEL = '__TYPETRACE_DUMP__';

    /**
     * @param array{line:int,functionKey:string,functionLabel:string,path:?string,argType:string,reason:?string} $trace
     * @param list<array{line:int,pos?:int,functionKey:string,path:string,type:string,origin:string,reason?:string,via?:list<string>}> $fileEvents
     */
    private function buildError(string $file, array $trace, array $fileEvents): IdentifierRuleError
    {
        return RuleErrorBuilder::message($this->renderMessage($trace, $fileEvents))
            ->file($file)
            ->line($trace['line'])
            ->identifier('typeTrace.chain')
            ->nonIgnorable()
            ->build();
    }

    /**
     * @param array{line:int,functionKey:string,functionLabel:string,path:?string,argType:string,reason:?string} $trace
     * @param list<array{line:int,pos?:int,functionKey:string,path:string,type:string,origin:string,reason?:string,via?:list<string>}> $fileEvents
     */
    private function renderMessage(array $trace, array $fileEvents): string
    {
        $header = $trace['path'] !== null
            ? sprintf('Type chain for %s in %s', $trace['path'], $trace['functionLabel'])
            : sprintf('Type chain (non-trackable expression) in %s', $trace['functionLabel']);

        if ($trace['reason'] !== null) {
            $header .= ' — ' . $trace['reason'];
        }

        if ($trace['path'] === null) {
            return $header . "\n  L" . $trace['line'] . '  ' . $trace['argType'];
        }

        $relevant = array_filter(
            $fileEvents,
            static fn (array $e): bool =>
                $e['functionKey'] === $trace['functionKey']
                && $e['path'] === $trace['path']
                && $e['line'] <= $trace['line']
        );

        $chain = $this->chainBuilder->build(array_values($relevant));

        if ($chain === []) {
            return $header . "\n  L" . $trace['line'] . '  ' . $trace['argType'] . '  (no prior history)';
        }

        $lines = [$header];
        foreach ($chain as $entry) {
            $viaSuffix = '';
            if (isset($entry['via']) && $entry['via'] !== []) {
                $viaSuffix = '  via ' . implode(', ', $entry['via']);
            }
            if ($entry['origin'] === 'narrow' && isset($entry['reason'])) {
                $lines[] = sprintf('  L%-5d %-10s %s  =>  %s%s', $entry['line'], 'narrow', $entry['reason'], $entry['type'], $viaSuffix);
                continue;
            }
            $suffix = isset($entry['reason']) ? '  (' . $entry['reason'] . ')' : '';
            $lines[] = sprintf('  L%-5d %-10s %s%s%s', $entry['line'], $entry['origin'], $entry['type'], $suffix, $viaSuffix);
        }
        return implode("\n", $lines);
    }
}
