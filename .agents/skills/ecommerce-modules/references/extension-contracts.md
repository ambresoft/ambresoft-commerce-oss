# Laravel-native extension contracts

Status: proposed design guidance. Do not infer a manifest format, registry API, namespace topology or third-party distribution method from this document.

Laravel's provider/package facilities support registration of configuration, routes, translations, migrations and assets. Use them as the integration foundation; they do not supply this CMS's merchant activation policy. [Laravel package development](https://laravel.com/docs/13.x/packages).

## Package capabilities

| Capability | Integration guidance |
| --- | --- |
| Manifest | Identify the module, version, core/runtime compatibility, dependencies and provider; choose exact fields/format during foundation design |
| Service provider | Register container bindings and permitted contributions; keep boot deterministic and free of installation/migration side effects |
| Configuration | Keep deploy-time defaults/configuration separate from validated merchant-editable settings |
| Migrations / models | Own module persistence explicitly; reference core identities through supported relationships and foreign keys |
| Services | Execute cohesive optional commerce operations; call core application contracts rather than copying price/stock rules |
| Admin / storefront controllers | Coordinate separate transport concerns and reuse services; do not replace core controllers to inject a feature |
| Routes | Namespace route names and choose prefixes; apply appropriate middleware and policy checks; account for disabled state and route caches |
| Permissions | Declare stable namespaced abilities and enforce them on the server; decide role assignment through the chosen permission model |
| Settings | Validate type, defaults, scope and secret handling; do not turn settings into a substitute for relational business entities |
| Translations | Namespace keys through Laravel's translation facilities; keep merchant data localization a separate decision |
| Assets | Package build inputs/output intentionally; isolate names/styles and decide discovery before promising dynamic loading |
| Events / hooks | Publish documented payloads and behavior at meaningful commerce boundaries |

Do not require every capability in a trivial module. An optional integration might need a service and job without new controllers or tables. Module migrations may own extension tables referencing core records; extending core columns requires a deliberate compatibility and data-ownership decision.

## Choose the extension mechanism

An event announces an occurrence. Specify whether listeners run synchronously or on a queue, transaction/commit timing, failure behavior and payload stability. External reactions to committed business data should not observe an operation that later rolls back. Essential atomic operations still need explicit orchestration.

A synchronous hook/contract intentionally changes or validates a decision. State typed inputs and outputs, allowed transformations, deterministic ordering/composition, veto/error behavior and transaction timing. A future pricing adjustment, for example, needs a defined monetary result and precedence rather than listeners mutating an untyped array. This example does not authorize a pricing engine.

A strategy binding is suitable when an operation needs a replaceable implementation. Do not make every service an interface. A focused query contribution may be appropriate for addon data, but unrestricted raw query mutation should not be the public contract.

For every extension, state the owning domain, authorized callers, invariant protection and versioning expectations. Keep authorization decisions authoritative; an addon UI capability is not a grant of permission. Third-party PHP code executes with application privileges, so package trust is an installation decision, not a sandbox guarantee.

## Admin and storefront contributions

Prefer documented positions such as navigation entries, a detail section or a form section over whole-page replacement. Define typed props, namespaced contribution IDs, ordering and permitted actions. Validate module fields on the server and save them through an explicit operation; rendering extra controls alone is insufficient.

Choose component/asset discovery during build design. Do not assume React components can be uploaded and executed safely at runtime. Keep storefront positions and theme contracts open until that phase.
