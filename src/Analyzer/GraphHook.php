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
    /** @param array<string, string> $classBindings @param array<string, string> $serviceClassBindings */
    public function __construct(private readonly Policy $policy, private readonly array $classBindings = [], private readonly bool $serviceConfigurationComplete = true, private readonly ?DeclarationIndex $declarations = null, private readonly array $serviceClassBindings = []) {}

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
        $functionCoverage = [];
        $constructorTargets = [];
        $indexFile = function (string $path) use (&$nodes, &$indexed, &$duplicates, &$unresolved, &$aliasesByClass, &$functionCoverage, &$constructorTargets, $files, $context, $parser, $finder, $graph): void {
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
                if ($this->declarations?->isDuplicate($className)) continue;
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
                    [$staticCalls, $instanceCalls, $functionCalls, $creations, $indirect] = CallCollector::collect($method->stmts ?? []);
                    foreach ($indirect as $call) $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Nested or indirect dispatch in {$symbol} is not modeled"];
                    foreach ($staticCalls as $call) {
                        if (!$call->class instanceof Node\Name || !$call->name instanceof Node\Identifier || in_array(strtolower($call->class->toString()), ['static', 'parent'], true)) {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Dynamic or relative static call in {$symbol}"];
                            continue;
                        }
                        $targetClass = strtolower($call->class->toString()) === 'self' ? $className : ($call->class->getAttribute('resolvedName') ?? $call->class)->toString();
                        $calls[] = self::edge($symbol, $targetClass . '::' . $call->name->toString(), $path, $source->path, $source->contents, $call, 'explicit static call');
                    }
                    $consumedLookups = [];
                    $localServiceCalls = $this->straightLineContainerCalls($method->stmts ?? [], $properties, $graph);
                    if ($localServiceCalls !== null) {
                        [$lookup, $localCalls, $targetClass, $id] = $localServiceCalls;
                        $consumedLookups[spl_object_id($lookup)] = true;
                        foreach ($localCalls as $localCall) {
                            $consumedLookups[spl_object_id($localCall)] = true;
                            $evidence = count($localCalls) === 1 && $localCall->args === [] ? 'Symfony single-use local service ID ' : 'Symfony straight-line local service ID ';
                            $calls[] = self::edge($symbol, $targetClass . '::' . $localCall->name->toString(), $path, $source->path, $source->contents, $localCall, $evidence . $id . ' -> ' . $targetClass);
                        }
                    }
                    foreach ($instanceCalls as $call) {
                        if (isset($consumedLookups[spl_object_id($call)])) continue;
                        if (!$call->name instanceof Node\Identifier) {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Dynamic instance method call in {$symbol}"];
                            continue;
                        }
                        if ($call->var instanceof Node\Expr\MethodCall && self::isContainerGet($call->var, $properties)) {
                            $lookup = $call->var;
                            $consumedLookups[spl_object_id($lookup)] = true;
                            $target = $this->containerTarget($lookup, $graph);
                            if ($target === null) {
                                $methodUnresolved[] = [$source->path, $lookup->getStartFilePos(), "Unproven Symfony container lookup in {$symbol}"];
                                continue;
                            }
                            [$targetClass, $id] = $target;
                            $calls[] = self::edge($symbol, $targetClass . '::' . $call->name->toString(), $path, $source->path, $source->contents, $call, 'Symfony literal service ID ' . $id . ' -> ' . $targetClass);
                            continue;
                        }
                        if (self::isContainerGet($call, $properties)) {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Symfony container lookup result escapes immediate call in {$symbol}"];
                            continue;
                        }
                        $binding = self::receiverBinding($call->var, $call->name->toString(), $class, $className, $properties);
                        if ($binding === null) {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Unresolved instance receiver in {$symbol}"];
                            continue;
                        }
                        [$targetClass, $evidence] = $binding;
                        $calls[] = self::edge($symbol, $targetClass . '::' . $call->name->toString(), $path, $source->path, $source->contents, $call, $evidence);
                    }
                    foreach ($creations as $creation) {
                        if (!$creation->class instanceof Node\Name || in_array(strtolower($creation->class->toString()), ['static', 'parent'], true)) {
                            $methodUnresolved[] = [$source->path, $creation->getStartFilePos(), "Dynamic or relative construction in {$symbol}"];
                            continue;
                        }
                        $targetClass = strtolower($creation->class->toString()) === 'self' ? $className : ($creation->class->getAttribute('resolvedName') ?? $creation->class)->toString();
                        if (!$graph->withinRoot($targetClass)) continue;
                        $target = self::constructorTarget($targetClass, $context, $constructorTargets);
                        if ($target === null || (is_string($target) && !$graph->withinRoot(explode('::', $target, 2)[0]))) {
                            $methodUnresolved[] = [$source->path, $creation->getStartFilePos(), "Constructor target is outside the modeled source graph: {$targetClass}"];
                            continue;
                        }
                        if ($target !== false) $calls[] = self::edge($symbol, $target, $path, $source->path, $source->contents, $creation, 'literal constructor call');
                    }
                    foreach ($functionCalls as $call) {
                        if ($call->isFirstClassCallable()) {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "First-class function callable in {$symbol} is not an invocation"];
                            continue;
                        }
                        if (!$call->name instanceof Node\Name) {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Dynamic function invocation in {$symbol}"];
                            continue;
                        }
                        $function = strtolower(ltrim($call->name->toString(), '\\'));
                        if (!in_array($function, ['call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array'], true)) {
                            if (in_array($function, ['array_map', 'array_filter', 'array_walk', 'array_walk_recursive', 'usort', 'uasort', 'uksort', 'preg_replace_callback', 'register_shutdown_function', 'set_error_handler'], true)) {
                                $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Callback-dispatching function {$function} in {$symbol} is not modeled"];
                            } elseif (($target = $this->functionTarget($call->name, self::namespaceOf($className), $graph, $context, $functionCoverage)) === null) {
                                $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Named function call {$function} in {$symbol} is not modeled"];
                            } elseif ($target !== false) {
                                $calls[] = self::edge($symbol, 'function:' . $target, $path, $source->path, $source->contents, $call, 'project function call');
                            }
                            continue;
                        }
                        // A namespaced function can shadow a PHP builtin. Only an
                        // explicit global call proves the dispatch semantics.
                        if (!$call->name->isFullyQualified() || $call->args === [] || $call->args[0]->name !== null || $call->args[0]->unpack) {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Unproven callback invocation in {$symbol}"];
                            continue;
                        }
                        $target = self::callbackTarget($call->args[0]->value, $class, $className, $properties);
                        if ($target === null) {
                            $methodUnresolved[] = [$source->path, $call->getStartFilePos(), "Dynamic callback target in {$symbol}"];
                            continue;
                        }
                        [$targetSymbol, $evidence] = $target;
                        $calls[] = self::edge($symbol, $targetSymbol, $path, $source->path, $source->contents, $call, $evidence);
                    }
                    $nodes[$key] = ['symbol' => $symbol, 'class' => $className, 'path' => $path, 'file' => $source->path, 'line' => $method->getStartLine(), 'position' => $method->getStartFilePos(), 'static' => $method->isStatic(), 'calls' => $calls, 'unresolved' => $methodUnresolved, 'bounded_recursion' => RecursionProof::bounded($class, $method, $className)];
                }
            }
            // Only namespace-level functions are callable symbols. Nested
            // declarations have execution-dependent availability.
            foreach ($statements as $statement) {
                $topLevel = $statement instanceof Node\Stmt\Namespace_ ? $statement->stmts : [$statement];
                foreach ($topLevel as $declaration) {
                    if (!$declaration instanceof Node\Stmt\Function_ || !$declaration->namespacedName instanceof Node\Name) continue;
                    $name = $declaration->namespacedName->toString();
                    if ($this->declarations?->isDuplicateFunction($name)) continue;
                    $symbol = 'function:' . $name;
                    $key = strtolower($symbol);
                    if (isset($nodes[$key]) || isset($duplicates[$key])) {
                        $unresolved[] = [$source->path, $declaration->getStartFilePos(), "Duplicate function symbol {$name}"];
                        unset($nodes[$key]);
                        $duplicates[$key] = true;
                        continue;
                    }
                    $calls = [];
                    $functionUnresolved = [];
                    [$staticCalls, $instanceCalls, $functionCalls, $creations, $indirect] = CallCollector::collect($declaration->stmts);
                    foreach ([...$indirect, ...$instanceCalls] as $call) $functionUnresolved[] = [$source->path, $call->getStartFilePos(), "Unproven dispatch in {$symbol}"];
                    foreach ($staticCalls as $call) {
                        if (!$call->class instanceof Node\Name || !$call->name instanceof Node\Identifier || in_array(strtolower($call->class->toString()), ['self', 'static', 'parent'], true)) {
                            $functionUnresolved[] = [$source->path, $call->getStartFilePos(), "Dynamic or relative static call in {$symbol}"];
                            continue;
                        }
                        $targetClass = ($call->class->getAttribute('resolvedName') ?? $call->class)->toString();
                        $calls[] = self::edge($symbol, $targetClass . '::' . $call->name->toString(), $path, $source->path, $source->contents, $call, 'explicit static call');
                    }
                    foreach ($creations as $creation) {
                        if (!$creation->class instanceof Node\Name || in_array(strtolower($creation->class->toString()), ['self', 'static', 'parent'], true)) {
                            $functionUnresolved[] = [$source->path, $creation->getStartFilePos(), "Dynamic or relative construction in {$symbol}"];
                            continue;
                        }
                        $targetClass = ($creation->class->getAttribute('resolvedName') ?? $creation->class)->toString();
                        if (!$graph->withinRoot($targetClass)) continue;
                        $target = self::constructorTarget($targetClass, $context, $constructorTargets);
                        if ($target === null || (is_string($target) && !$graph->withinRoot(explode('::', $target, 2)[0]))) {
                            $functionUnresolved[] = [$source->path, $creation->getStartFilePos(), "Constructor target is outside the modeled source graph: {$targetClass}"];
                            continue;
                        }
                        if ($target !== false) $calls[] = self::edge($symbol, $target, $path, $source->path, $source->contents, $creation, 'literal constructor call');
                    }
                    foreach ($functionCalls as $call) {
                        if ($call->isFirstClassCallable() || !$call->name instanceof Node\Name) {
                            $functionUnresolved[] = [$source->path, $call->getStartFilePos(), "Dynamic or deferred function call in {$symbol}"];
                            continue;
                        }
                        $function = strtolower(ltrim($call->name->toString(), '\\'));
                        if (in_array($function, ['call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array', 'array_map', 'array_filter', 'array_walk', 'array_walk_recursive', 'usort', 'uasort', 'uksort', 'preg_replace_callback', 'register_shutdown_function', 'set_error_handler'], true)) {
                            $functionUnresolved[] = [$source->path, $call->getStartFilePos(), "Callback dispatch in {$symbol} is not modeled"];
                            continue;
                        }
                        $target = $this->functionTarget($call->name, self::namespaceOf($name), $graph, $context, $functionCoverage);
                        if ($target === null) $functionUnresolved[] = [$source->path, $call->getStartFilePos(), "Named function call {$function} in {$symbol} is not modeled"];
                        elseif ($target !== false) $calls[] = self::edge($symbol, 'function:' . $target, $path, $source->path, $source->contents, $call, 'project function call');
                    }
                    $nodes[$key] = ['symbol' => $symbol, 'class' => '', 'path' => $path, 'file' => $source->path, 'line' => $declaration->getStartLine(), 'position' => $declaration->getStartFilePos(), 'static' => false, 'calls' => $calls, 'unresolved' => $functionUnresolved, 'bounded_recursion' => false];
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
        if ($this->declarations !== null) {
            if (!$this->declarations->complete() && $files !== []) {
                $first = reset($files);
                $unresolved[] = [$first->file, 0, 'Complete declaration scan was unavailable'];
            }
            foreach ($this->declarations->problems() as [$file, $position, $name]) $unresolved[] = [$file, $position, "Duplicate or unresolved declaration {$name}"];
        }
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
            $calls = array_values(array_filter($nodes[$key]['calls'], static fn ($call) => $graph->withinRoot(self::targetType($call['to']))));
            foreach ($calls as $index => $call) {
                if (!str_starts_with($call['evidence'], 'declared promoted property ') && !str_starts_with($call['evidence'], 'constructor-attested property ')) continue;
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
            $methodCalls = array_values(array_filter($calls, static fn ($call) => !str_starts_with($call['to'], 'function:')));
            $members = array_map(static function ($call) {
                [$class, $method] = explode('::', $call['to'], 2);
                return new \Mago\Sdk\Analyzer\Metadata\MemberIdentifier($class, $method);
            }, $methodCalls);
            $metadata = $members === [] ? [] : $context->codebase->getMultipleMethods($members);
            $methodIndex = 0;
            $result = [];
            foreach ($calls as $call) {
                $target = strtolower($call['to']);
                $location = str_starts_with($call['to'], 'function:')
                    ? $context->codebase->getFunction(substr($call['to'], strlen('function:')))?->location->file
                    : ($metadata[$methodIndex++]?->location->file ?? null);
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
                if ($call['evidence'] === 'literal static callback' && !$nodes[$target]['static']) {
                    $unresolved[] = [$call['file'], $call['position'], 'Class callback target is not a static method: ' . $call['to']];
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
        $reportedCycles = [];
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
                        if ($graph->withinRoot(self::targetType($call['to'])) && !isset($seen[strtolower($call['to'])])) {
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
                $cycleKey = $scopeId . ':' . implode(',', $component);
                if (isset($reportedCycles[$cycleKey])) continue;
                $reportedCycles[$cycleKey] = true;
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

    /** @param array<string, array{string, string, string}> $properties */
    private static function isContainerGet(Node\Expr\MethodCall $call, array $properties): bool
    {
        if (!$call->name instanceof Node\Identifier || strcasecmp($call->name->toString(), 'get') !== 0) return false;
        $property = self::thisProperty($call->var);
        if ($property === null || !isset($properties[$property])) return false;
        return in_array(strtolower($properties[$property][2]), [
            'psr\\container\\containerinterface',
            'symfony\\component\\dependencyinjection\\containerinterface',
        ], true);
    }

    /** @return array{string, string}|null Concrete class and exact service ID. */
    private function containerTarget(Node\Expr\MethodCall $lookup, GraphPolicy $graph): ?array
    {
        if (!$this->serviceConfigurationComplete || count($lookup->args) !== 1 || $lookup->args[0]->name !== null || $lookup->args[0]->unpack) return null;
        $argument = $lookup->args[0]->value;
        $id = null;
        if ($argument instanceof Node\Scalar\String_) $id = $argument->value;
        elseif ($argument instanceof Node\Expr\ClassConstFetch && $argument->class instanceof Node\Name && $argument->name instanceof Node\Identifier && strcasecmp($argument->name->toString(), 'class') === 0 && !in_array(strtolower($argument->class->toString()), ['self', 'static', 'parent'], true)) {
            $id = ($argument->class->getAttribute('resolvedName') ?? $argument->class)->toString();
        }
        if ($id === null || !isset($this->serviceClassBindings[$id])) return null;
        $class = $this->serviceClassBindings[$id];
        return $graph->withinRoot($class) ? [$class, $id] : null;
    }

    /**
     * A bounded sequence of exact statements proves the local is assigned once,
     * then only used as the receiver of literal calls. Simple arguments cannot
     * mutate or expose that local. Control flow and other statements fail closed.
     *
     * @param list<Node\Stmt> $statements
     * @param array<string, array{string, string, string}> $properties
     * @return array{Node\Expr\MethodCall, list<Node\Expr\MethodCall>, string, string}|null
     */
    private function straightLineContainerCalls(array $statements, array $properties, GraphPolicy $graph): ?array
    {
        if (count($statements) < 2 || count($statements) > 32 || !$statements[0] instanceof Node\Stmt\Expression) return null;
        $assignment = $statements[0]->expr;
        if (!$assignment instanceof Node\Expr\Assign || !$assignment->var instanceof Node\Expr\Variable || !is_string($assignment->var->name) || $assignment->var->name === 'this') return null;
        if (!$assignment->expr instanceof Node\Expr\MethodCall || !self::isContainerGet($assignment->expr, $properties)) return null;
        $target = $this->containerTarget($assignment->expr, $graph);
        if ($target === null) return null;
        $calls = [];
        foreach (array_slice($statements, 1) as $statement) {
            if (!$statement instanceof Node\Stmt\Expression || !$statement->expr instanceof Node\Expr\MethodCall) return null;
            $call = $statement->expr;
            if (!$call->name instanceof Node\Identifier || $call->isFirstClassCallable() || !$call->var instanceof Node\Expr\Variable || $call->var->name !== $assignment->var->name) return null;
            foreach ($call->args as $argument) {
                if ($argument->unpack || $argument->byRef || !self::simpleArgument($argument->value, $assignment->var->name)) return null;
            }
            $calls[] = $call;
        }
        return [$assignment->expr, $calls, $target[0], $target[1]];
    }

    private static function simpleArgument(Node\Expr $value, string $local): bool
    {
        if ($value instanceof Node\Scalar\String_ || $value instanceof Node\Scalar\LNumber || $value instanceof Node\Scalar\DNumber || $value instanceof Node\Expr\ConstFetch) return true;
        if ($value instanceof Node\Expr\Variable) return is_string($value->name) && $value->name !== $local;
        return $value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strcasecmp($value->name->toString(), 'class') === 0;
    }

    /** @param array<string, array{string, string, string}> $properties @return array{string, string}|null */
    private static function receiverBinding(Node\Expr $receiver, string $method, Node\Stmt\Class_ $class, string $className, array $properties): ?array
    {
        if ($receiver instanceof Node\Expr\Variable && $receiver->name === 'this') {
            if ($class->isFinal()) return [$className, 'final-class this call'];
            $declared = $class->getMethod($method);
            return $declared !== null && ($declared->isPrivate() || $declared->isFinal())
                ? [$className, 'non-overridable this method'] : null;
        }
        $property = self::thisProperty($receiver);
        return $property !== null && isset($properties[$property]) ? [$properties[$property][0], $properties[$property][1]] : null;
    }

    /** @param array<string, array{string, string, string}> $properties @return array{string, string}|null */
    private static function callbackTarget(Node\Expr $callback, Node\Stmt\Class_ $class, string $className, array $properties): ?array
    {
        if (!$callback instanceof Node\Expr\Array_ || count($callback->items) !== 2) return null;
        [$receiver, $method] = $callback->items;
        if ($receiver === null || $method === null || $receiver->key !== null || $method->key !== null || $receiver->unpack || $method->unpack || !$method->value instanceof Node\Scalar\String_) return null;
        $methodName = $method->value->value;
        if ($methodName === '' || !preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $methodName)) return null;
        $value = $receiver->value;
        if ($value instanceof Node\Expr\ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strcasecmp($value->name->toString(), 'class') === 0) {
            $classRef = strtolower($value->class->toString());
            if (in_array($classRef, ['static', 'parent'], true)) return null;
            $targetClass = $classRef === 'self' ? $className : ($value->class->getAttribute('resolvedName') ?? $value->class)->toString();
            return [$targetClass . '::' . $methodName, 'literal static callback'];
        }
        $binding = self::receiverBinding($value, $methodName, $class, $className, $properties);
        return $binding === null ? null : [$binding[0] . '::' . $methodName, 'literal instance callback via ' . $binding[1]];
    }

    /** @param array<string, string|false|null> $cache */
    private function functionTarget(Node\Name $name, string $namespace, GraphPolicy $graph, AfterAnalysisContext $context, array &$cache): string|false|null
    {
        $literal = $name->toString();
        $candidates = $name->isFullyQualified() || $namespace === '' ? [$literal] : [$namespace . '\\' . $literal, $literal];
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (!array_key_exists($key, $cache)) {
                $metadata = $context->codebase->getFunction($candidate);
                $path = $metadata === null ? null : $this->policy->relativePath($metadata->location->file);
                $cache[$key] = $metadata === null ? null : ($path !== null && str_starts_with($path, $graph->sourceRoot . '/') && $graph->withinRoot($metadata->originalName) ? $metadata->originalName : false);
            }
            if ($cache[$key] !== null) return $cache[$key];
        }
        return null;
    }

    private static function namespaceOf(string $name): string
    {
        $separator = strrpos($name, '\\');
        return $separator === false ? '' : substr($name, 0, $separator);
    }

    private static function targetType(string $symbol): string
    {
        return str_starts_with($symbol, 'function:') ? substr($symbol, strlen('function:')) : explode('::', $symbol, 2)[0];
    }

    /** @param array<string, string|false|null> $cache */
    private static function constructorTarget(string $class, AfterAnalysisContext $context, array &$cache): string|false|null
    {
        $key = strtolower($class);
        if (array_key_exists($key, $cache)) return $cache[$key];
        $seen = [];
        $current = $class;
        for ($depth = 0; $depth < 64; $depth++) {
            $currentKey = strtolower($current);
            if (isset($seen[$currentKey])) return $cache[$key] = null;
            $seen[$currentKey] = true;
            $type = $context->codebase->getClass($current);
            if ($type === null || $type->hasIncompleteHierarchy()) return $cache[$key] = null;
            $constructor = $context->codebase->getMethod($current, '__construct');
            if ($constructor !== null) return $cache[$key] = ($constructor->identifier->class ?? $current) . '::__construct';
            if ($type->directParentClass === null) return $cache[$key] = false;
            $current = $type->directParentClass;
        }
        return $cache[$key] = null;
    }

    /** @return array<string, array{string, string, string}> */
    private function injectedProperties(Node\Stmt\Class_ $class, NodeFinder $finder): array
    {
        $constructor = $class->getMethod('__construct');
        if ($constructor === null) return [];
        $properties = [];
        $parameters = [];
        foreach ($constructor->params as $parameter) {
            if (!is_string($parameter->var->name)) continue;
            $name = $parameter->var->name;
            $binding = $this->parameterBinding($parameter);
            if ($binding === null) continue;
            $parameters[$name] = $binding;
            if ($parameter->isPromoted() && $parameter->isPrivate() && ($class->isFinal() || $parameter->isReadonly())) $properties[$name] = [$binding[1], $binding[2], $binding[0]];
        }
        $declared = [];
        foreach ($class->getProperties() as $property) {
            if (!$property->isPrivate() || (!$class->isFinal() && !$property->isReadonly()) || !$property->type instanceof Node\Name) continue;
            $type = ($property->type->getAttribute('resolvedName') ?? $property->type)->toString();
            foreach ($property->props as $declaration) if ($declaration->default === null) $declared[$declaration->name->toString()] = $type;
        }
        $allowedAssignments = [];
        // Only a leading sequence of exact constructor assignments is attested.
        // Branches, parameter rewrites and later property writes stay unresolved.
        foreach ($constructor->stmts ?? [] as $statement) {
            if (!$statement instanceof Node\Stmt\Expression || !$statement->expr instanceof Node\Expr\Assign) break;
            $assignment = $statement->expr;
            $property = self::thisProperty($assignment->var);
            if ($property === null || !isset($declared[$property]) || !$assignment->expr instanceof Node\Expr\Variable || !is_string($assignment->expr->name)) break;
            $parameter = $assignment->expr->name;
            if (!isset($parameters[$parameter]) || strcasecmp($declared[$property], $parameters[$parameter][0]) !== 0 || isset($properties[$property])) break;
            $properties[$property] = [$parameters[$parameter][1], $parameters[$parameter][2], $parameters[$parameter][0]];
            $allowedAssignments[spl_object_id($assignment)] = true;
        }
        foreach ($finder->find($class->stmts, static fn (Node $node): bool => $node instanceof Node\Expr\Assign || $node instanceof Node\Expr\AssignOp || $node instanceof Node\Expr\AssignRef || $node instanceof Node\Expr\PreInc || $node instanceof Node\Expr\PostInc || $node instanceof Node\Expr\PreDec || $node instanceof Node\Expr\PostDec || $node instanceof Node\Stmt\Unset_ || $node instanceof Node\Arg) as $mutation) {
            if ($mutation instanceof Node\Expr\Assign && isset($allowedAssignments[spl_object_id($mutation)])) continue;
            $targets = [];
            if ($mutation instanceof Node\Stmt\Unset_) $targets = $mutation->vars;
            elseif ($mutation instanceof Node\Arg) $targets = [$mutation->value];
            elseif ($mutation instanceof Node\Expr\AssignRef) $targets = [$mutation->var, $mutation->expr];
            else $targets = [$mutation->var];
            foreach ($targets as $target) {
                if ($target instanceof Node\Expr\PropertyFetch && $target->var instanceof Node\Expr\Variable && $target->var->name === 'this' && !$target->name instanceof Node\Identifier) {
                    $properties = [];
                    continue;
                }
                $name = self::thisProperty($target);
                if ($name !== null) unset($properties[$name]);
            }
        }
        return $properties;
    }

    /** @return array{string, string, string}|null Declared type, concrete type and proof. */
    private function parameterBinding(Node\Param $parameter): ?array
    {
        if (!$parameter->type instanceof Node\Name) return null;
        $type = ($parameter->type->getAttribute('resolvedName') ?? $parameter->type)->toString();
        $target = null;
        foreach ($parameter->attrGroups as $group) foreach ($group->attrs as $attribute) {
            $attributeName = ($attribute->name->getAttribute('resolvedName') ?? $attribute->name)->toString();
            if (strcasecmp($attributeName, 'Symfony\\Component\\DependencyInjection\\Attribute\\Target') !== 0) continue;
            if ($target !== null || count($attribute->args) !== 1 || !$attribute->args[0]->value instanceof Node\Scalar\String_) return null;
            $target = $attribute->args[0]->value->value;
        }
        $key = $target === null ? $type : $type . ' $' . ltrim($target, '$');
        $bindingKey = strtolower($key);
        if ($target !== null && !isset($this->classBindings[$bindingKey])) return null;
        $concrete = $this->classBindings[$bindingKey] ?? $type;
        $proof = isset($this->classBindings[$bindingKey]) ? "Symfony service alias {$key} -> {$concrete}" : "constructor-attested property {$type}";
        return [$type, $concrete, $proof];
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
