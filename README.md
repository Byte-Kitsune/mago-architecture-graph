# Mago Architecture Graph

[![Tests](https://github.com/Byte-Kitsune/mago-architecture-graph/actions/workflows/check.yml/badge.svg?branch=main)](https://github.com/Byte-Kitsune/mago-architecture-graph/actions/workflows/check.yml)
[![Security Check](https://github.com/Byte-Kitsune/mago-architecture-graph/actions/workflows/security.yml/badge.svg?branch=main)](https://github.com/Byte-Kitsune/mago-architecture-graph/actions/workflows/security.yml)

Path-aware module boundaries and a conservative PHP call graph for [Mago](https://mago.carthage.software/1.50.0/en/). This package is a Mago **Analyzer** plugin. Use Mago Guard for ordinary namespace and structural rules; this package adds checks that need the source path, parsed namespace, or a proven call path.

See the [runnable example](examples/README.md) for a controller that crosses a hidden module boundary and reaches a forbidden method through another service.

## Install and run

The Composer requirement `^1.50` permits later Mago 1.x releases and excludes 2.x.
Mago 1.50 remains the tested API floor; older releases are not covered.
CI checks this floor and the latest compatible 1.x release.

Requires PHP 8.2+ and Mago >=1.50.0,<2.0.0. Pin the stable release in your project:

```sh
composer require --dev "carthage-software/mago:^1.50" byte-kitsune/mago-architecture-graph:1.2.0
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

When one class has multiple service definitions with different constructor arguments, pass the positional bindings from a revision-matched **compiled dev container reference** as the sixth argument. Map each owner service ID to constructor positions and their target service IDs; include aliases and an empty array for a same-class service with no explicit arguments. Use `null` for an argument whose target cannot be proved. For example:

```php
$extension = ArchitectureGraphExtension::create(
    $root,
    $root . '/.mago/architecture-policy.json',
    serviceClassBindings: [
        'processor.safe' => App\Processor::class,
        'processor.danger' => App\Processor::class,
        'safe.port' => App\SafePort::class,
        'danger.port' => App\DangerPort::class,
    ],
    serviceConstructorBindings: [
        'processor.safe' => [0 => 'safe.port'],
        'processor.danger' => [0 => 'danger.port'],
    ],
);
```

The graph keeps those two `Processor` instances separate through private `$this` calls and constructor-injected properties. Exact container lookups retain the selected service ID; an unbound construction does not borrow either service's constructor proof. The [service constructor fixture](tests/service-constructor-corpus) exercises this case. A compiled reference is needed for runtime service parity: parsing service YAML or PHP cannot establish all effective aliases, environment overrides and argument values. The caller must regenerate and verify that reference for the analyzed revision; this extension does not compile or execute the application container.

## Explicit family entrances and reusable graph scopes

Module families are independent of layer names. Give related modules the same
`module_family` and capture names to isolate their instances across layers.
Existing policies keep their current behavior: a caller without a family cannot
import a family target unless an explicit entrance grant is configured.

A caller outside a family can be allowed to import its public entrypoints:

```json
{
  "id": "lobby",
  "path_pattern": "src/Lobby/",
  "namespace_prefix": "App\\Lobby",
  "layer": "lobby",
  "allowed_target_layers": ["gate"],
  "entrypoint_suffix": "",
  "allowed_family_entrypoints": [
    {"target_family": "workshop", "target_layer": "gate"}
  ]
}
```

Add this source module alongside the target family modules, which retain their
`entrypoint_suffix` and optional `entrypoint_path_pattern`. Each grant applies to
any instance of the named family in exactly the named layer, and only to its
configured entrypoint classes. It does not expose internal classes, other layers,
or change `forbid_peer_entrypoints`. A source with its own `module_family` may
also have an explicit entrance grant, including a narrowly configured shared-data
exception within its family. Without a grant, distinct owners remain isolated.
Existing `allowed_family_bindings` restrict cross-family access by equal named
source and target captures; use these for a link tied to one target owner rather
than an entrance into arbitrary public instances. The target layer must also be in
`allowed_target_layers`, and the named family/layer must have an active module.
The list is limited to 64 grants and rejects unknown fields or targets.

Mark a target module with `"external_entrypoints_only": true` to reject
references from its own family and owner to its configured entrypoint classes.
This flag works across layers and is independent of directory names. It requires
a module family. Public data-contract modules can keep the flag unset, so their
own instance may use its records, enums and interfaces while peer instances stay
blocked unless an explicit shared-data exception is configured. An entrance
grant or matching same-family capture binding cannot override the own-instance
entrypoint guard. Entrances from other
families and capture-bound links still follow their explicit permissions.

For example, configure a portal class as one module with this flag and its public
data contracts as a separate module without it. Internal callers can use their
own data contracts but cannot call back into their portal. Public modules retain
family captures so cross-instance data dependencies can still be rejected.

Call-graph scopes can select repeated directories using `path_pattern` instead of
`path_prefix`. Each `*` matches one nonempty path segment; a trailing `/` includes
that directory's descendants. `path_captures` optionally names the wildcard
segments in order, with at most 16 unique names. Use one selector per scope;
literal `path_prefix` rules retain their previous semantics. Scopes stay ordered;
the first matching path and namespace selects the scope. Namespace prefixes
remain literal and must match as well.

The following lists belong inside the existing `scope_graph` configuration:

```json
{
  "scopes": [
    {
      "id": "steps",
      "path_pattern": "src/Workshop/*/Steps/",
      "path_captures": ["owner"],
      "namespace_prefix": "App\\Workshop"
    }
  ],
  "method_permissions": [
    {
      "scope_id": "steps",
      "target_type_pattern": "App\\Workshop\\*\\Tools\\EntryTool",
      "method": "ping",
      "decision": "deny",
      "policy_id": "foreign-tool",
      "rationale": "Tools of another workshop are unavailable"
    },
    {
      "scope_id": "steps",
      "target_type_pattern": "App\\Workshop\\{owner}\\Tools\\EntryTool",
      "method": "ping",
      "decision": "allow",
      "policy_id": "own-tool",
      "rationale": "The entry may use its own workshop tool"
    }
  ]
}
```

Choose either the existing literal `target_type` or `target_type_pattern`.
A type pattern's `*` matches one namespace segment; `{owner}` binds one segment
from the original scope entry path and remains fixed throughout transitive
traversal. Bindings are literal PHP namespace identifiers, not regular expressions.
Unknown capture names, partial wildcards, recursive `**`, unsafe paths and
ambiguous selector shapes are rejected. Method-specific rules take precedence;
then more literal namespace segments, then more bound segments win. A denial
wins equal specificity, independently of list order. Pair a bound allowance with
an explicit wildcard denial to reject other instances. Patterns are limited to
2048 bytes and 16 path wildcards. Bound graph proofs include `scope_captures` alongside the existing
source-to-target call edges; literal graph proof fields are unchanged.

The [fictional instance fixture](tests/instance-corpus/policy.json) demonstrates
public entrances, inaccessible internals, peer restrictions, independent owners,
and direct/transitive isolation using the actual Mago worker:

```sh
sh tests/instance-smoke.sh
```

## Check literal class dependencies

Boundary rules check literal class dependencies from the complete analyzed file
snapshot by default, including references without imports in the same namespace.
The top-level policy field `"dependency_mode": "class-references"` explicitly
selects this default. To retain the historical import-only behavior, set
`"dependency_mode": "imports"`; that mode cannot detect unimported class types.
Existing policies that omit this field can therefore produce additional boundary
findings after upgrading. For example, `__construct(PeerTask $peer)` now checks
`PeerTask` even when both classes share a namespace and no `use` is needed.
This mode resolves import aliases and grouped class imports, property and
parameter types, return types, nullable/union/intersection types, inheritance,
interfaces, traits, attributes, catch types, construction, static member access,
`instanceof`, and class constants. It reports each resolved target once per
source namespace/file, so importing a class and using it does not duplicate a
boundary finding. References to a class from that same class (for example, a
factory returning its own type) are not incoming dependencies. Function/constant imports, calls, and arbitrary strings are
not mistaken for classes.

No source is executed. Dynamic class names, reflection strings, PHPDoc-only
references and runtime service resolution are outside this literal dependency
mode. Known class names and declaration paths come from Mago's codebase snapshot;
undeclared targets use the existing configured source-root/namespace-root mapping;
it does not claim parity with another dependency analyzer's collectors. Each
file is limited to 8 MiB and 100,000 literal references. Parse failures and
exceeded limits produce `architecture-reference-incomplete` errors, rather than
a clean result with partial reference coverage. The module policy's namespace,
path, exclusions, owner bindings and entrypoint rules remain in force.

The [neutral requirements fixture](tests/requirements-corpus/policy.json) covers
cross-family public entrances, capture-bound links, peer isolation, shared-data
exceptions and internal entrypoint restrictions. It uses repeated cells, mixes,
links, routes, bundles, parcels and screens with no concrete owner names in the
policy. Run the same acceptance matrix against imports, resolved class references,
and generated fully qualified references without ordinary import aliases:

```sh
sh tests/requirements-smoke.sh
```

The [same-namespace fixture](tests/namespace-corpus/policy.json) checks ten peer
dependencies without `use` imports, including promoted constructor types,
nullable/union types, construction, class constants and namespace-relative names.
Declaration paths are taken from the codebase even when namespace and directory
do not mirror each other. Self references and allowed data types remain accepted.
Run `sh tests/namespace-smoke.sh` to compare default, explicit class-reference and
legacy import-only behavior.

## Read the results

Boundary violations appear as Analyzer issues such as `foreign-module-instance` and `forbidden-internal-access`. Graph permissions produce `scope-forbidden-entrypoint-method` errors or `scope-allowed-entrypoint-method` notes. The `graph-evidence` note contains the shortest modeled path, policy ID, target and `complete` flag. `scope-graph-incomplete` means a reached part could not be proven; do not interpret missing denials as a clean graph when it appears. Reachable unproven recursion is `recursive-cycle`; a narrow guarded integer self-call with a positive literal decrement is classified as `bounded-recursion`.

When the graph is enabled and at least one PHP source is in its configured root, the Analyzer also emits one `analysis-attestation` note. Its bounded `extension-attestation` payload identifies the extension, version, `scope_graph` capability, source-file count and completeness. Proofs exceeding the 4096-byte evidence limit are omitted and make the whole graph attestation incomplete. Consumers that require graph coverage should require this note; its absence must not count as a clean run.

This is static evidence, not a runtime trace. It does not replace Mago's parse diagnostics or prove all PHP execution paths. Benchmark the full `mago analyze` run on your own project before setting a CI time budget.

## Export a source call graph

Editor integrations can use the inspection API without a boundary policy:

```php
$extension = ArchitectureGraphExtension::inspect(
    projectRoot: $root,
    entrypointPaths: ['src/Controller', 'src/Command'],
    graphObserver: static function (array $snapshot): void {
        file_put_contents('/tmp/call-graph.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
    },
    classBindings: $map->classBindings(),
    serviceConfigurationComplete: $map->incomplete === [],
    serviceClassBindings: $map->serviceClassBindings(),
    serviceConstructorBindings: $constructorBindings,
);
(new Worker($extension))->run();
```

The observer receives `schema_version: "1"`, `capability: "full_source_call_graph"`, completeness, source-file count, nodes, actual invocation edges, unresolved reasons and the traversal depth limit. Node IDs preserve distinct constructor service instances. Node and call-site paths are project-relative; lines and columns are one-based. Entry paths select configured starting methods; they do not assert Symfony routes or runtime execution. The graph covers the complete source files supplied by Mago, across directories and namespaces; included vendor metadata is not treated as application source.

Inspection emits no boundary or permission findings. Injection alone is not a call edge. Dynamic dispatch, missing source targets, duplicate declarations and unproven receivers remain incomplete. The export is bounded to 50,000 nodes and 250,000 edges; exceeding either limit fails the analysis rather than exporting a silently partial graph. Consumers should write to a sidecar file: worker stdout carries the SDK protocol.

An existing explicit policy can instead pass a trailing `graphObserver` closure to `create()`. That export respects its source root, namespace, exclusions and configured scopes, but includes callers independently of allowed/denied method permissions. Existing policy enforcement remains unchanged.

## Develop

```sh
composer install
sh tests/smoke.sh
sh tests/inspection.sh
```

The fictional corpora cover allowed, denied and incomplete paths through a real Mago worker. Licensed under [MIT](LICENSE).


## Declaration comment metadata

`inspect()` and `create()` accept an optional final named `commentMarkers` argument.
A `null` value uses the defaults; an empty list disables extraction. For example:

```php
ArchitectureGraphExtension::inspect(
    $root,
    ['src/Controller'],
    $observer,
    commentMarkers: [
        ['marker' => '[DEV COMMENT]', 'severity' => 'info'],
        ['marker' => '@deprecated', 'severity' => 'warning'],
        ['marker' => '@todo', 'severity' => 'info'],
        ['marker' => '@see', 'severity' => 'reference'],
    ],
);
```

Markers are case-sensitive literal text. The reader uses comments attached to named
classes, interfaces, traits, enums and methods in the parsed source, never string
literals. Consecutive `//` comments and `/** ... */` blocks support continuation
lines until a blank line, another configured marker, an `@` tag or comment end.
Leading comment delimiters and `*` are removed. Marker text may be empty (for
example bare `@deprecated`). `@see` targets are preserved as reference text; the
extension does not claim they are resolved symbols.

Full-source snapshots retain schema version `1` and the existing `nodes` and
`edges`. They add `symbol_metadata` and `annotation_sites`, plus
`comment_column_encoding: "utf8_bytes"`. Positions are one-based UTF-8 byte
columns; `end_column` is exclusive. Each declaration has a symbol, kind, source
range and annotations containing marker, severity, message and source location.
Sites additionally identify `target_symbol` and kind (`declaration`,
`implementation`, `call`, `new`, `extends`, `implements`, `type`).

An interface type comment appears on an implementing class name. An interface
method comment appears on the matching implementation method and proven direct
call sites to that method. Unrelated methods and transitive callers are not
annotated. Class/type references are resolved through PHP namespace/import
resolution; uncertain calls retain the existing conservative call graph behavior.
Duplicate symbol declarations suppress their metadata. Extraction is bounded to
32 markers, 64 annotations per declaration, 4096 message bytes, 50000 symbols and
250000 reference/call sites. These annotations never affect architecture policy
findings.
