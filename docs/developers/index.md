---
title: Developer guide
nav_order: 6
has_children: true
permalink: /developers/
---

# Developer guide

This part is for developers who run, extend or contribute to Ambresoft Commerce.

| Page | What you learn |
| --- | --- |
| [Architecture](architecture/) | The modular monolith, context-first DDD, request flow and the rules every change follows |
| [Project structure](project-structure/) | Where everything lives in the repository |
| [Local development](local-development/) | Daily commands, quality checks, tests, logs and debugging |
| [FrankenPHP](frankenphp/) | The application server: how it is configured and why |
| [PostgreSQL](postgresql/) | The database, its local and test databases, and the integrity rules |
| [Redis, queues and the scheduler](redis-queues-scheduler/) | Sessions, cache, the storefront content cache, background jobs and scheduled tasks |
| [Search with Typesense](typesense/) | How product search is indexed, kept current and secured |
| [Frontend](frontend/) | Inertia, React, TypeScript and Tailwind: admin and storefront pages and shared components |
| [Localization](localization/) | Interface translations versus merchant content translations |
| [Authorization](authorization/) | Permissions, roles, gates and how to protect a new screen |
| [Creating an add-on](creating-an-addon/) | A step-by-step tutorial that builds and installs an add-on |
| [Add-on reference](addon-reference/) | The manifest, every extension point, lifecycle, packaging and limits |
| [Testing](testing/) | PHPUnit, Vitest, the architecture test and the isolated test database |

## Technology at a glance

| Layer | Technology |
| --- | --- |
| Language and framework | PHP 8.3+ (8.5 in Docker), Laravel 13 |
| Application server | FrankenPHP 1.14 (Caddy with embedded PHP), classic mode |
| Database | PostgreSQL 18 |
| Cache, sessions, queues | Redis 7.4 |
| Search | Typesense 30.2 through the bundled `ambresoft/typesense` add-on |
| Frontend | Inertia 3, React 19, TypeScript, Tailwind CSS 4, built with Vite |
| Quality | PHPUnit, Pint, PHPStan/Larastan (level 6), Vitest with Testing Library, ESLint, Prettier, `tsc` |
| Local tooling | Docker Compose, Mailpit, optional pgAdmin |

## Ground rules

These rules apply to every change, in core and in add-ons:

- **Business rules live on the server.** Prices, stock, taxes, validation and permissions are decided by PHP and
  PostgreSQL, never by React.
- **Money is never a float.** Amounts are exact decimal strings (`App\Shared\Domain\Service\Decimal`).
- **Permissions are checked on the server** for every page, action and bulk target. Hiding a button is not security.
- **Interface text is translated by key**; merchant content is translated per language in its own translation tables,
  never in language-specific columns.
- **No microservices, no API-first rewrite, no blanket repository layer.** Keep the modular monolith.
- **Add-ons extend through published contracts**, never by patching core files.
