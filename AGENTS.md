# Agent Instructions

This document provides guidance for AI agents working on the Open Source Point of Sale (OSPOS) codebase.

## Code Style

- Follow PHP CodeIgniter 4 coding standards
- Run PHP-CS-Fixer before committing: `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.no-header.php`
- Write PHP 8.1+ compatible code with proper type declarations
- Use PSR-12 naming conventions: `camelCase` for variables and functions, `PascalCase` for classes, `UPPER_CASE` for constants

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

- **The deploy workflows do NOT run database migrations.** Any commit that adds a migration needs
  `php spark migrate` triggered manually over SSH after the deploy finishes.
- **Any manual `docker compose up --build` must be preceded by the asset build.** The Dockerfile only
  copies the repo; it never runs composer or npm. Skipping the build leaves the page with no CSS or
  JS behind an HTTP 200 that no smoke test catches.
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