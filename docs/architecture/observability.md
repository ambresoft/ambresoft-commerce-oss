# Observability and auditing

Start with Laravel structured/contextual logs to stderr in containers and normal exception handling. Record request/job correlation IDs when a real cross-operation workflow needs them; do not add tracing middleware/platform only to fill a diagram. /up is liveness, not a claim that PostgreSQL/Redis are healthy.

Log operation, actor/target identifiers where lawful, outcome, duration and safe diagnostic context. Redact passwords, tokens, cookies, payment details, sensitive customer fields and uploaded content. Production shows generic errors; exceptions retain protected diagnostic details with bounded retention.

Application logs diagnose execution. Future audit history records important authorized admin actions (role changes, catalog publication, stock/price adjustments, module lifecycle) explicitly with actor/action/target/time/outcome and minimal redacted change summary. Define retention, access/export/privacy before an audit table/service. Do not mirror every model change or retain personal information indefinitely.

Redis jobs use bounded retries/backoff/timeout and idempotency for external effects; failed_jobs persists terminal failures. Inspect with queue:failed; retry only after diagnosis, never blindly replay payment-like effects. Queue payloads/exceptions can contain sensitive data: restrict access and schedule retention/pruning when workers are operational. No jobs or audit entities are fabricated now. External monitoring, event sourcing and an outbox remain need-driven future decisions.

The explicitly authorized central ledger now implements persistent activity history separately from diagnostics. See [audit logging](audit-logging.md) for the API, verified coverage, append-only protections, failure handling, privacy and retention. Earlier future-audit wording above describes the foundation baseline.
