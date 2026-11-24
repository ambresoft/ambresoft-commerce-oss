---
name: ecommerce-modules
description: Design or review Laravel-native ecommerce addons, their manifests, providers, lifecycle and extension contracts. Use for installation, activation, dependencies, hooks, events, module routes, permissions, settings and assets; do not use addon packaging to move essential ecommerce concepts out of core.
---

# Ecommerce modules

Read [module-system.md](../../../docs/architecture/module-system.md) for scope and decision status.

- Classify the capability as essential core or optional addon before designing its package. Do not make every entity an addon.
- Integrate modules through Laravel providers, the container, routes, policies, migrations, translations and events. Keep business behavior in module services, with separate admin and eventual storefront controllers.
- Preserve the selected Composer extra.cms-module contract and explicit activation configuration in the module-system document. Distinguish deploy-time code configuration from merchant-editable settings; do not redesign discovery implicitly.
- Extend published core contracts rather than editing core files, replacing controllers wholesale or relying on Eloquent model observers to bypass application invariants.
- Follow [context-first DDD](../../../docs/architecture/layered-ddd.md): call published context Application operations/Domain contracts, not controllers or concrete persistence adapters. Keep business Domain/Application framework-free; locate the host loader in Shared\\Infrastructure\\Modules. Preserve its manifest/lifecycle behavior and the existing AdminNavigation API.
- Treat installation, enabling, disabling, upgrading and uninstalling as different operations. Never run migrations merely because a provider boots.
- Require server authorization for module actions even when navigation or components are hidden. Namespace registrations to avoid collisions.
- Define hook inputs, outputs, ordering, failure behavior and transaction timing before publishing a hook. Use events to announce facts, not to collect a mutable price or authorization decision.

Read [lifecycle.md](references/lifecycle.md) for state transitions, dependencies, migration/data ownership and recovery. Read [extension-contracts.md](references/extension-contracts.md) for manifests, settings, permissions, routes, events, hooks and UI integration. Keep packaging, trust/distribution and uninstall retention choices in the project decision register until resolved.
