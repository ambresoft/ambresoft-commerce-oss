---
title: Installation
parent: Getting started
nav_order: 2
---

# Installation
{: .no_toc }

These steps install a **new** store on your computer. If you already have an installation, do not repeat them: follow
[upgrading](../upgrading/) instead, because re-initializing would replace your application key and data.

1. TOC
{:toc}

---

## 1. Download the source code

```sh
git clone https://github.com/ambresoft/ambresoft-commerce-oss.git
cd ambresoft-commerce-oss
```

## 2. Create your settings file

Copy the example settings file. Do this **only if `.env` does not exist yet**:

```sh
cp .env.example .env
```

Open `.env` in a text editor and check `APP_URL`. It must be the address you will type in your browser, for example
`http://localhost:8000`. Emails (password resets, invitations) contain links built from this value.

{: .tip }
> Port 8000 already in use on your computer? Set `APP_PORT` (for example `APP_PORT=8100`) **and** change `APP_URL` to
> the same port.

## 3. Install and start

Run these commands from the project folder, one after another:

```sh
make install
docker compose run --rm --no-deps app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan cms:permissions-sync
docker compose run --rm app php artisan cms:addons-sync
pnpm build
docker compose up -d app worker scheduler typesense
```

What each step does:

| Command | What it does |
| --- | --- |
| `make install` | Builds the PHP image, installs PHP packages with Composer (inside Docker) and frontend packages with pnpm |
| `key:generate` | Creates the secret application key in `.env`. **Only on a new installation**: never replace an existing key |
| `migrate` | Creates the database tables |
| `cms:permissions-sync` | Registers all staff permissions and creates the reference roles (Super Admin, Store Admin, Catalog Manager, Order Manager, Content Manager) |
| `cms:addons-sync` | Installs the bundled add-ons. They start **disabled**; you choose which to enable |
| `pnpm build` | Builds the administration and storefront screens |
| `docker compose up -d …` | Starts the web application, the background worker, the scheduler and the search engine |

## 4. Choose: demo store or real store

### Option A: try the demo store

```sh
docker compose exec -T app php artisan cms:demo-store --with-admin
```

This fills a complete electronics shop: 37 products with photos (phones, tablets, laptops, audio, wearables and
accessories), brands, deals, coupons, banners, videos, blog posts, FAQs, pages, customers, orders, newsletters and a
fully built homepage. It also switches on the **Typesense search** add-on and builds the search index.

With `--with-admin` it creates the super administrator `admin@example.com` and **prints a random password once** in the
terminal. Copy it, then open [localhost:8000/admin](http://localhost:8000/admin) and sign in.

{: .warning }
> `--with-admin` works only in the `local` environment. The demo administrator is for your computer only: never use it
> on a public store. Use `--email=you@example.com` to choose another address.

### Option B: start a real store

Send yourself a private, one-time setup link for the first administrator:

```sh
docker compose exec -T app php artisan cms:admin-setup owner@example.com --name="Store owner"
```

Open the local email inbox at [localhost:8025](http://localhost:8025), open the setup email, follow the link and choose
your password. You are now the store's **Super Admin**. Continue with [Set up your store](../first-steps/).

## 5. Your local addresses

| What | Address |
| --- | --- |
| Storefront | [localhost:8000](http://localhost:8000) |
| Administration | [localhost:8000/admin](http://localhost:8000/admin) |
| Email inbox (Mailpit): every email the store sends lands here | [localhost:8025](http://localhost:8025) |
| Health check | [localhost:8000/up](http://localhost:8000/up) |

## Docker-only frontend (optional)

If you do not want Node.js on your computer, build the frontend inside Docker instead of `pnpm build`:

```sh
docker compose run --rm node sh -lc 'corepack enable && pnpm install --frozen-lockfile && pnpm build'
```

The `node` service keeps its own `node_modules` volume, separate from your computer. Do not copy `node_modules`
between macOS and Linux.

## Live reload while developing

Run `pnpm dev` in a separate terminal to see frontend changes instantly. Stop it before testing the built version:
a leftover `public/hot` file points the application at a stopped development server. Rebuild with `pnpm build` after
frontend changes.

## Optional: browse the database

pgAdmin is available as an optional service. Set a unique `PGADMIN_PASSWORD` in `.env`, then:

```sh
docker compose --profile db-admin up -d pgadmin
```

Open [localhost:5050](http://localhost:5050) and sign in with `PGADMIN_EMAIL` and `PGADMIN_PASSWORD`. Expand
**Local development → CMS local database**; when asked for the database password, use `DB_PASSWORD` from `.env`
(`local-only-cms` on a default local installation).

{: .warning }
> pgAdmin can edit and delete real data. Never expose it publicly, and never run `docker compose down -v`: the `-v`
> deletes the database volume with all store data.

## Troubleshooting

| Problem | What to do |
| --- | --- |
| The page says *Forbidden* when you open `/admin` with a script or `curl` | The administration refuses requests that identify as bots or command-line tools. Use a normal browser |
| A blank page or *Vite manifest* error | Run `pnpm build` (or stop `pnpm dev` and remove `public/hot`) |
| Emails never arrive | Open Mailpit at [localhost:8025](http://localhost:8025); locally, emails are caught there and not delivered to real inboxes |
| Search says it is unavailable | Start the search engine with `docker compose up -d typesense`, enable **Typesense search** in Add-ons and build the index in Settings → Search. See [Product search](../../admin/settings/search/) |
| Changes to products do not reach search, emails are not sent | Make sure the `worker` and `scheduler` containers are running: `docker compose ps` |
