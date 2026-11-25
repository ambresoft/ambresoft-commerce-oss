# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for security problems.

Report vulnerabilities privately through GitHub: open the repository's **Security** tab and choose
**Report a vulnerability**. Include the affected version or commit, the steps to reproduce, and the impact you
observed. You will receive an acknowledgement within 5 working days. We will keep you informed while the fix is
prepared and credit you in the release notes unless you prefer to stay anonymous.

## Supported versions

Security fixes are made on the latest release and the `master` branch. Update to the newest release before reporting.

## Scope

In scope: this repository, including the bundled add-ons in `modules/`. Separately distributed add-ons and third-party
dependencies should be reported to their maintainers; tell us as well if the issue affects Ambresoft Commerce.

The Docker Compose setup and demo store are for **local development only**. The demo administrator
(`php artisan cms:demo-store --with-admin`) is refused outside the `local` environment. Never run a public store with
`APP_DEBUG=true`, the example passwords from `.env.example`, or the demo administrator.

Operational guidance for running a store is in [docs/architecture/security.md](docs/architecture/security.md).
