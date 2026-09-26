# Mago Architecture Graph

**Beta: 0.1.0-beta.1.** The first release checks explicit path-aware module
boundaries. Call-graph reachability is still being ported; do not remove an
existing graph gate until its evidence has been compared on the same project.

This is a Mago **Analyzer Plugin**. Mago 1.50 does not expose an extension API
for its native Guard. Use Guard's `perimeter` and `structural` rules for ordinary
namespace dependencies and symbol conventions. This package covers the narrower
case where the actual source path and parsed namespace must jointly select a
module, where module families isolate path instances, or where another layer
may import only a public entrypoint.

The first beta examines literal class `use` imports. It does not infer method
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
