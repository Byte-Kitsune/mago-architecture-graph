# Changelog

## 0.1.0-beta.16

- Add `ArchitectureGraphExtension::inspect()` for complete Mago source call graphs without boundary-policy enforcement.
- Export one-based source locations, service-instance identities, configured entry scopes, actual call edges and explicit incomplete coverage through a sidecar observer.
- Add an optional graph observer to `create()`; graph exports are independent of method permission findings.
- Bound exports to 50,000 nodes and 250,000 edges, and verify arbitrary source directories/namespaces and injection-only exclusion using a real Mago worker.
- Preserve existing policy validation and enforcement semantics.
