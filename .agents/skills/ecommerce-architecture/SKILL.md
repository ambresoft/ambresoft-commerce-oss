---
name: ecommerce-architecture
description: Define or review this CMS's context-first DDD modular monolith, pure Domain/Application layers, Laravel adapters and core versus addon ownership. Use for architecture refactors or changes spanning layers; use domain, database, modules or admin skills for specialized details.
---

# Ecommerce architecture

Read the project [overview](../../../docs/architecture/overview.md), [decisions](../../../docs/architecture/decisions.md) and [layered DDD conventions](../../../docs/architecture/layered-ddd.md) before changing boundaries. Treat stack preferences as direction, not installed dependencies.

- Keep Laravel responsible for routing, validation, authorization and business operations. Use Inertia to deliver typed React pages from controllers.
- Organize app/{Context}/Domain, Application, Infrastructure and Presentation. Use PascalCase with singular Model/Repository/Command/Query/Service categories and HTTP Controllers/Requests. Keep Admin a cross-context presentation interface, not a business domain; Core is essential ownership, not a namespace.
- Let controllers coordinate requests, form requests validate input, policies authorize operations, Eloquent persist data, and focused services execute substantial business operations. Keep simple CRUD simple.
- Share business operations between admin, eventual storefront, imports and jobs; give admin and storefront distinct transport and presentation boundaries.
- Keep essential catalog, inventory and pricing semantics in core even when internally organized into modules. Make optional functionality removable without making core depend on it.
- Use meaningful events for notifications, jobs for asynchronous work and explicit extension contracts when behavior must be changed. Do not treat event listeners as implicit orchestration for atomic business operations.
- Keep Domain and Application plain PHP: no framework imports/helpers, Eloquent, HTTP, JSON transport or concrete adapter dependencies. Put needed repository interfaces in Domain/Repository and Eloquent records/mappers/implementations in Infrastructure/Persistence. Keep Laravel authentication events, notifications and password broker behind consumed Application ports. Never return Eloquent types from Domain interfaces.
- Use typed input/output for real handlers; Command/Query folders do not authorize a bus, separate read database or other CQRS machinery. Do not add empty Query/Service folders, generic mapping frameworks or future domain placeholders.
- Introduce query objects or repositories only for a demonstrated query or persistence boundary. Keep the existing registration repository and PostgreSQL failure translation; do not add generic CRUD repositories, service frameworks, CQRS or microservices speculatively.
- Preserve the accepted layer/folder convention. Leave unselected packages and product scope open until their relevant decision; record reasons and consequences rather than silently changing boundaries.

Read [decision-making.md](references/decision-making.md) when comparing alternatives or proposing an exception. This skill owns layering and ownership; do not redefine domain semantics or database conventions here.
