# Context-first DDD

A29 supersedes A28's layer-first layout and Eloquent-in-Domain compromise. Keep Laravel MVC, the modular monolith, server-driven Inertia and existing behavior; organize business code by bounded context with inward layers.

## Current structure

    app/
      Identity/
        Domain/
          Model/                 plain User entity and UserId value object
          Repository/            UserRepositoryInterface, interfaces only
          Exception/             business failures
        Application/
          Command/               RegisterUserHandler, ResetPasswordHandler and input DTOs
          Contract/              notification and password-recovery ports
        Infrastructure/
          Persistence/           UserRecord, UserMapper, EloquentUserRepository
          Auth/                  Laravel notification/password-broker adapters
          IdentityServiceProvider.php
        Presentation/Http/
          Controllers/           browser auth endpoints
          Requests/              Form Requests and validated DTO construction
          AuthenticationServiceProvider.php
      Shared/Infrastructure/Modules/   Composer manifest/graph/activation loader
      Admin/                     existing cross-context admin presentation shell
      Http/                      global middleware and neutral root controller

Use PascalCase PHP namespaces/directories and singular context categories Model, Repository, Command, Query, Service, Contract, Exception. Preserve Controllers/Requests for HTTP and conventional lowercase Laravel roots (app, routes, database, config). Frontend naming is unchanged. Create Query/Service or other contexts only for real implemented behavior; no empty directories or future ecommerce placeholders.

## Layers

| Layer                  | Responsibility                                                                       | Dependencies                                                                             |
| ---------------------- | ------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------- |
| Context/Domain         | Entities, value objects, invariants, repository interfaces, domain exceptions/events | Own Domain and intentional Shared Domain types; standard PHP only                        |
| Context/Application    | Use-case orchestration, typed input/output, required integration ports               | Own Application/Domain and intentional Shared Application/Domain contracts; no framework |
| Context/Infrastructure | Eloquent records/mapping, hashing/storage, broker/mail/event adapters, bindings      | Domain/Application contracts and framework/libraries; not Presentation/Admin/Http        |
| Context/Presentation   | HTTP/console, validation, authorization, sessions, redirects and page projections    | Application operations and framework delivery facilities                                 |

Admin is a delivery interface spanning contexts, not a business Domain. Keep its shell/navigation contribution API stable; do not invent Domain/Admin or an Admin aggregate. Shared contains actual cross-context module infrastructure, not business rules or a BaseEntity/BaseRepository hierarchy. Optional Composer addons remain distinct from essential bounded contexts.

## Domain entity versus authentication record

Identity\Domain\Model\User is an immutable entity identified by UserId, containing name/email/verification state. It has no HTTP, JSON, Eloquent, Laravel traits/contracts, password hash, remember token or database methods. UserId preserves positive canonical decimal identifiers as strings, including values beyond JavaScript's safe integer range. Identity's current domain behavior is intentionally small; do not invent customer/account lifecycles to make an aggregate appear richer.

Identity\Infrastructure\Persistence\UserRecord is Laravel's Authenticatable/MustVerifyEmail/Notifiable Eloquent record explicitly mapped to the existing users table. It retains normalized email, hashing, hidden credentials and verification timestamps. UserMapper maps persisted records into plain Domain entities. Repository interfaces never return Eloquent types, builders or framework collections. Guards, broker, signed verification, policies and sessions use the configured authentication record, not the Domain entity.

## Implemented use cases

Registration: validated Form Request → RegisterUserData → RegisterUserHandler → UserRepositoryInterface → Eloquent adapter → plain Domain User → RegistrationNotifierInterface. The infrastructure notifier emits Laravel's Registered event with the auth record, preserving verification delivery. The HTTP controller retrieves that identity through Laravel's configured provider, starts/rotates its session and redirects. Duplicate normalized emails become EmailAlreadyRegistered and are mapped to validation feedback at the HTTP boundary. Registration never grants staff access.

Password reset: validated ResetPasswordData → ResetPasswordHandler → PasswordResetterInterface → Laravel broker adapter. Infrastructure owns token hashing/expiry/single-use, password hashing, remember-token rotation and PasswordReset events. The handler imports no broker/Eloquent types; the controller owns messages and redirects. Login/logout and signed verification remain supported browser adapters in Identity Presentation.

Command/Query directories organize use cases, not a CQRS framework, bus, separate read database, event store or API. No Query handler is created because the current landing page reads the authenticated session projection and needs no reusable business query.

## Validation, persistence and effects

Typed DTO constructors are not validators or authorization grants. HTTP callers construct them from validated Form Request data. Later non-HTTP adapters must enforce equivalent input/security requirements. Domain invariants belong in plain PHP; PostgreSQL enforces persistence integrity. Never log or serialize secret-bearing inputs into Inertia/queue payloads. SensitiveParameter protects stack arguments, not deliberate object dumps.

Domain repository interfaces describe required persistence, not generic Eloquent CRUD. Infrastructure implements and binds them; Application never imports adapters. The narrow registration repository accepts transient credentials; hashing stays supported infrastructure behavior. Do not add one repository per model or generic BaseService automatically.

The registration adapter uses a transaction/savepoint so handled PostgreSQL uniqueness failures do not poison an enclosing transaction. Notification timing preserves the current synchronous Laravel flow after insertion, not guaranteed delivery/outbox behavior. Multi-operation use cases must define transaction ownership and commit timing before use under outer transactions. Future domain events must be plain PHP; Laravel authentication events remain inside adapters.

## Modules and migration safety

The host loader is App\Shared\Infrastructure\Modules. Manifest contract 1, dependency ordering, activation and migration lifecycle are unchanged. Modules call explicitly published Application operations/Domain contracts, never concrete persistence or HTTP controllers. AdminNavigation remains App\Admin\Navigation\AdminNavigation, preserving its consumed extension API. Domain placement alone does not publish every method as an addon API.

No schema, URL, account, password or APP_KEY change is required. Refresh Composer autoload, clear stale framework caches and restart long-lived processes after namespace changes. UserRecord's explicit users table declaration prevents accidental user_records inference. Existing hashes/reset tokens/allowlist/session identity IDs stay valid. Future serialized queued-class payloads need a drain/compatibility plan before renames; no identity jobs are introduced here.

## Verification

Plain PHPUnit unit tests exercise Domain entities and handlers without booting Laravel/database. Architecture tests enforce PascalCase paths, reject framework namespace references/helpers in Domain/Application, enforce inward context dependencies and prevent Infrastructure importing presentation. Integration tests cover mapping, repository substitution, PostgreSQL uniqueness translation, broker behavior and Laravel notifications. Existing browser/session/security/navigation/module tests remain regression coverage. Source checks do not prove safety of reflection or dynamic string bindings; review those explicitly.
