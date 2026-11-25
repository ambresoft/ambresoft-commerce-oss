# Implementation workflow

Use this workflow for an authorized slice, not as permission to implement the roadmap. During the initial knowledge task, limit changes to skills, references and architecture documents.

## Inspect and scope

Read root/nested AGENTS instructions and the relevant architecture documents. Inspect existing routes, controllers, requests, policies, services, models, pages and tests related to the slice. Check dependency manifests and actual runnable commands. In an empty repository, report absence rather than inventing scripts or architectural precedent.

State the observable result, authorized phase and necessary invariants. Check the decision register for choices that affect the slice. Make routine local choices consistent with existing conventions; resolve substantive open product/architecture questions before encoding them. Do not require decisions for unrelated later phases.

Choose specialized guidance by affected responsibility: architecture for cross-layer boundaries, domain for meanings, database for persistence, modules for optional extensions and admin for page interactions. Read only relevant references.

## Implement when authorized

Keep the change coherent and bounded. Start with the server operation and its authorization/validation contract, use deliberate persistence and expose the required admin interaction. Avoid generating unused interfaces, repositories, services or later-phase tables.

For architecture refactors, keep routes/schema/security behavior stable unless explicitly authorized otherwise. Move imports, auth provider configuration and container bindings together with classes; refresh optimized autoload/caches and test direct use cases plus existing HTTP regressions. Preserve local accounts and databases. Follow A29's context-first, framework-free Domain/Application convention rather than historical Phase 0 or superseded A28 examples. Update record mappings and auth provider wiring together; a plain Domain User must not be passed to Laravel session login.

Reuse domain/application operations from each entry point; imports and jobs should not become alternate sources of business rules. Preserve established code and unrelated user changes. Update the decision register when making a lasting architectural choice.

## Verify meaningful behavior

| Change                 | Useful evidence                                                                                  |
| ---------------------- | ------------------------------------------------------------------------------------------------ |
| Request / policy       | Authorized success, denied access, invalid input and correctly scoped records                    |
| Multi-record operation | Invariants, transaction failure behavior and relevant concurrency/conflict handling              |
| PostgreSQL persistence | Actual constraints, relationship ownership and migration/backfill behavior on PostgreSQL         |
| Inertia page / form    | Correct projected props, server errors, mutation feedback and preserved values                   |
| React behavior         | Relevant Vitest interaction checks plus visual/keyboard review                                   |
| Module lifecycle       | Applicable dependency, activation, recovery, cache/worker and retention behavior                 |
| Documentation / skills | Official skill validator, valid links, complete reference routing and scope/decision consistency |

Use the selected PHPUnit/Pest framework once established; do not install both by default. Run the actual project's type/lint/build commands where relevant. Keep checks proportionate: do not write tests that only mirror wording or implementation, or fabricate a test suite for a documentation-only repository. Report unavailable infrastructure and what could not be verified.

## Handoff

Report what changed, observable verification and material limits. Update specialized guidance when real behavior changes, keeping project decisions in the architecture documents and reusable procedures in references. Do not add broad rules from a one-off failure.

The initial skill system uses skill-creator's existing `scripts/quick_validate.py` against each skill directory. This checks frontmatter/naming/scaffold issues, not architectural quality; separately inspect descriptions, links, research coverage and decision status. No custom project automation is necessary for this baseline.
