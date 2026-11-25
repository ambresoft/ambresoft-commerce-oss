# Events, synchronous extensions and jobs

| Mechanism                 | Purpose                                             | Rules                                                                                        |
| ------------------------- | --------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| Domain/application event  | Announce an immutable meaningful fact               | Past tense; typed payload; document commit timing and version compatibility                  |
| Synchronous hook/strategy | Intentionally participate in validation/calculation | Narrow typed inputs/output, invariant checks, deterministic composition and failure behavior |
| Job                       | Retryable asynchronous work                         | Stable IDs, idempotency, bounded retries/backoff/timeouts and post-commit dispatch           |

Future examples: App\\Catalog\\Domain\\Event\\ProductCreated, ProductUpdated; App\\Orders\\Domain\\Event\\OrderCreated; App\\Payments\\Domain\\Event\\PaymentCaptured. These remain concepts, not generated classes. Phase 3 implements pure Catalog CategoryCreated/Updated/Moved/Deleted facts for actual consuming operations. Events represent successful business operations, not every model save. An import/bulk edit uses the same Application operation and semantics. Domain event types/payloads are pure PHP. Laravel authentication events remain supported contracts emitted by Infrastructure adapters (A29), not imports into Domain/Application.

Publish committed facts using Laravel after-commit event/job facilities. Internal invariants belong in the explicit service transaction, not in optional listeners required for correctness. Define payload IDs, relevant immutable values, occurrence time and correlation ID; avoid whole mutable Eloquent graphs/secrets. Listeners that fail after commit cannot roll back the completed business operation. External exactly-once delivery is not promised; assess an outbox only when required.

A future price adjustment hook must specify currency/exact result, priority and rounding; an authorization hook must never weaken mandatory core access rules. Sort extensions by explicit priority then namespaced ID, reject duplicate IDs, propagate validation veto/failure before commit and prohibit irreversible remote effects inside atomic SQL work. Publish such a hook only with a real consuming operation; no generic hook engine in Phase 0.

UI slots will carry typed minimal props and namespaced IDs, server-validated contributions and explicit positions. Admin and storefront have different slot contracts. Do not grant arbitrary core query mutation, whole-controller replacement or source editing as an extension mechanism.

Phase 3 consumes a narrow CategoryMutationParticipant contract for before-parent-use and before-delete SQL participation, with deterministic namespaced registration. Future core Product reassignment must complete synchronously inside deletion's transaction, not via a post-commit CategoryDeleted listener. Optional participants cannot weaken mandatory category/system/permission invariants. [Contract and payloads](module-system.md#phase-3-catalog-extension-points), [category operations](categories.md).
