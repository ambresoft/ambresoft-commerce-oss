# FAQ administration (A83)

Website owns reusable questions and answers. Website → FAQs manages one Q&A per record, with General, Category or Product type, Active/Disabled status and list position. Every active language needs a question (500 characters) and a sanitized rich-text answer (20,000 characters). Saved inactive translations remain intact. Editors use optimistic versions and the dashboard's shared UI components.

## Linking and integrity

Categories, products and CMS pages have an FAQs tab. General FAQs can be linked to any of these; Category FAQs only to categories and Product FAQs only to products. A typed FAQ can be reused across multiple objects of its supported kind. There is no implicit category inheritance. Page links accept General FAQs.

Links are ordered, limited to 100 per object, and saved atomically with the owner's existing authorized/versioned edit. Legacy saves that omit FAQ fields preserve links. New disabled links are rejected, while existing disabled links can be retained or removed. Product duplication copies references, not FAQ content. Linked FAQs cannot change type or be deleted; disabling remains available. Owner deletion removes its links. PostgreSQL composite foreign keys and checks independently enforce type suitability and restrictive FAQ deletion.

The Catalog transaction lock coordinates linking, content/type changes and owner saves. FAQ content, translations and link changes participate in the central transactional audit ledger. The list shows usage counts. Storefront blocks, public FAQ rendering, description/tab modes, schema-only output and JSON-LD remain the later storefront phase requested by the user.

## Permissions

All routes require verified staff administration access. `faqs.view` permits catalog/editor/picker reads and linking, alongside the owner's existing update permission. `faqs.manage` permits create/update/delete and saving reviewed new drafts. `faqs.generate` plus `faqs.manage` permits generation; an object source additionally requires the object's read capability, or create capability for a new object. `settings.manage` protects AI configuration. `ai.logs.view` independently protects AI call history.

AI Log is under Logs alongside Audit Logs, also linked from the General settings AI tab. If a staff member has log access without General settings access, the log remains reachable under Logs. Sidebar visibility does not authorize routes. Ordinary permission synchronization adds definitions without widening role grants.

## Optional Claude generation

[Shared AI settings and history](ai-settings.md) are owned by Settings. FAQs consume that configuration; the credential, budget and history can be reused by later authorized AI features.

Manual operation needs no API key. Shared AI configuration belongs to Settings and starts disabled, with a write-only merchant-owned Anthropic key encrypted using Laravel's application key. Blank key input preserves it; explicit removal clears it. Keys never enter Inertia props, old-input session flashes or audit field values. A credential-change event records only whether a key is configured. Preserve the installation's application key when deploying.

The server calls Anthropic's fixed HTTPS Messages endpoint with structured JSON output; users cannot supply an endpoint. Allowed models are Sonnet 4.6 and Haiku 4.5. See [Messages API](https://platform.claude.com/docs/en/api/messages/create) and [structured outputs](https://platform.claude.com/docs/en/build-with-claude/structured-outputs). Provider model availability may require a later allowlist update.

Generation uses the current unsaved description first, or saved localized source text when no text is supplied. Public company contact details provide additional context; internal notification emails are excluded. Source descriptions are converted to plain text and bounded to 12,000 characters. The prompt treats source material as untrusted reference data, prohibits fabricated policies/specifications, and makes no claim of live online research.

Auto language generates matching translations in all active content languages. A specific active language can also be chosen; missing other active translations must be completed before adding a draft. English and Greek work through the same locale-driven contract as other active languages.

The native dashboard modal supports selection, inline editing and per-item regeneration. Previewing never persists FAQ content. Adding queues reviewed drafts in the owning form; a successful owner save creates and links them in the same transaction. Invalid drafts roll back the whole save. A master FAQ editor requests a single draft. Owners can queue up to 10 new drafts.

Defaults: 50 provider calls per user per hour, 3,000 maximum output tokens per call. Configuration permits 1–1,000 calls/hour and 256–8,192 tokens. Regeneration and failed calls consume the same allowance. A shared-cache per-user lock permits only one in-flight call; production uses the configured Redis cache. Connect/overall timeouts are 5/45 seconds, redirects and automatic retries are disabled. Provider failures, refusals, truncation and malformed outputs produce a safe localized error, never partial FAQ writes.

The shared AI log stores the originating feature as well as staff identity, source kind/ID snapshot, model, timestamp, status, HTTP status, safe error code, duration and reported input/output tokens. Source snapshots intentionally survive owner deletion. Prompts, responses and credentials are not logged. Real provider generation is unverified without a merchant API key; tests fake all network calls and cover error/rate-limit behavior.

## Setup and samples

Run migrations, ordinary `cms:permissions-sync`, and `pnpm build`; assign permissions through Roles. No sample content or credentials are created by migrations. AI settings live in Settings → General → AI settings, linked from the FAQ list and generation preview.

`php artisan cms:faqs-demo USER_ID` explicitly creates four labeled samples: two General, one Category and one Product, translated into each active supported language. It refuses production, uses stable demo keys, creates no links and preserves subsequent edits/status changes. If a mapped record was deleted it fails rather than replacing it.

Feature/unit tests cover translation completeness, sanitization, versions, transactional audit, relational restrictions, owner linking/duplication, draft rollback, independent permissions, sample idempotence, encrypted credentials, structured mocked calls, limits and failure scrubbing. Frontend tests cover native forms, typed ordered pickers and editable/selectable generation previews.
