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

/** Deterministic shortest paths through literal calls and proven service bindings. */
final class GraphHook implements AfterAnalysisHook
{
    /** @param array<string, string> $classBindings */
    public function __construct(private readonly Policy $policy, private readonly array $classBindings = [], private readonly bool $serviceConfigurationComplete = true) {}

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
        $aliasesByClass = [];
        $indexFile = function (string $path) use (&$nodes, &$indexed, &$duplicates, &$unresolved, &$aliasesByClass, $files, $context, $parser, $finder): void {
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
                $aliasesByClass[strtolower($className)] = self::asAlias($class);
                $properties = $this->injectedProperties($class, $finder);
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
                    [$staticCalls, $instanceCalls, $indirect] = CallCollector::collect($method->stmts ?? []);
                    foreach ($indirect as $call) $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Nested or indirect dispatch in {$symbol} is not modeled"];
                    foreach ($staticCalls as $call) {
                        if (!$call->class instanceof Node\Name || !$call->name instanceof Node\Identifier || in_array(strtolower($call->class->toString()), ['static', 'parent'], true)) {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Dynamic or relative static call in {$symbol}"];
                            continue;
                        }
                        $targetClass = strtolower($call->class->toString()) === 'self' ? $className : ($call->class->getAttribute('resolvedName') ?? $call->class)->toString();
                        $calls[] = self::edge($symbol, $targetClass . '::' . $call->name->toString(), $path, $source->path, $source->contents, $call, 'explicit static call');
                    }
                    foreach ($instanceCalls as $call) {
                        if (!$call->name instanceof Node\Identifier) {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Dynamic instance method call in {$symbol}"];
                            continue;
                        }
                        $receiver = $call->var;
                        if ($receiver instanceof Node\Expr\Variable && $receiver->name === 'this' && $class->isFinal()) {
                            $targetClass = $className;
                            $evidence = 'final-class this call';
                        } elseif ($receiver instanceof Node\Expr\PropertyFetch && $receiver->var instanceof Node\Expr\Variable && $receiver->var->name === 'this' && $receiver->name instanceof Node\Identifier && isset($properties[$receiver->name->toString()])) {
                            [$targetClass, $binding] = $properties[$receiver->name->toString()];
                            $evidence = $binding;
                        } else {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Unresolved instance receiver in {$symbol}"];
                            continue;
                        }
                        $calls[] = self::edge($symbol, $targetClass . '::' . $call->name->toString(), $path, $source->path, $source->contents, $call, $evidence);
                    }
                    $nodes[$key] = ['symbol' => $symbol, 'class' => $className, 'path' => $path, 'file' => $source->path, 'line' => $method->getStartLine(), 'position' => $method->getStartFilePos(), 'calls' => $calls, 'unresolved' => $methodUnresolved, 'bounded_recursion' => RecursionProof::bounded($class, $method, $className)];
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
        if (!$this->serviceConfigurationComplete && $starts !== []) {
            $first = $nodes[array_key_first($starts)];
            $unresolved[] = [$first['file'], $first['position'], 'Trusted Symfony service configuration is incomplete'];
        }
        $edges = [];
        $resolvedAliases = [];
        $resolveAlias = function (string $interface) use (&$resolvedAliases, &$aliasesByClass, $context, $files, $indexFile): ?string {
            $key = strtolower($interface);
            if (array_key_exists($key, $resolvedAliases)) return $resolvedAliases[$key];
            $metadata = $context->codebase->getInterface($interface);
            if ($metadata === null || $metadata->children === null || count($metadata->children) > 256) return $resolvedAliases[$key] = null;
            $children = $context->codebase->getMultipleClasses($metadata->children);
            $matches = [];
            $invalid = false;
            foreach ($children as $child) {
                if ($child === null) continue;
                $path = $this->policy->relativePath($child->location->file);
                if ($path === null || !isset($files[$path])) continue;
                $indexFile($path);
                $alias = $aliasesByClass[strtolower($child->name)] ?? null;
                if ($alias === false) $invalid = true;
                elseif (is_string($alias) && strcasecmp($alias, $interface) === 0) $matches[] = $child->originalName;
            }
            return $resolvedAliases[$key] = !$invalid && count($matches) === 1 ? $matches[0] : null;
        };
        $outgoing = function (string $key) use (&$edges, &$nodes, &$unresolved, $graph, $context, $files, $indexFile, $resolveAlias): array {
            if (isset($edges[$key])) return $edges[$key];
            foreach ($nodes[$key]['unresolved'] as $issue) $unresolved[] = $issue;
            $calls = array_values(array_filter($nodes[$key]['calls'], static fn ($call) => $graph->withinRoot(explode('::', $call['to'], 2)[0])));
            foreach ($calls as $index => $call) {
                if (!str_starts_with($call['evidence'], 'declared promoted property ')) continue;
                [$interface, $method] = explode('::', $call['to'], 2);
                if ($context->codebase->getInterface($interface) === null) continue;
                $concrete = $resolveAlias($interface);
                if ($concrete === null) {
                    $unresolved[] = [$call['file'], $call['position'], 'Symfony AsAlias is absent, ambiguous, or unsupported for ' . $interface];
                    unset($calls[$index]);
                    continue;
                }
                $calls[$index]['to'] = $concrete . '::' . $method;
                $calls[$index]['evidence'] = "Symfony AsAlias {$interface} -> {$concrete}";
            }
            $calls = array_values($calls);
            $nodes[$key]['calls'] = $calls;
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
                    $unresolved[] = [$call['file'], $call['position'], 'Call target absent from the complete Mago source set: ' . $call['to']];
                    continue;
                }
                $indexFile($path);
                if (!isset($nodes[$target])) {
                    $unresolved[] = [$call['file'], $call['position'], 'Call target declaration was not uniquely indexed: ' . $call['to']];
                    continue;
                }
                $result[] = $call;
            }
            usort($result, static fn ($a, $b) => [$a['to'], $a['path'], $a['line'], $a['column']] <=> [$b['to'], $b['path'], $b['line'], $b['column']]);
            return $edges[$key] = $result;
        };
        $proofs = [];
        $depthWarnings = [];
        $cycleIncomplete = false;
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
            $adjacency = [];
            foreach (array_keys($seen) as $key) {
                $adjacency[$key] = [];
                foreach ($nodes[$key]['calls'] as $call) {
                    $target = strtolower($call['to']);
                    if (isset($seen[$target], $nodes[$target])) $adjacency[$key][] = $target;
                }
            }
            foreach (GraphCycles::find($adjacency) as $component) {
                $first = $nodes[$component[0]];
                if (count($component) === 1 && $first['bounded_recursion']) {
                    $this->report($context, Level::Note, 'bounded-recursion', "Scope {$scopeId} has a guarded, strictly decreasing self-call in {$first['symbol']}.", $first['file'], $first['position'], $first['position'] + 1);
                    continue;
                }
                $cycleIncomplete = true;
                $names = array_map(static fn (string $key): string => $nodes[$key]['symbol'], array_slice($component, 0, 3));
                $message = "Scope {$scopeId} reaches a recursive call cycle of " . count($component) . ' methods: ' . implode(', ', $names) . (count($component) > 3 ? ', ...' : '') . '. Termination is not proven.';
                $this->report($context, Level::Error, 'recursive-cycle', $message, $first['file'], $first['position'], $first['position'] + 1);
            }
        }
        foreach ([...$unresolved, ...array_values($depthWarnings)] as [$file, $position, $reason]) $this->report($context, Level::Error, 'scope-graph-incomplete', 'Call graph coverage is incomplete: ' . $reason, $file, $position, $position + 1);
        $complete = $unresolved === [] && $depthWarnings === [] && !$cycleIncomplete;
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

    /** @return array<string, array{string, string}> */
    private function injectedProperties(Node\Stmt\Class_ $class, NodeFinder $finder): array
    {
        if (!$class->isFinal()) return [];
        $constructor = $class->getMethod('__construct');
        if ($constructor === null) return [];
        $properties = [];
        foreach ($constructor->params as $parameter) {
            if (!$parameter->isPromoted() || !$parameter->isPrivate() || !$parameter->type instanceof Node\Name || !is_string($parameter->var->name)) continue;
            $name = $parameter->var->name;
            $type = ($parameter->type->getAttribute('resolvedName') ?? $parameter->type)->toString();
            $target = null;
            $invalid = false;
            foreach ($parameter->attrGroups as $group) foreach ($group->attrs as $attribute) {
                $attributeName = ($attribute->name->getAttribute('resolvedName') ?? $attribute->name)->toString();
                if (strcasecmp($attributeName, 'Symfony\\Component\\DependencyInjection\\Attribute\\Target') !== 0) continue;
                if ($target !== null || count($attribute->args) !== 1 || !$attribute->args[0]->value instanceof Node\Scalar\String_) {
                    $invalid = true;
                    continue;
                }
                $target = $attribute->args[0]->value->value;
            }
            if ($invalid) continue;
            $key = $target === null ? $type : $type . ' $' . ltrim($target, '$');
            $bindingKey = strtolower($key);
            if ($target !== null && !isset($this->classBindings[$bindingKey])) continue;
            $concrete = $this->classBindings[$bindingKey] ?? $type;
            $binding = isset($this->classBindings[$bindingKey]) ? "Symfony service alias {$key} -> {$concrete}" : "declared promoted property {$type}";
            $properties[$name] = [$concrete, $binding];
        }
        foreach ($finder->find($class->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec || $node instanceof Node\Stmt\Unset_ || $node instanceof Node\Arg) as $mutation) {
            $targets = [];
            if ($mutation instanceof Node\Stmt\Unset_) $targets = $mutation->vars;
            elseif ($mutation instanceof Node\Arg) $targets = [$mutation->value];
            elseif ($mutation instanceof Node\Expr\AssignRef) $targets = [$mutation->var, $mutation->expr];
            else $targets = [$mutation->var];
            foreach ($targets as $target) {
                $name = self::thisProperty($target);
                if ($name !== null) unset($properties[$name]);
            }
        }
        return $properties;
    }

    private static function thisProperty(Node $node): ?string
    {
        return $node instanceof Node\Expr\PropertyFetch && $node->var instanceof Node\Expr\Variable && $node->var->name === 'this' && $node->name instanceof Node\Identifier ? $node->name->toString() : null;
    }

    /** false means an AsAlias attribute exists but cannot be proved from one literal interface class. */
    private static function asAlias(Node\Stmt\Class_ $class): string|false|null
    {
        $found = null;
        foreach ($class->attrGroups as $group) foreach ($group->attrs as $attribute) {
            $name = ($attribute->name->getAttribute('resolvedName') ?? $attribute->name)->toString();
            if (strcasecmp($name, 'Symfony\\Component\\DependencyInjection\\Attribute\\AsAlias') !== 0) continue;
            if ($found !== null || count($attribute->args) !== 1) return false;
            $argument = $attribute->args[0];
            $value = $argument->value;
            if ($argument->name !== null || $argument->unpack || !$value instanceof Node\Expr\ClassConstFetch || !$value->class instanceof Node\Name || !$value->name instanceof Node\Identifier || strcasecmp($value->name->toString(), 'class') !== 0) return false;
            $found = ($value->class->getAttribute('resolvedName') ?? $value->class)->toString();
        }
        return $found;
    }

    /** @return array<string, mixed> */
    private static function edge(string $from, string $to, string $path, string $file, string $source, Node\Expr $call, string $evidence): array
    {
        return ['from' => $from, 'to' => $to, 'path' => $path, 'file' => $file, 'line' => $call->getStartLine(), 'column' => self::column($source, $call->getStartFilePos()), 'position' => $call->getStartFilePos(), 'end' => $call->getEndFilePos() + 1, 'evidence' => $evidence];
    }

    private static function column(string $source, int $position): int
    {
        $lineStart = strrpos(substr($source, 0, $position), "\n");
        return $position - ($lineStart === false ? 0 : $lineStart + 1);
    }
}
