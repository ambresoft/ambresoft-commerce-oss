---
title: Local development
parent: Developer guide
nav_order: 3
---

# Local development
{: .no_toc }

1. TOC
{:toc}

---

Install the project first: see [Installation](../../getting-started/installation/). PHP and Composer always run inside
Docker; Node.js and pnpm run on your computer (or in the optional `node` container).

## Everyday commands

| Task | Command |
| --- | --- |
| Start the stack | `docker compose up -d app worker scheduler typesense` |
| Stop it (keeps data) | `docker compose down` or `make down` |
| See running services | `docker compose ps` |
| Run an Artisan command | `docker compose exec -T app php artisan <command>` |
| Run Composer | `docker compose exec -T app composer <command>` |
| Frontend with live reload | `pnpm dev` |
| Build the frontend | `pnpm build` |
| Follow logs | `docker compose logs -f app worker scheduler` |
| Failed background jobs | `docker compose exec -T app php artisan queue:failed` |
| Clear framework caches | `docker compose exec -T app php artisan optimize:clear` |
| List routes | `docker compose exec -T app php artisan route:list --except-vendor` |
| List scheduled tasks | `docker compose exec -T app php artisan schedule:list` |

{: .warning }
> Never run `docker compose down -v` unless you really want to delete the database, Redis and search volumes.

Logs go to standard error (`LOG_CHANNEL=stderr`), so `docker compose logs` shows them.

## Quality checks

Run everything CI runs:

```sh
make check
```

`make check` runs the frontend checks (`pnpm check`) and then the backend checks (`composer check`) in Docker:

| Check | Command | Tool |
| --- | --- | --- |
| PHP style | `composer lint` (fix with `composer format`) | Laravel Pint |
| Static analysis | `composer analyse` | PHPStan with Larastan, level 6 |
| PHP tests | `composer test` | PHPUnit, against the isolated `cms_test` database |
| Types | `pnpm types` | TypeScript (`tsc --noEmit`) |
| Lint | `pnpm lint` | ESLint |
| Formatting | `pnpm format:check` (fix with `pnpm format`) | Prettier |
| Frontend tests | `pnpm test` | Vitest with Testing Library |
| Production build | `pnpm build` | Vite |

Also useful before a pull request:

```sh
docker compose run --rm --no-deps app composer validate --strict
docker compose run --rm --no-deps app composer audit
pnpm audit
git diff --check
```

### What CI runs

The GitHub Actions workflows in `.github/workflows/`:

- **Quality**: installs with Docker, runs `make check`, starts the FrankenPHP services for HTTP smoke checks (health,
  login, assets, private-file protection) and audits dependencies; plus PHP 8.3, 8.4 and 8.5 compatibility jobs
  (platform requirements and syntax);
- **Security**: secret scanning, dependency advisories and a Trivy scan of the built container image;
- **CodeQL**: code scanning;
- **Release**: publishes a GitHub release when a version tag (`1.2.3` or `v1.2.3`) is pushed.

## After changing namespaces or add-ons

```sh
docker compose exec -T app composer dump-autoload --optimize
docker compose exec -T app php artisan optimize:clear
docker compose restart worker scheduler
```

Never rotate `APP_KEY` or recreate the database for a code-layout change.

## Demo data commands

These commands create sample data and refuse to run outside the `local` and `testing` environments (except
`cms:demo-store`, which accepts `--force` for a client demo install, never together with `--with-admin`). `<actor>` is
the numeric ID of an existing staff user allowed to create that data:

| Command | Creates |
| --- | --- |
| `cms:demo-store [--with-admin] [--email=]` | The complete demo electronics store |
| `cms:features-demo <actor>` | Ten products demonstrating features |
| `cms:brands-demo <actor>` | Assigns catalog products to sample brands |
| `cms:banners-demo <actor>` | Two sample hero banners |
| `cms:blog-demo <actor>` | Two blogs with eight posts |
| `cms:faqs-demo <actor>` | Four sample FAQs |
| `cms:pages-demo <actor>` | Privacy policy, returns, and a contact page with a form |
| `cms:customers-demo <actor>` | Fictitious customers with Greek addresses |
| `cms:orders-demo <actor>` | One sample order per order status |
| `cms:newsletters-demo <actor>` | Lists, templates, a campaign, newsletters and subscribers (nothing is sent) |
| `cms:taxes-demo <actor>` | Sample Greek rate areas and taxes |

See the full list of commands in the [Artisan commands reference](../../reference/commands/).

## AI coding tools (optional)

The repository includes **Laravel Boost** as a development dependency with an MCP configuration for Claude Code in
`.mcp.json`. It runs only through the guarded `scripts/boost-mcp.php` launcher against the local `cms-foundation`
Compose project and its local database. Project instructions for AI assistants live in `AGENTS.md` and `CLAUDE.md`, and
shared skills in `.agents/skills/`. Never point Boost at production credentials or expose it as an HTTP endpoint.
