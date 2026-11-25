---
title: Upgrading
parent: Getting started
nav_order: 5
---

# Upgrading to a new version

New versions are published on the [GitHub releases page](https://github.com/ambresoft/ambresoft-commerce-oss/releases).
**Read the release notes first**: each release says whether it needs a new Docker image, migrations or other steps.

{: .important }
> Keep your `.env` file, your application key (`APP_KEY`), your Compose project name and your Docker volumes. An
> upgrade never needs `key:generate`, `docker compose down -v` or a fresh database. Back up the database before you
> upgrade a store with real data.

## Standard upgrade

From the project folder:

```sh
git pull                                   # or check out the release tag, e.g. git checkout 0.1.2
make install                               # PHP and frontend packages at the versions of the new release
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan cms:permissions-sync
docker compose run --rm app php artisan cms:addons-sync
pnpm build
docker compose up -d app worker scheduler typesense
docker compose restart worker scheduler
```

| Step | Why |
| --- | --- |
| `migrate` | Applies database changes of the new version |
| `cms:permissions-sync` | Registers new permissions. Existing role grants are **not** changed: assign new permissions through **Users → Roles** |
| `cms:addons-sync` | Installs new bundled add-ons (disabled) and upgrades bundled add-ons you already have. Enabled or disabled states never change |
| `pnpm build` | Rebuilds the screens |
| `restart worker scheduler` | The background worker and the scheduler keep the old code (and the old list of enabled add-ons) in memory until they restart |

## When the Docker image changed

If the release notes mention a new image (for example a new PHP or FrankenPHP version), rebuild and recreate only the PHP
services. Databases, Redis and their volumes stay in place:

```sh
docker compose build app worker scheduler
docker compose up -d --no-deps app worker scheduler
```

## After the upgrade

- If the storefront cache is enabled, open **Cache** in the administration and clear it once the database is ready.
- If the release changed search, open **Settings → Search** and check its health; rebuild the index if it says a rebuild
  is needed.
- Sign in to the administration and open a few pages to confirm everything works.

## Upgrading add-ons

- **Bundled add-ons** (in `modules/`) upgrade with the application through `cms:addons-sync`, or with the **Upgrade**
  button in **Add-ons**.
- **Uploaded add-ons** are upgraded by uploading the newer ZIP in **Add-ons** with **This is a newer version of an
  installed add-on: replace it** turned on. Their settings and data are kept. See [Add-ons](../../admin/system/addons/).
