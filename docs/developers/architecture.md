---
title: Architecture
parent: Developer guide
nav_order: 1
---

# Architecture
{: .no_toc }

1. TOC
{:toc}

---

## A modular monolith

Ambresoft Commerce is **one Laravel application** (a monolith) whose code is split into **business contexts**
(modules). Each context owns its data and rules and talks to other contexts through small, explicit contracts.
There are no microservices and no separate API backend: pages are rendered by Laravel controllers and shown with
Inertia and React.

Optional features are **add-ons**: Composer-style packages loaded only while enabled. They use the same published
contracts as core code. See [Creating an add-on](../creating-an-addon/).

## Context-first DDD

Code is organized **by business context first, then by layer** (decision A29 in the project's decision register):

```text
app/{Context}/
  Domain/           plain PHP: entities, value objects, business rules, repository interfaces
  Application/      plain PHP: use cases, typed input objects (Command/), ports (Contract/), services
  Infrastructure/   Laravel and PostgreSQL: Eloquent records, mappers, adapters, service provider
  Presentation/     HTTP controllers and requests, console commands
```

The contexts that exist today:

| Context | Owns |
| --- | --- |
| `Identity` | User accounts, registration, sign-in, password reset, email verification |
| `Authorization` | Staff roles, permissions and access-safety rules |
| `Localization` | Languages, the default language, interface translation overrides |
| `Catalog` | Categories, products, features, variations, brands, reviews, availability, imports |
| `Pricing` | Currencies, exchange rates, formatting, product sales |
| `Tax` | Taxes, rate areas and tax calculation |
| `Customer` | Customer profiles, addresses, groups, blacklist, call requests, wishlists |
| `Geography` | Shipping countries and regions |
| `Cart` | Carts, including abandoned carts offered to add-ons |
| `Order` | Orders, statuses, order emails, invoice and packing-slip layouts |
| `Payment` | Payment methods (cash on delivery, bank deposit, add-on providers), payment attempts and webhooks |
| `Shipping` | Shipping methods, carriers, shipments |
| `Marketing` | Banners, promotions, coupons, gift cards, stickers, newsletters, reels |
| `Website` | Pages, blogs, FAQs, menus, SEO redirect rules |
| `Storefront` | Themes, layouts, grids and blocks, the public catalog, comparison, SEO output, the storefront cache |
| `Search` | The search extension point, indexing, visibility, popular searches |
| `Reporting` | Reports |
| `Audit` | The central audit log |
| `Media` | Private image and file storage with metadata |
| `Notification` | Live admin notifications |
| `Settings` | General and company settings, AI settings |
| `Shared` | Cross-context contracts and the add-on (module) system |

Not every context has every layer: a folder exists only when it holds real code. Two folders are deliberately **not**
contexts:

- `app/Admin`: the administration is a **presentation shell** that spans contexts (controllers, requests, navigation,
  dashboard). It is not a business domain.
- `app/Http`: global middleware and the root controller.

### What each layer may depend on

| Layer | May use | Must not use |
| --- | --- | --- |
| `Domain` | its own Domain, `App\Shared\Domain` | Laravel, Eloquent, HTTP, other contexts' code |
| `Application` | its own Domain and Application, `App\Shared\Domain`, `App\Shared\Application` | Laravel, Eloquent, HTTP |
| `Infrastructure` | Domain and Application contracts, Laravel, libraries | `Presentation`, `App\Admin`, `App\Http` |
| `Presentation` | Application operations, Laravel's HTTP and console features | — |

This is **enforced by a test**. `tests/Unit/ArchitectureTest.php` reads every PHP file under `app/` and fails when:

- a directory name is not PascalCase;
- a `Domain` or `Application` class references a namespace outside its allowed list;
- a `Domain` or `Application` class calls a framework helper (`app`, `config`, `auth`, `event`, `dispatch`, `request`,
  `response`, `route`, `session`, `collect`, `env`, `now`, `logger`, `dd`, `resolve`);
- an `Infrastructure` class imports presentation code.

### Naming

- PHP directories are **PascalCase** and categories are **singular**: `Model`, `Repository`, `Command`, `Query`,
  `Service`, `Contract`, `Exception`. HTTP code keeps Laravel's `Controllers` and `Requests`.
- Laravel's lowercase roots (`app`, `routes`, `database`, `config`) stay as they are.
- Create a folder or class only when real behavior needs it: no empty `Query/` or `Service/` folders, no placeholders
  for future features.

## How a request flows

```text
Route + middleware → Controller → (Form Request / input class) → Application contract (use case)
    → Domain rules → Infrastructure adapter (PostgreSQL) → events / jobs → Inertia → React page
```

A real example, creating a tax in the administration:

1. `routes/admin.php` maps `POST /admin/taxes` to `App\Admin\Controllers\TaxController@store`. Every admin route sits
   inside a group with the middleware `auth`, `auth.session`, `verified` and `can:access-admin`.
2. The controller checks the operation's own permission and calls the Application contract:

   ```php
   public function store(Request $request, TaxInput $input, TaxAdministration $taxes): RedirectResponse
   {
       Gate::authorize('taxes.create');
       $id = $taxes->create((string) $request->user()->id, $input->data($request->all()));

       return redirect('/admin/taxes/'.$id.'/edit')->with('status', __('taxes.saved'));
   }
   ```

3. `App\Tax\Application\Contract\TaxAdministration` is a plain PHP interface. `TaxInput` (Infrastructure) validates the
   request and builds the typed `SaveTaxData` command.
4. `App\Tax\Infrastructure\TaxServiceProvider` binds the interface to the PostgreSQL adapter:
   `$this->app->bind(TaxAdministration::class, EloquentTaxAdministration::class);`
5. The adapter checks the actor's permission again (defense in depth), records the change in the audit log and writes
   inside a PostgreSQL transaction. Business rules such as rate matching live in plain Domain services
   (`App\Tax\Domain\Service\TaxCalculator`, `RateAreaMatcher`).
6. The controller redirects; the edit page is rendered with `Inertia::render('Admin/Taxes/Form', [...])`, which loads
   `resources/js/pages/Admin/Taxes/Form.tsx`.

Simple reads do not need a service or repository: query classes such as `TaxQueries` return exactly the data a page
needs.

## Responsibilities

| Piece | Responsibility |
| --- | --- |
| Controllers | Coordinate permission checks, input, the operation and the response. No pricing or stock algorithms |
| Form Requests / input classes | Validate and normalize transport input |
| Gates and policies | Server-side permissions, including every bulk target and add-on action |
| Services | Substantial operations, transaction boundaries, cross-model invariants |
| Queries | Complex reusable reads; ordinary Eloquent queries stay direct |
| Domain models | Plain PHP entities; Eloquent records and mappers live separately in Infrastructure |
| Events | Typed facts with explicit commit timing |
| Jobs | Retryable background work using stable identifiers and state checks |
| Persistence | Eloquent or the query builder over PostgreSQL with explicit constraints; no blanket repositories |
| Frontend | Separate admin and storefront page trees; share only suitable primitives and types |

## Core rules

- **The server owns business decisions.** Prices, stock, taxes, totals, eligibility and validation are calculated in
  PHP and enforced in PostgreSQL. React displays results and collects input.
- **Money is exact.** Amounts are decimal strings handled by `App\Shared\Domain\Service\Decimal`; never `float`.
- **Integrity lives in PostgreSQL**: foreign keys, unique and check constraints, and triggers where a rule spans rows
  (for example search change capture and storefront cache invalidation).
- **Features, options, combinations and variations are different things.** Keep their separate meanings (see the
  [glossary](../../reference/glossary/)).
- **Optional features stay out of core.** If a capability is optional for stores, it belongs in an add-on.
- **No CQRS framework, command bus or event sourcing.** `Command/` and `Query/` folders only organize code.

## Further reading

The repository keeps the detailed engineering notes that this site summarizes:

- [Architecture decisions register](https://github.com/ambresoft/ambresoft-commerce-oss/blob/master/docs/architecture/decisions.md)
- [Context-first DDD](https://github.com/ambresoft/ambresoft-commerce-oss/blob/master/docs/architecture/layered-ddd.md)
- [Database principles](https://github.com/ambresoft/ambresoft-commerce-oss/blob/master/docs/architecture/database-principles.md)
- [Module system](https://github.com/ambresoft/ambresoft-commerce-oss/blob/master/docs/architecture/module-system.md)
- [Storefront architecture](https://github.com/ambresoft/ambresoft-commerce-oss/blob/master/docs/architecture/storefront-architecture.md)
- [Admin architecture](https://github.com/ambresoft/ambresoft-commerce-oss/blob/master/docs/architecture/admin-architecture.md)
