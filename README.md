# Mago Architecture Graph

Path-aware module boundaries and a conservative PHP call graph for [Mago](https://mago.carthage.software/1.50.0/en/). This beta is a Mago **Analyzer** plugin. Use Mago Guard for ordinary namespace and structural rules; this package adds checks that need the source path, parsed namespace, or a proven call path.

See the [runnable example](examples/README.md) for a controller that crosses a hidden module boundary and reaches a forbidden method through another service.

## Install and run

Requires PHP 8.2+ and Mago 1.50. Pin the beta in your project:

```sh
composer require --dev carthage-software/mago:1.50.0 byte-kitsune/mago-architecture-graph:0.1.0-beta.14
```

Add an extension host to `mago.toml` (keep your normal `[source]` paths configured):

```toml
[extension-hosts.architecture]
command = ["php", ".mago/architecture-worker.php"]
```

Create `.mago/architecture-worker.php`:

```php
<?php

use ByteKitsune\MagoArchitectureGraph\ArchitectureGraphExtension;
use Mago\Sdk\Worker;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

(new Worker(ArchitectureGraphExtension::create(
    $root,
    $root . '/.mago/architecture-policy.json',
)))->run();
```

Run `vendor/bin/mago analyze`. The worker and policy are trusted project inputs; review changes to them like other analysis configuration.

## Configure the checks

Create `.mago/architecture-policy.json`. Paths are relative to the project root; namespace and path must **both** match. Modules are ordered, and exclusions take precedence.

```json
{
  "version": "1",
  "source_root": "src",
  "namespace_root": "App",
  "modules": [
    {
      "id": "controller",
      "path_pattern": "src/Controller/",
      "namespace_prefix": "App\\Controller\\",
      "layer": "controller",
      "allowed_target_layers": ["service"],
      "entrypoint_suffix": "Controller"
    },
    {
      "id": "service",
      "path_pattern": "src/Service/",
      "namespace_prefix": "App\\Service\\",
      "layer": "service",
      "allowed_target_layers": [],
      "entrypoint_suffix": "Service"
    }
  ],
  "scope_graph": {
    "version": "1",
    "enabled": true,
    "mode": "transitive",
    "max_depth": 4,
    "source_root": "src",
    "namespace_root": "App",
    "scopes": [
      {"id": "controllers", "path_prefix": "src/Controller/", "namespace_prefix": "App\\Controller\\"}
    ],
    "method_permissions": [
      {"scope_id": "controllers", "target_type": "App\\Service\\Gateway", "method": "expensive", "decision": "deny", "policy_id": "gateway-budget", "rationale": "Keep expensive work out of controllers"}
    ],
    "unlisted_method_policy": "allow",
    "exclusions": []
  }
}
```

Remove `scope_graph` if you only need module boundaries. `direct` graph mode stops after one edge; `transitive` follows calls up to `max_depth` (1–64). A `path_pattern` ending in `/` matches a directory prefix; `*` matches one nonempty segment. `module_family` can capture such segments and restrict imports between instances; see the [module fixture](tests/corpus/policy.json). The [graph fixture](tests/graph-corpus/policy.json) shows separate allow and deny scopes.

The graph follows literal static calls, narrowly proven instance receivers, constructors, literal callbacks, named project functions, and exact Symfony service lookups. It also follows calls on a literal temporary `new` instance. A local variable assigned one literal `new` expression or exact container `get()` lookup is followed only through up to 31 straight-line calls with simple arguments. Branching, reassignment, escape, dynamic IDs, unproven mutable receivers, overridable dispatch and decorators are **not** silently treated as proven.

For Symfony type, `#[Target]`, and service-ID resolution, install [mago-symfony-wiring](https://github.com/Byte-Kitsune/mago-symfony-wiring) and pass its complete dev service map:

```php
use ByteKitsune\MagoSymfonyWiring\ServiceConfigLoader;

$map = (new ServiceConfigLoader($root, [
    'config/services.yaml',
    'config/services.dev.yaml',
]))->load();

$extension = ArchitectureGraphExtension::create(
    $root,
    $root . '/.mago/architecture-policy.json',
    $map->classBindings(),
    $map->incomplete === [],
    $map->serviceClassBindings(),
);
(new Worker($extension))->run();
```

Pass only reviewed shared/dev service files. A complete literal map proves configured IDs and aliases, not the runtime container or decorators.

## Read the results

Boundary violations appear as Analyzer issues such as `foreign-module-instance` and `forbidden-internal-access`. Graph permissions produce `scope-forbidden-entrypoint-method` errors or `scope-allowed-entrypoint-method` notes. The `graph-evidence` note contains the shortest modeled path, policy ID, target and `complete` flag. `scope-graph-incomplete` means a reached part could not be proven; do not interpret missing denials as a clean graph when it appears. Reachable unproven recursion is `recursive-cycle`; a narrow guarded integer self-call with a positive literal decrement is classified as `bounded-recursion`.

When the graph is enabled and at least one PHP source is in its configured root, the Analyzer also emits one `analysis-attestation` note. Its bounded `extension-attestation` payload identifies the extension, version, `scope_graph` capability, source-file count and completeness. Proofs exceeding the 4096-byte evidence limit are omitted and make the whole graph attestation incomplete. Consumers that require graph coverage should require this note; its absence must not count as a clean run.

This is static evidence, not a runtime trace. It does not replace Mago's parse diagnostics or prove all PHP execution paths. Benchmark the full `mago analyze` run on your own project before setting a CI time budget.

## Develop

```sh
composer install
sh tests/smoke.sh
```

The fictional corpora cover allowed, denied and incomplete paths through a real Mago worker. Licensed under [MIT](LICENSE).
