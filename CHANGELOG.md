# Changelog

## Unreleased

- Add an operator-selected native trace file so recursion paths survive Mago worker stderr capture; preserve the shared engine adapter across all three extensions.

## 1.3.0-RC2 — pending validation

- Make Rust the default computational backend; require a compatible engine and remove automatic PHP grammar fallback.
- Install verified platform assets into one shared cache and provide offline/preinstall controls.
- Test Linux and both macOS architectures, default selection and complete reference-report parity.
- Add opt-in recursion traces and neutral delegation regressions; project-specific parity investigation remains pending.

## 1.3.0-RC1 — 2026-10-12

- Add an opt-in shared Rust computational backend selected by `MAGO_NATIVE_ENGINE`, retaining the version-matched PHP SDK adapter.
- Verify native strict mode and complete fixture-report parity in CI against the minimum and latest supported Mago versions.
- See `NATIVE_BACKEND.md` for migrated components, bounded transport, compatibility fallback and platform status.

## 1.2.0 — 2026-10-12

- Check literal class references by default, including constructor types and other references in the same namespace without imports. Policies can explicitly select `dependency_mode: imports` to preserve the historical behavior.
- Add real-Mago regression coverage for ten unimported peer-entrypoint dependencies, declaration-path resolution, duplicate references and allowed self/data dependencies.

## 1.1.0 — 2026-10-12

- Allow Mago >=1.50.0,<2.0.0 instead of restricting dependencies to the 1.50 minor series.
- Use a major-version pin in executable Mago configurations and test the supported API floor plus latest compatible 1.x in CI.

- Add explicit public-entrypoint grants from configured source modules without relaxing internal visibility, layer checks, peer restrictions, or owner isolation.
- Add target-side internal entrypoint restrictions independent of folder names, and narrowly configured shared-data entrance exceptions.
- Add optional resolved literal class dependencies beyond imports, with alias/group/type support and explicit coverage errors on parse or size limits.
- Add wildcard graph scope paths and named captures, with namespace-segment target patterns bound to the original entry throughout transitive traversal.
- Resolve graph permission specificity deterministically and retain capture bindings in graph evidence.
- Verify positive and negative instance rules, unsafe configurations, and repeated real Mago reports using fictional workshop fixtures.

## 1.0.0 — 2026-10-12

- Publish the existing analyzer and inspection APIs as the first stable release.
- Set extension and analysis-attestation version metadata to `1.0.0`.
- Update installation instructions to use the stable package version.
- Preserve conservative analysis, explicit incomplete evidence, and existing bounds.

## 0.1.0-beta.17

- Add reusable configured declaration comment metadata and exact direct-use annotation sites to full-source graph exports.
- Default markers: `[DEV COMMENT]`, `@deprecated`, `@todo`, and `@see`; configure marker severity or disable extraction.
- Distinguish interface type notes on implementing class names from interface method notes on implementation signatures and resolved direct calls.
- Preserve the existing graph schema and policy behavior; bound extraction, reject ambiguous declarations, and expose UTF-8 byte column encoding.

## 0.1.0-beta.16

- Add `ArchitectureGraphExtension::inspect()` for complete Mago source call graphs without boundary-policy enforcement.
- Export one-based source locations, service-instance identities, configured entry scopes, actual call edges and explicit incomplete coverage through a sidecar observer.
- Add an optional graph observer to `create()`; graph exports are independent of method permission findings.
- Bound exports to 50,000 nodes and 250,000 edges, and verify arbitrary source directories/namespaces and injection-only exclusion using a real Mago worker.
- Preserve existing policy validation and enforcement semantics.
