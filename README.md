# Mago Architecture Graph

**Beta: 0.1.0-beta.3.** This release checks explicit path-aware module
boundaries and adds a narrow, deterministic static-call graph. Keep existing
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
method calls in the complete configured Mago source set. It indexes scope files
first, then loads only files reached through Mago's method metadata. It does not
reparse every file in the project. A scope requires both
its repository path prefix and namespace prefix. Listed method permissions
produce native Mago error or note findings with a bounded `graph-evidence` JSON
note containing the path, policy ID, target declaration and completeness flag.
Unresolved in-root targets, unsupported dynamic/relative calls, parse failures
and exhausted transitive depth produce `scope-graph-incomplete` errors. A direct
mode stops after one call without treating later calls as missing coverage.
The fictional [graph policy](tests/graph-corpus/policy.json) shows a two-hop
denial and allowance.

This graph subset currently does not trace instance calls, Symfony service
aliases, runtime dispatch or recursive call termination. Those gaps prevent it from
replacing Argus's existing reachability gate or its verified-graph repair
evidence. Use Mago Guard for standard dependencies and keep the Argus graph gate
enabled while parity is developed.

The extension was exercised against synthetic 20,001-file projects under a
four-CPU, 4 GiB container limit. Those fixtures are small and regular; results
do not establish a runtime or memory bound for a large Symfony monolith. Measure
the complete `mago analyze` command on a representative project before relying
on it in CI. Keep source discovery and Mago's own analysis costs separate from
extension overhead when comparing runs.
