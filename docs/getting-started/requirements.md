---
title: Requirements
parent: Getting started
nav_order: 1
---

# Requirements

## To run the store locally

| Software | Version | Why |
| --- | --- | --- |
| [Docker](https://docs.docker.com/get-docker/) with Docker Compose | Docker Desktop or Docker Engine with the `docker compose` command | Runs PHP, PostgreSQL, Redis, Typesense and the email inbox for you |
| [Node.js](https://nodejs.org/) | **22.13 or newer, within Node 22** | Builds the administration and storefront screens |
| [pnpm](https://pnpm.io/) | **10.24.0** | Installs the frontend packages |
| Git | any recent version | Downloads the source code |

You do **not** need PHP, Composer, PostgreSQL or Redis on your computer: they run inside Docker.

{: .tip }
> If you prefer not to install Node.js either, you can build the frontend inside Docker. See
> [Docker-only frontend](../installation/#docker-only-frontend-optional).

## What runs inside Docker

| Service | Image | Purpose |
| --- | --- | --- |
| `app` | FrankenPHP 1.14.0 with PHP 8.5 on Debian 13 | The web application (storefront and administration) on port 8000 |
| `worker` | same image | Background jobs: emails, search indexing, file cleanup |
| `scheduler` | same image | Runs scheduled tasks every minute (and search sync every 5 seconds) |
| `postgres` | PostgreSQL 18 | The database: all store data |
| `redis` | Redis 7.4 | Sessions, cache and job queues |
| `typesense` | Typesense 30.2 | Product search engine (used by the Typesense search add-on) |
| `mailpit` | Mailpit 1.31.4 | Catches every email the store sends so you can read it at `localhost:8025` |
| `pgadmin` | pgAdmin 4 (optional) | A database browser, started only on request |

## Supported versions for developers

| Component | Version |
| --- | --- |
| PHP | **8.3 minimum** (the Docker image runs 8.5; CI also checks 8.3 and 8.4) |
| Laravel | 13 |
| PostgreSQL | 18 in Docker |
| Inertia | 3, with React 19, TypeScript and Tailwind CSS 4 |

## Browser

Use an up-to-date web browser for the administration and storefront. Passkey sign-in needs a browser and device that
support passkeys.
