# Module lifecycle design guidance

Status: proposed conventions for future implementation. The brief requires lifecycle support; exact state storage, discovery and package format are open in `docs/architecture/decisions.md`.

## Distinguish states and operations

| Operation | Intended effect | Data / runtime concern |
| --- | --- | --- |
| Discover | Recognize available module metadata | Do not execute installation work while listing candidates |
| Install | Validate compatibility/dependencies and establish owned schema/settings/registrations | Track installed version and progress; do not conflate availability with enabled state |
| Enable | Activate approved runtime contributions | Require completed installation; refresh applicable caches/workers |
| Disable | Stop new contributions without removing business data | Define in-flight jobs/requests and dependency behavior |
| Upgrade | Move installed code/data contracts to a compatible version | Run tracked pending migrations explicitly; plan failure recovery |
| Uninstall | Remove runtime integration and resolve owned resources | Decide retain versus purge before deleting anything |

The intended conceptual path is available → installed → enabled, with enable/disable reversible around installed state. These are concepts, not a mandatory enum or a finalized state machine. Failure/in-progress states may be needed once execution mechanics are chosen.

## Installation and upgrades

- Validate stable module identity, supported core/runtime versions, missing dependencies and conflicts before mutation. Detect dependency cycles; choose a deterministic order.
- Keep boot/registration separate from lifecycle execution. Application startup and ordinary requests must not silently install or migrate modules.
- Attribute migrations and installation records to their owner. Repeated attempts should resume or safely detect completed steps rather than duplicate schema, settings or permissions.
- Use transactions for steps PostgreSQL can make atomic. Do not claim files, remote calls, assets, cache updates and database writes share one rollback boundary.
- Define recovery for partial work. Do not use uninstall as automatic rollback if it might delete preexisting merchant data.
- Keep published contribution contracts compatible during upgrades, or explicitly declare incompatibility. Do not build a general version solver beyond the actual package strategy.

## Activation and disabling

Do not confuse Laravel provider discovery with merchant activation. Decide which metadata can be available while disabled and how active routes, listeners, schedules, permissions, navigation and assets are gated. Account for route/config caches and long-running queue workers; changing a database flag alone may not refresh them.

Check dependents before disabling a capability they require. Choose whether to refuse the transition or offer an explicit dependency plan; do not silently disable unrelated modules. Specify whether queued module work completes, is rejected or is deferred after disabling. Keep core operations valid when optional code is absent.

## Uninstall and data ownership

Define installed metadata, module-owned business tables, settings, media and external resources separately. Uninstall is not synonymous with reversing every migration. Do not delete core products, prices, stock or history merely because an addon referred to them.

Choose retention/export/purge policy before implementing uninstall, show concrete consequences and protect required dependents. Validate references from retained data before dropping a module table. Decide how reinstall reconnects retained data and migration history. These are decision criteria, not a chosen default purge policy.

## Verification when implemented

Cover install twice, enable/disable repeatedly, upgrade from a prior supported version, missing/incompatible/cyclic dependencies, failed migration recovery, disabled routes/jobs and retained/purged data behavior. Add only cases relevant to the actual contract. No lifecycle automation, loader or migration is part of this documentation task.

