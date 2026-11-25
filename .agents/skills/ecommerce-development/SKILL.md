---
name: ecommerce-development
description: Implement and verify authorized work in this CMS phase by phase, inspecting existing architecture and choosing proportionate tests. Use when planning or carrying out implementation work, reviewing phase scope or handing off changes; the roadmap alone never authorizes future features.
---

# Ecommerce development

Read [development-phases.md](../../../docs/architecture/development-phases.md), [decisions.md](../../../docs/architecture/decisions.md) and [implementation-workflow.md](references/implementation-workflow.md) before implementation.

- Establish the deliverable and authorized phase from the current request. Keep later roadmap items out of the change unless explicitly requested or required for the authorized slice.
- Inspect repository instructions, existing code, dependencies and tests before proposing new structure. Preserve unrelated work.
- Follow [context-first DDD](../../../docs/architecture/layered-ddd.md) with pure Domain/Application under app/{Context} and PascalCase PHP namespaces; run framework-free unit, architecture and direct use-case integration tests when changing boundaries. Keep behavior-preserving refactors distinct from feature authorization.
- Load the specialized skill for the affected boundary; do not repeat its entire reference set. Resolve only decisions needed by this slice and record substantive choices.
- Implement the smallest coherent vertical slice when application work is authorized: server contract, business operation, persistence, admin UI and relevant verification. Do not create empty services, repositories or future-phase entities as scaffolding.
- Reuse the same business operation across entry points. Cover permissions, invalid input and important invariants as well as success behavior.
- Run commands supported by the actual repository. Use PHPUnit or Pest for backend behavior, PostgreSQL for database-specific semantics and Vitest for relevant frontend behavior. Do not invent passing checks or choose a testing framework silently.
- Update decisions and reusable knowledge when behavior changes. Report completed scope, verification and material limitations.

For the initial knowledge-system task, create and validate documentation and skills only. Do not bootstrap Laravel, install application dependencies, create migrations or implement any roadmap feature. Treat a later explicit implementation request as authorization for its stated scope, not for the entire roadmap.
