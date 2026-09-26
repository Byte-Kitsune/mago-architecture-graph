<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

use ByteKitsune\MagoArchitectureGraph\GraphPolicy;
use ByteKitsune\MagoArchitectureGraph\Policy;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/** Deterministic shortest paths through literal static method calls. */
final class GraphHook implements AfterAnalysisHook
{
    public function __construct(private readonly Policy $policy) {}

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $graph = $this->policy->graph;
        if ($graph === null || !$graph->enabled) return;
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $files = [];
        foreach ($context->analysis->files as $file) {
            if (!str_ends_with($file->file, '.php')) continue;
            $path = $this->policy->relativePath($file->file);
            if ($path !== null && str_starts_with($path, $graph->sourceRoot . '/') && !$graph->excluded($path)) $files[$path] = $file;
        }
        ksort($files);
        $nodes = [];
        $indexed = [];
        $duplicates = [];
        $unresolved = [];
        $indexFile = function (string $path) use (&$nodes, &$indexed, &$duplicates, &$unresolved, $files, $context, $parser, $finder): void {
            if (isset($indexed[$path])) return;
            $indexed[$path] = true;
            $context->cancellation->throwIfCancelled();
            $source = $files[$path]->getSourceFile();
            try {
                $statements = $parser->parse($source->contents);
                $statements = (new NodeTraverser(new NameResolver()))->traverse($statements ?? []);
            } catch (\Throwable) {
                $unresolved[] = [$source->path, 0, 'PHP parse failed; graph coverage is incomplete'];
                return;
            }
            foreach ($finder->findInstanceOf($statements, Node\Stmt\Class_::class) as $class) {
                if (!$class->namespacedName instanceof Node\Name) continue;
                $className = $class->namespacedName->toString();
                foreach ($class->getMethods() as $method) {
                    $symbol = $className . '::' . $method->name->toString();
                    $key = strtolower($symbol);
                    if (isset($nodes[$key]) || isset($duplicates[$key])) {
                        $unresolved[] = [$source->path, $method->getStartFilePos(), "Duplicate method symbol {$symbol}"];
                        unset($nodes[$key]);
                        $duplicates[$key] = true;
                        continue;
                    }
                    $calls = [];
                    $methodUnresolved = [];
                    foreach ($finder->findInstanceOf($method->stmts ?? [], Node\Expr\StaticCall::class) as $call) {
                        if (!$call->class instanceof Node\Name || !$call->name instanceof Node\Identifier || in_array(strtolower($call->class->toString()), ['self', 'static', 'parent'], true)) {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Dynamic or relative static call in {$symbol}"];
                            continue;
                        }
                        $target = ($call->class->getAttribute('resolvedName') ?? $call->class)->toString() . '::' . $call->name->toString();
                        $calls[] = ['from' => $symbol, 'to' => $target, 'path' => $path, 'file' => $source->path, 'line' => $call->getStartLine(), 'column' => self::column($source->contents, $call->getStartFilePos()), 'position' => $call->getStartFilePos(), 'end' => $call->getEndFilePos() + 1, 'evidence' => 'explicit static call'];
                    }
                    $nodes[$key] = ['symbol' => $symbol, 'class' => $className, 'path' => $path, 'file' => $source->path, 'line' => $method->getStartLine(), 'position' => $method->getStartFilePos(), 'calls' => $calls, 'unresolved' => $methodUnresolved];
                }
            }
        };
        foreach (array_keys($files) as $path) if ($graph->possibleScopePath($path)) $indexFile($path);
        $starts = [];
        foreach ($nodes as $key => $node) {
            $scope = $graph->scopeFor($node['path'], $node['class']);
            if ($scope !== null) $starts[$key] = $scope['id'];
        }
        ksort($starts);
        $edges = [];
        $outgoing = function (string $key) use (&$edges, &$nodes, &$unresolved, $graph, $context, $files, $indexFile): array {
            if (isset($edges[$key])) return $edges[$key];
            foreach ($nodes[$key]['unresolved'] as $issue) $unresolved[] = $issue;
            $calls = array_values(array_filter($nodes[$key]['calls'], static fn ($call) => $graph->withinRoot(explode('::', $call['to'], 2)[0])));
            if ($calls === []) return $edges[$key] = [];
            $members = array_map(static function ($call) {
                [$class, $method] = explode('::', $call['to'], 2);
                return new \Mago\Sdk\Analyzer\Metadata\MemberIdentifier($class, $method);
            }, $calls);
            $metadata = $context->codebase->getMultipleMethods($members);
            $result = [];
            foreach ($calls as $index => $call) {
                $target = strtolower($call['to']);
                $location = $metadata[$index]?->location->file ?? null;
                $path = is_string($location) ? $this->policy->relativePath($location) : null;
                if ($path === null || !isset($files[$path])) {
                    $unresolved[] = [$call['file'], $call['position'], 'Static target absent from the complete Mago source set: ' . $call['to']];
                    continue;
                }
                $indexFile($path);
                if (!isset($nodes[$target])) {
                    $unresolved[] = [$call['file'], $call['position'], 'Static target declaration was not uniquely indexed: ' . $call['to']];
                    continue;
                }
                $result[] = $call;
            }
            usort($result, static fn ($a, $b) => [$a['to'], $a['path'], $a['line'], $a['column']] <=> [$b['to'], $b['path'], $b['line'], $b['column']]);
            return $edges[$key] = $result;
        };
        $proofs = [];
        $depthWarnings = [];
        foreach ($starts as $startKey => $scopeId) {
            $start = $nodes[$startKey];
            $queue = [[$startKey, []]];
            $seen = [$startKey => true];
            for ($index = 0; $index < count($queue); $index++) {
                $context->cancellation->throwIfCancelled();
                [$current, $proof] = $queue[$index];
                if ($proof !== []) {
                    $permission = $graph->permission($scopeId, $nodes[$current]['symbol']);
                    if ($permission !== null) {
                        $proofs[] = [$scopeId, $permission, $proof, $nodes[$current]];
                        if ($permission['decision'] === 'deny') continue;
                    }
                }
                $limit = $graph->mode === 'direct' ? 1 : $graph->maxDepth;
                if (count($proof) >= $limit) {
                    if ($graph->mode === 'transitive') foreach ($nodes[$current]['calls'] as $call) {
                        if ($graph->withinRoot(explode('::', $call['to'], 2)[0]) && !isset($seen[strtolower($call['to'])])) {
                            $depthWarnings[$scopeId . ':' . $startKey] = [$start['file'], $start['position'], 'Configured graph depth was reached before traversal completed'];
                            break;
                        }
                    }
                    continue;
                }
                foreach ($outgoing($current) as $edge) {
                    $target = strtolower($edge['to']);
                    if (isset($seen[$target])) continue;
                    $seen[$target] = true;
                    $queue[] = [$target, [...$proof, $edge]];
                }
            }
        }
        foreach ([...$unresolved, ...array_values($depthWarnings)] as [$file, $position, $reason]) $this->report($context, Level::Error, 'scope-graph-incomplete', 'Static-call graph coverage is incomplete: ' . $reason, $file, $position, $position + 1);
        $complete = $unresolved === [] && $depthWarnings === [];
        foreach ($proofs as [$scope, $permission, $proof, $target]) $this->reportProof($context, $scope, $permission, $proof, $target, $complete);
    }

    /** @param array<string, mixed> $permission @param list<array<string, mixed>> $proof @param array<string, mixed> $target */
    private function reportProof(AfterAnalysisContext $context, string $scope, array $permission, array $proof, array $target, bool $complete): void
    {
        $edges = array_map(static fn ($edge) => array_intersect_key($edge, array_flip(['from', 'to', 'path', 'line', 'column', 'evidence'])), $proof);
        $evidence = ['schema_version' => '1', 'scope' => $scope, 'target' => $target['symbol'], 'target_path' => $target['path'], 'target_line' => $target['line'], 'policy_id' => $permission['policy_id'], 'complete' => $complete, 'edges' => $edges];
        $note = 'graph-evidence: ' . json_encode($evidence, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($note) > 4096) {
            $first = $proof[0];
            $this->report($context, Level::Error, 'scope-graph-incomplete', 'Structured graph proof exceeds 4096 bytes', $first['file'], $first['position'], $first['end']);
            return;
        }
        $first = $proof[0];
        $decision = $permission['decision'];
        $message = sprintf('Scope %s reaches %s %s. Policy %s: %s.', $scope, $decision === 'deny' ? 'prohibited' : 'allowed', $target['symbol'], $permission['policy_id'], $permission['rationale']);
        if ($decision === 'deny') {
            $issue = Issue::at($message, new SourceLocation($first['file'], new Span($first['position'], $first['end'])))->withNote($note);
            $context->report(Level::Error, 'scope-forbidden-entrypoint-method', $issue);
            return;
        }
        $participants = [];
        foreach ($proof as $edge) $participants[$edge['path'] . ':' . $edge['line']] = [$edge['file'], $edge['position'], $edge['end']];
        $participants[$target['path'] . ':' . $target['line']] = [$target['file'], $target['position'], $target['position'] + 1];
        ksort($participants);
        $total = count($participants);
        $index = 0;
        foreach ($participants as [$file, $start, $end]) {
            $issue = Issue::at($message . sprintf(' Verified graph participant %d/%d.', ++$index, $total), new SourceLocation($file, new Span($start, $end)))->withNote($note);
            $context->report(Level::Note, 'scope-allowed-entrypoint-method', $issue);
        }
    }

    private function report(AfterAnalysisContext $context, Level $level, string $code, string $message, string $file, int $start, int $end): void
    {
        $context->report($level, $code, Issue::at($message, new SourceLocation($file, new Span($start, $end))));
    }

    private static function column(string $source, int $position): int
    {
        $lineStart = strrpos(substr($source, 0, $position), "\n");
        return $position - ($lineStart === false ? 0 : $lineStart + 1);
    }
}
