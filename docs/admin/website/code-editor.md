---
title: Code editor
parent: Website
grand_parent: Administration guide
nav_order: 7
---

# Code editor

**Website → Code editor** (`/admin/code-editor`)

The code editor lets trusted staff change the source files of the storefront theme (`Default`): its stylesheets and
React components. It is meant for **developers**.

{: .warning }
> Theme code runs in every customer's browser. Review changes like any developer code. The permission
> *Edit frontend theme source (trusted code)* is not given to anyone except Super Admins unless explicitly granted.

## What you can edit

Existing `.css`, `.tsx`, `.jsx`, `.ts` and `.js` files under `resources/js/storefront/themes/Default/`, up to 256 KB each.
Other parts of the application (administration, server code, configuration) cannot be edited here.

## Saving is not publishing

**Save** stores your change as a **draft** on the server. It does not change the live storefront. If someone else saved
the same file meanwhile, or a deployment changed it, you get a conflict instead of overwriting their work.

To **publish** the drafts, the server operator runs, on the server:

```sh
pnpm theme:apply
```

or, with the Docker tools profile:

```sh
docker compose run --rm node sh -lc 'corepack pnpm theme:apply'
```

This type-checks and builds the storefront. If the build fails, nothing changes. If it succeeds, the drafts are applied
and removed, and the next page load uses the new build.

Every save is recorded in the audit log (who, when and which file, without the code itself).

## Permission

*Edit frontend theme source (trusted code)* (`theme-code.manage`).
