# Agent Instructions

This document provides guidance for AI agents working on the Open Source Point of Sale (OSPOS) codebase.

## Code Style

- Follow PHP CodeIgniter 4 coding standards
- Run PHP-CS-Fixer before committing: `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.no-header.php`
- Write PHP 8.1+ compatible code with proper type declarations
- Naming follows the surrounding code, not PSR-12: this codebase names models, libraries, methods and
  variables in `snake_case` (`Sale_lib::get_cart()`, `$sale_id`), classes in `PascalCase`/`Snake_case`
  as the neighbouring files do, and constants in `UPPER_CASE`. Controller actions keep CodeIgniter's
  `getX`/`postX`. New code matches the file it lives in; do not mix styles inside a module. (Decided
  2026-10-07: upstream's PSR-12 rule never matched the code, and the presales module ended up mixing
  both.)

## Development

**This is a fork (`deimorga/opensourcepos_casaletto`), not upstream.** New work branches from and
lands on **`develop`**, never `master`. `develop` deploys to staging, `master` to production; both
are locked to those branches by GitHub Environments. A hotfix meant to go straight to production is
the only exception, and it is a deliberate call, not a default.

- Run `git branch --show-current` immediately before any commit. It is easy to still be on `master`
  after a deploy operation and commit untested work there.
- Commit fixes and push to the remote.

## Fork-specific operational rules

These are not upstream's rules. Ignoring them has caused real incidents in this repo.

- **The container migrates every tenant schema when it starts** (`docker/entrypoint.sh` →
  `scripts/migrate-tenants.sh`, since `8f92b4901`). A deploy with a new migration needs no manual
  `php spark migrate`; check the log ends in `[entrypoint] All schemas current.` If any schema fails,
  Apache does not start, on purpose. Take a database backup before deploying a migration anyway.
- **A new permission leaves the support employee behind.** `soporte_micronuba` holds the permissions
  that existed when it was created. After a deploy that adds a module or permission, run
  `php spark platform:support-employee` (all tenants, idempotent) or a support session won't see it.
  Deliberately not in the entrypoint: a failure there would take the till down.
- **Never roll back to an image older than the schema.** An image whose latest migration is below the
  database's makes `MY_Migration::is_latest()` false, and `Load_config` destroys the session on every
  request: nobody can log in, with every check green. Roll back image and database backup together,
  or pick a rollback image with the same migrations.
- **Deploys go through GitHub Actions, and both workflows run `scripts/deploy.sh`.** Use
  `gh workflow run deploy-staging.yml --ref develop`, certify on staging, fast-forward `master`, then
  `gh workflow run deploy-production.yml --ref master`. The script refuses a production commit that
  staging doesn't run, backs up and restore-tests every schema, builds before stopping anything,
  verifies every business host and rolls back on its own when it safely can. Never run a bare
  `docker compose up` on the VPS: on 2026-09-29 one without `-f docker-compose.prod.yml` took
  production down for 9 minutes. In an emergency, run the same script by hand. See
  `docs/Tecnico/despliegue.md`.
- **Production is not touched while the business is selling** — only after 22:00 Colombia time,
  unless the owner authorizes it explicitly in the moment. Verification against production is
  read-only: counts, logs, smoke tests. Never test transactions.
- **Never inline secrets in the compose files.** This repo is public. New environment variables go in
  as `${VAR}` and are set in each VPS folder's untracked `.env`.

## Documentation

Behaviour changes are documented in **both** places or in neither:

- `docs/Funcional/` — what the business sees. Written for a stakeholder, not an engineer.
- `docs/Tecnico/` — how it works, what was decided and why, what was ruled out.

`docs/Funcional/referencia-ospos-wiki/` is a frozen copy of upstream's wiki from the fork point.
**Do not edit it.** Where our behaviour diverges — or where it describes CodeIgniter 3 paths that no
longer exist — write the correction in `docs/Funcional/` proper and leave the original as historical
context.

A change is not done until the docs match the code. Updating only the technical doc and leaving the
functional one behind is the specific failure this section exists to prevent.

## Testing

- Run PHPUnit tests: `composer test`
- Tests must pass before submitting changes

## Build

- Install dependencies: `composer install && npm install`
- Build assets: `npm run build` or `gulp`

## Conventions

- Controllers go in `app/Controllers/`
- Models go in `app/Models/`
- Views go in `app/Views/`
- Database migrations in `app/Database/Migrations/`
- Use CodeIgniter 4 framework patterns and helpers
- Sanitize user input; escape output using `esc()` helper

## Security

- Never commit secrets, credentials, or `.env` files
- Use parameterized queries to prevent SQL injection
- Validate and sanitize all user input