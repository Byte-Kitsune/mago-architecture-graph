# Mago Architecture Graph

**Beta: 0.1.0-beta.7.** This release checks explicit path-aware module
boundaries and adds a narrow, deterministic call graph. Keep existing
graph gates until its evidence has been compared on the same project.

This is a Mago **Analyzer Plugin**. Mago 1.50 does not expose an extension API
for its native Guard. Use Guard's `perimeter` and `structural` rules for ordinary
namespace dependencies and symbol conventions. This package covers the narrower
case where the actual source path and parsed namespace must jointly select a
module, where module families isolate path instances, or where another layer
may import only a public entrypoint.

The boundary rule examines literal class `use` imports through Mago's targeted
syntax hooks. It does not infer method
calls, resolve dynamic service names, or prove that a class exists at a mapped
PSR-4 path. Mago's Analyzer and Guard retain their own diagnostics. Parse errors
produce no guessed architecture edges.

Install `byte-kitsune/mago-architecture-graph` with `carthage-software/mago`
and register a trusted worker in `mago.toml`:

```toml
[extension-hosts.architecture]
command = ["php", ".mago/architecture-worker.php"]
```

```php
<?php

use ByteKitsune\MagoArchitectureGraph\ArchitectureGraphExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/vendor/autoload.php';
(new Worker(ArchitectureGraphExtension::create(
    dirname(__DIR__),
    dirname(__DIR__) . '/.mago/architecture-policy.json',
)))->run();
```

The policy path is operator-owned executable input to the extension host. A
fictional policy with two independent areas is in
[`tests/corpus/policy.json`](tests/corpus/policy.json). Module descriptors are
ordered; exclusions win. A `path_pattern` ending in `/` matches a directory
prefix; `*` matches one nonempty path segment. Each module declares a namespace
prefix, layer, permitted target layers, and an entrypoint filename suffix.
Optional `module_family` captures a whole `*` segment and rejects cross-instance
imports unless a directed `allowed_family_bindings` entry matches. The policy
rejects unknown fields, duplicate modules, invalid roots and dangling layers.

Run `composer install` and `sh tests/smoke.sh` to exercise positive and negative
paths through a real Mago 1.50 Analyzer worker. The smoke corpus is fictional.

The optional `scope_graph` policy builds shortest paths from explicit static
calls and narrowly proven instance calls in the complete configured Mago source
set. It scans class-like declaration names through Mago's already-parsed source
to reject duplicate symbols, then reparses method bodies only in scope files and
files reached through Mago's method metadata. A scope requires both
its repository path prefix and namespace prefix. Listed method permissions
produce native Mago error or note findings with a bounded `graph-evidence` JSON
note containing the path, policy ID, target declaration and completeness flag.
Unresolved in-root targets, unsupported dynamic/relative calls, reached-file parse failures
and exhausted transitive depth produce `scope-graph-incomplete` errors. A direct
mode stops after one call without treating later calls as missing coverage.
The fictional [graph policy](tests/graph-corpus/policy.json) shows a two-hop
denial and allowance.

For a final class with a private constructor-promoted property or a private
typed property assigned directly from a matching typed constructor parameter, the graph
follows `$this->service->method()` when its receiver has a concrete type or a
literal Symfony binding. Pass the optional type-to-class bindings from
`mago-symfony-wiring`'s `ServiceMap::classBindings()` to `create()`; named
`#[Target('name')]` bindings use the `Interface $name` key. The optional fourth
argument must be `false` when that service map is incomplete. Without a
configuration binding, the extension also recognizes a unique literal
`#[AsAlias(Interface::class)]` on an implementation returned by Mago's interface
metadata. Ambiguous or unsupported aliases are incomplete, never guessed.
Only files that can implement a reached interface are inspected. The
[alias corpus](tests/alias-corpus) covers ordinary, named and attribute aliases.
Ordinary properties require a leading constructor assignment and no later
class write. Conditional assignments, dynamic writes and mutable properties
stay incomplete; the [unsafe-property corpus](tests/unsafe-property-corpus)
checks this rejection.

Reachable recursive components produce `recursive-cycle` errors. A direct
self-call is classified as `bounded-recursion` only for a final-class method
whose sole `int` parameter is guarded by an initial `if ($n <= 0) return;`
(or `$n < 1`) and whose sole remaining statement calls itself with `$n - 1`.
This proves termination of that narrow shape, not its query cost. Mutual cycles,
other break conditions and dynamic dispatch remain hard errors or incomplete
coverage. A denied method does not end traversal, so a cycle behind a denial
remains visible. Detection uses an iterative graph walk, so long chains do not consume
the PHP call stack. Each cycle is reported once per scope, even when several
entry methods reach it.

The declaration scan uses Mago's syntax index and adds no second full PHP
parse. Native Mago `parse` diagnostics are separate from this extension and
must fail the analyzer gate; an individual `graph-evidence.complete` field does
not prove that every source file parsed. The [duplicate corpus](tests/duplicate-corpus)
verifies that an ambiguous declaration cannot certify a graph proof.

Other property assignments, mutable service receivers, non-final receiver
classes, container lookups, decorators, runtime dispatch and indirect callbacks
still need broader treatment. Unsupported reachable calls emit incomplete
findings where observable. This beta therefore does not yet replace Argus's
existing reachability gate or authorize verified-graph repair evidence. Use Mago
Guard for standard dependencies and keep the Argus graph gate enabled while
parity is developed.

The extension was exercised against a synthetic 20,002-file project under a
four-CPU, 4 GiB container limit. In three runs each, the pre-scan beta took
0.93–1.01 s and the declaration-scan version took 1.30 s with identical issues.
Those fixtures are small and regular; results
do not establish a runtime or memory bound for a large Symfony monolith. Measure
the complete `mago analyze` command on a representative project before relying
on it in CI. Keep source discovery and Mago's own analysis costs separate from
extension overhead when comparing runs.
