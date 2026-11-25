# Contributing to Ambresoft Commerce

Thank you for helping. Bug reports, fixes, translations and documentation improvements are all welcome.

## Before you start

- For a bug, open an issue with steps to reproduce, what you expected and what happened.
- For a new feature, open an issue first so we can agree on the approach. Optional features usually belong in an
  add-on (see the [add-on developer guide](docs/addons.md)) rather than in core.
- Security problems go through [SECURITY.md](SECURITY.md), never a public issue.

## Development setup

Follow [Get started locally](README.md#get-started-locally). PHP, Composer, PostgreSQL and Redis run in Docker;
frontend commands use Node.js 22 and pnpm. Read the [architecture overview](docs/architecture/overview.md) and
[AGENTS.md](AGENTS.md) for the conventions the codebase follows (context-first DDD, server-owned business rules,
shared admin components, keyed translations in all six languages).

## Pull requests

1. Branch from `master` and keep each pull request focused on one change.
2. Add or update tests for behaviour changes.
3. Run `make check` (Pint, PHPStan, PHPUnit, TypeScript, ESLint, Prettier, Vitest) and make sure it passes.
4. Add new UI text to every language in `lang/` (English, Greek, Spanish, French, Italian, Dutch).
5. Describe what changed and why in the pull request.

## License of contributions

Ambresoft Commerce is licensed under the [GNU AGPL v3.0 only](LICENSE). By submitting a contribution you agree that it
is your own work (or you have the right to submit it) and that it is licensed under the same terms.

Please follow our [Code of Conduct](CODE_OF_CONDUCT.md).
