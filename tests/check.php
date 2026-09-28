<?php

declare(strict_types=1);

use ByteKitsune\MagoArchitectureGraph\Policy;
use ByteKitsune\MagoArchitectureGraph\GraphPolicy;
use ByteKitsune\MagoArchitectureGraph\Analyzer\GraphCycles;
use ByteKitsune\MagoArchitectureGraph\Analyzer\RecursionProof;
use ByteKitsune\MagoArchitectureGraph\Analyzer\GraphHook;
use ByteKitsune\MagoArchitectureGraph\Analyzer\CallCollector;
use PhpParser\NodeFinder;
use PhpParser\Node\Stmt\Class_;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

require __DIR__ . '/source-autoload.php';

$policy = new Policy(__DIR__ . '/corpus', __DIR__ . '/corpus/policy.json');
$sourcePath = 'src/Area/Alpha/Controller/OrderController.php';
$source = $policy->classify($sourcePath, 'App\Area\Alpha\Controller');
if ($source === null || $source['id'] !== 'area-controller') throw new RuntimeException('Source must match both path and namespace.');
if ($policy->classify($sourcePath, 'App\Wrong') !== null) throw new RuntimeException('Namespace mismatch was classified.');
if ($policy->relativePath('/outside/OrderController.php') !== null) throw new RuntimeException('Outside path was accepted.');
$same = $policy->classify('src/Area/Alpha/Service/OrderService.php', 'App\Area\Alpha\Service');
$hidden = $policy->classify('src/Area/Alpha/Service/Internal/HiddenRepository.php', 'App\Area\Alpha\Service\Internal');
$foreign = $policy->classify('src/Area/Beta/Service/OtherService.php', 'App\Area\Beta\Service');
if ($same === null || $hidden === null || $foreign === null) throw new RuntimeException('Target classification failed.');
if ($policy->violation($source, $same, $sourcePath, 'src/Area/Alpha/Service/OrderService.php') !== null) throw new RuntimeException('Valid entrypoint was rejected.');
if ($policy->violation($source, $hidden, $sourcePath, 'src/Area/Alpha/Service/Internal/HiddenRepository.php') !== 'forbidden-internal-access') throw new RuntimeException('Hidden service was allowed.');
if ($policy->violation($source, $foreign, $sourcePath, 'src/Area/Beta/Service/OtherService.php') !== 'foreign-module-instance') throw new RuntimeException('Foreign instance was allowed.');
if ($policy->targetPath('App\Area\Alpha\Service\OrderService') !== 'src/Area/Alpha/Service/OrderService.php') throw new RuntimeException('Target path mapping failed.');
echo "Policy checks passed\n";

$graphData = json_decode(file_get_contents(__DIR__ . '/graph-corpus/policy.json'), true, 64, JSON_THROW_ON_ERROR)['scope_graph'];
$graph = new GraphPolicy($graphData);
if ($graph->scopeFor('src/Red/Entry.php', 'App\\Red\\Entry')['id'] !== 'red') throw new RuntimeException('Graph scope matching failed.');
if ($graph->scopeFor('src/Red/Entry.php', 'App\\Blue\\Entry') !== null) throw new RuntimeException('Graph scope ignored namespace.');
if ($graph->permission('red', 'App\\Api\\Gateway::expensive')['decision'] !== 'deny') throw new RuntimeException('Graph denial missing.');
if ($graph->permission('blue', 'App\\Api\\Gateway::expensive')['decision'] !== 'allow') throw new RuntimeException('Graph allowance missing.');
$invalid = $graphData;
$invalid['source_root'] = '../outside';
try { new GraphPolicy($invalid); throw new RuntimeException('Unsafe graph root accepted.'); } catch (InvalidArgumentException) {}
$invalid = $graphData;
$invalid['mode'] = 'unknown';
try { new GraphPolicy($invalid); throw new RuntimeException('Unknown graph mode accepted.'); } catch (InvalidArgumentException) {}
echo "Graph policy checks passed\n";

$parser = (new ParserFactory())->createForNewestSupportedVersion();
$finder = new NodeFinder();
foreach ([
    'if ($n <= 0) return; self::walk($n - 1);' => true,
    'if ($n <= 0) return; self::walk($n - 2);' => true,
    'if ($n <= 0) return; self::walk($n - 0);' => false,
    'if ($n === 0) return; self::walk($n - 1);' => false,
    'if ($n <= 0) return; self::walk($n);' => false,
    'if ($n <= 0) return; self::walk($n - 1); self::walk($n - 1);' => false,
] as $body => $expected) {
    $parsed = $parser->parse('<?php final class Walk { public static function walk(int $n): void {' . $body . '} }');
    $class = $finder->findFirstInstanceOf($parsed, Class_::class);
    if (!$class instanceof Class_ || RecursionProof::bounded($class, $class->getMethod('walk'), 'Walk') !== $expected) throw new RuntimeException('Recursion breaker proof mismatch.');
}
$chain = [];
for ($index = 0; $index < 20_000; $index++) $chain['n' . $index] = $index === 19_999 ? [] : ['n' . ($index + 1)];
if (GraphCycles::find($chain) !== []) throw new RuntimeException('Long acyclic chain was misclassified.');
$chain['n19999'] = ['n19998'];
if (GraphCycles::find($chain) !== [['n19998', 'n19999']]) throw new RuntimeException('Iterative cycle detection failed.');
echo "Recursion checks passed\n";

$asAlias = new ReflectionMethod(GraphHook::class, 'asAlias');
foreach ([
    '#[\\Symfony\\Component\\DependencyInjection\\Attribute\\AsAlias(Port::class)]' => 'Port',
    '#[\\Symfony\\Component\\DependencyInjection\\Attribute\\AsAlias("port")]' => false,
    '#[\\Symfony\\Component\\DependencyInjection\\Attribute\\AsAlias(target: Port::class)]' => false,
] as $attribute => $expected) {
    $parsed = $parser->parse('<?php ' . $attribute . ' final class Service {}');
    $class = $finder->findFirstInstanceOf($parsed, Class_::class);
    if (!$class instanceof Class_ || $asAlias->invoke(null, $class) !== $expected) throw new RuntimeException('Symfony AsAlias shape mismatch.');
}
echo "AsAlias shape checks passed\n";

$bindings = new ReflectionMethod(GraphHook::class, 'injectedProperties');
$hook = new GraphHook(new Policy(__DIR__ . '/alias-corpus', __DIR__ . '/alias-corpus/policy.json'), ['app\\port' => 'App\\Gateway']);
foreach ([
    'private Port $port; public function __construct(Port $port) { $this->port = $port; }' => true,
    'public function __construct(private Port $port) {}' => true,
    'private Port $port; public function __construct(Port $port) { if (rand(0, 1)) $this->port = $port; }' => false,
    'private Port $port; public function __construct(Port $port) { $port = new Other(); $this->port = $port; }' => false,
    'private Other $port; public function __construct(Port $port) { $this->port = $port; }' => false,
    'private Port $port; public function __construct(Port $port) { $this->port = $port; } public function replace(Port $port): void { $this->port = $port; }' => false,
    'private Port $port; public function __construct(Port $port) { $this->port = $port; } public function replace(string $name, Port $port): void { $this->{$name} = $port; }' => false,
] as $body => $expected) {
    $parsed = (new NodeTraverser(new NameResolver()))->traverse($parser->parse('<?php namespace App; final class Example { ' . $body . ' }'));
    $class = $finder->findFirstInstanceOf($parsed, Class_::class);
    if (!$class instanceof Class_ || isset($bindings->invoke($hook, $class, $finder)['port']) !== $expected) throw new RuntimeException('Constructor property attestation mismatch: ' . $body);
}
echo "Constructor property checks passed\n";

$serviceHook = new GraphHook(new Policy(__DIR__ . '/alias-corpus', __DIR__ . '/alias-corpus/policy.json'), [], true, null, [
    'processor.safe' => 'App\\Processor',
    'processor.danger' => 'App\\Processor',
    'safe.port' => 'App\\SafePort',
    'danger.port' => 'App\\DangerPort',
], [
    'processor.safe' => [0 => 'safe.port'],
    'processor.danger' => [0 => 'danger.port'],
]);
$parsed = (new NodeTraverser(new NameResolver()))->traverse($parser->parse('<?php namespace App; final class Processor { public function __construct(private Port $port) {} }'));
$class = $finder->findFirstInstanceOf($parsed, Class_::class);
if (!$class instanceof Class_) throw new RuntimeException('Service constructor fixture missing.');
$safe = $bindings->invoke($serviceHook, $class, $finder, 'processor.safe')['port'] ?? null;
$danger = $bindings->invoke($serviceHook, $class, $finder, 'processor.danger')['port'] ?? null;
$unbound = $bindings->invoke($serviceHook, $class, $finder)['port'] ?? null;
if ($safe === null || $safe[0] !== 'App\\SafePort' || $safe[3] !== 'safe.port'
    || $danger === null || $danger[0] !== 'App\\DangerPort' || $danger[3] !== 'danger.port' || $unbound !== null) {
    throw new RuntimeException('Service constructor variants were conflated.');
}
echo "Service constructor binding checks passed\n";

foreach ([
    'private readonly Port $port; public function __construct(Port $port) { $this->port = $port; }' => true,
    'public function __construct(private readonly Port $port) {}' => true,
    'private Port $port; public function __construct(Port $port) { $this->port = $port; }' => false,
] as $body => $expected) {
    $parsed = (new NodeTraverser(new NameResolver()))->traverse($parser->parse('<?php namespace App; class Example { ' . $body . ' }'));
    $class = $finder->findFirstInstanceOf($parsed, Class_::class);
    if (!$class instanceof Class_ || isset($bindings->invoke($hook, $class, $finder)['port']) !== $expected) throw new RuntimeException('Non-final constructor property attestation mismatch: ' . $body);
}
echo "Non-final readonly property checks passed\n";

$parsed = $parser->parse('<?php final class Deferred { public function run(): void { $later = function () { Gateway::denied(); }; Service::run(); } }');
$class = $finder->findFirstInstanceOf($parsed, Class_::class);
if (!$class instanceof Class_) throw new RuntimeException('Call collector fixture missing.');
[$direct, $instance, $functions, $creations, $unknown] = CallCollector::collect($class->getMethod('run')->stmts);
if (count($direct) !== 1 || count($instance) !== 0 || count($functions) !== 0 || count($creations) !== 0 || count($unknown) !== 1 || $direct[0]->name->toString() !== 'run') throw new RuntimeException('Deferred closure was treated as an immediate graph edge.');
$parsed = $parser->parse('<?php final class Deferred { public function run(): void { Gateway::denied(...); $fn(); \\call_user_func([Gateway::class, "denied"]); } }');
$class = $finder->findFirstInstanceOf($parsed, Class_::class);
[$direct, $instance, $functions, $creations, $unknown] = CallCollector::collect($class->getMethod('run')->stmts);
if ($direct !== [] || $instance !== [] || count($functions) !== 2 || $creations !== [] || count($unknown) !== 1) throw new RuntimeException('Callable creation or dynamic function invocation was misclassified.');
echo "Call collection checks passed\n";
