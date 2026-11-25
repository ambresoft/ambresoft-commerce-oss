---
title: Authorization
parent: Developer guide
nav_order: 10
---

# Authorization

**Authentication** identifies a user; **authorization** decides what that user may do. Staff permissions are persisted
in PostgreSQL and checked on the server for every page, action and bulk target.

## The model

```text
User → role_user → Role → permission_role → Permission
```

- A user can have several roles; their permissions add up.
- Permissions have dotted names such as `products.update` or `ambresoft.sitemap.publish`.
- One protected role, **Super Admin**, has the `is_super_admin` flag: it is granted every **registered** capability.
  It does not bypass unknown abilities or resource rules.
- Customers are not staff. A customer account never grants administration access.

`php artisan cms:permissions-sync` registers missing permission definitions and creates the reference roles: **Super
Admin**, **Store Admin**, **Catalog Manager**, **Order Manager** and **Content Manager**. It never changes existing
grants or assigns users. The complete list of permissions is in the [Permissions reference](../../reference/permissions/).

## How a route is protected

1. Every admin route is in a group with `auth`, `auth.session`, `verified` and `can:access-admin` (which delegates to
   the `admin.access` permission).
2. Each controller action checks its own capability:

   ```php
   Gate::authorize('taxes.create');
   ```

3. Sensitive Application operations check the actor again (defense in depth), and access changes are serialized with
   a PostgreSQL advisory lock.

Gates are registered by `AuthorizationServiceProvider` from the `PermissionRegistry` after all enabled providers boot.
`AccessChecker` reads the persisted state on every request, so revoking a permission takes effect on the next request.

## Adding a permission

1. Register it from a service provider (core or add-on):

   ```php
   $this->app->make(PermissionRegistry::class)->add('acme.reports.view', 'acme-reports::ui.permission_view');
   ```

   The second argument is the translation key of the label shown in the role editor.
2. Protect routes (`->middleware('can:acme.reports.view')`) or controller actions (`Gate::authorize(...)`).
3. Run `php artisan cms:permissions-sync`. Nobody receives the new permission automatically: a Super Admin grants it
   through **Users → Roles**.
4. Optionally show or hide controls in React with the `capabilities` prop. That is presentation only.
5. Test that a user **without** the permission receives `403`.

## Safety rules built in

- At least one active, verified Super Admin always remains; nobody can deactivate themselves.
- Staff management (`users.create`, `users.update`, `users.deactivate`, `users.set-password`, `users.assign-roles`,
  `roles.create`, `roles.update`, `roles.delete`, `roles.assign-permissions`) belongs to Super Admins by default. A
  Super Admin can delegate it through a role.
- An actor cannot grant capabilities they lack, assign stronger roles or edit a more privileged account.
- `users.update` does not imply `users.set-password`.
- Roles that are assigned to users cannot be deleted.

## Operator recovery

`php artisan cms:admin-grant someone@example.com` grants the Super Admin role to an **existing, verified, active** user.
It never creates accounts or changes passwords. Use it to recover access when no Super Admin can sign in.
