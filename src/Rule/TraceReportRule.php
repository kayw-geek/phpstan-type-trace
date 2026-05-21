<?php

declare(strict_types=1);

namespace Kayw\PhpstanTypeTrace\Rule;

use Kayw\PhpstanTypeTrace\Collector\AssignCollector;
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
    public function __construct(private readonly ChainBuilder $chainBuilder) {}

    public function getNodeType(): string
    {
        return CollectedDataNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        /** @var array<string, list<array{line:int,functionKey:string,functionLabel:string,varName:?string,argType:string,reason:?string}>> $traces */
        $traces = $node->get(TraceCallCollector::class);
        /** @var array<string, list<array{line:int,functionKey:string,varName:string,type:string,origin:string}>> $reads */
        $reads = $node->get(VarReadCollector::class);
        /** @var array<string, list<array{line:int,functionKey:string,varName:string,type:string,origin:string}>> $assigns */
        $assigns = $node->get(AssignCollector::class);

        $errors = [];
        foreach ($traces as $file => $fileTraces) {
            $fileEvents = array_merge($reads[$file] ?? [], $assigns[$file] ?? []);
            foreach ($fileTraces as $trace) {
                $errors[] = $this->buildError($file, $trace, $fileEvents);
            }
        }
        return $errors;
    }

    /**
     * @param array{line:int,functionKey:string,functionLabel:string,varName:?string,argType:string,reason:?string} $trace
     * @param list<array{line:int,functionKey:string,varName:string,type:string,origin:string}> $fileEvents
     */
    private function buildError(string $file, array $trace, array $fileEvents): IdentifierRuleError
    {
        $message = $this->renderMessage($trace, $fileEvents);

        return RuleErrorBuilder::message($message)
            ->file($file)
            ->line($trace['line'])
            ->identifier('typeTrace.chain')
            ->nonIgnorable()
            ->build();
    }

    /**
     * @param array{line:int,functionKey:string,functionLabel:string,varName:?string,argType:string,reason:?string} $trace
     * @param list<array{line:int,functionKey:string,varName:string,type:string,origin:string}> $fileEvents
     */
    private function renderMessage(array $trace, array $fileEvents): string
    {
        $header = $trace['varName'] !== null
            ? sprintf('Type chain for $%s in %s', $trace['varName'], $trace['functionLabel'])
            : sprintf('Type chain (non-variable expression) in %s', $trace['functionLabel']);

        if ($trace['reason'] !== null) {
            $header .= ' — ' . $trace['reason'];
        }

        // For non-variable expressions we can't follow assignment history; show snapshot.
        if ($trace['varName'] === null) {
            return $header . "\n  L" . $trace['line'] . '  ' . $trace['argType'];
        }

        $relevant = array_filter(
            $fileEvents,
            static fn (array $e): bool =>
                $e['functionKey'] === $trace['functionKey']
                && $e['varName'] === $trace['varName']
                && $e['line'] <= $trace['line']
        );

        $chain = $this->chainBuilder->build(array_values($relevant));

        if ($chain === []) {
            return $header . "\n  L" . $trace['line'] . '  ' . $trace['argType'] . '  (no prior history)';
        }

        $lines = [$header];
        foreach ($chain as $entry) {
            $lines[] = sprintf('  L%-5d %-8s %s', $entry['line'], $entry['origin'], $entry['type']);
        }
        return implode("\n", $lines);
    }
}
