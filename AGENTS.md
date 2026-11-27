# AGENTS.md

Guidance for coding agents working in this repository. Humans can read it too; the
longer version lives in [README.md](README.md) and [CONTRIBUTING.md](CONTRIBUTING.md).

## What this app is

Shillinq is a business administration suite for Nextcloud, for freelancers, sole
proprietors, SMBs and corporations: integrated financial, procurement and contract
management. It requires [OpenRegister](https://github.com/ConductionNL/openregister),
which stores its records.

## Setup

```bash
composer install     # also points git at .githooks (post-install-cmd)
npm install          # also points git at .githooks (prepare)
```

Run it locally with the Docker evaluation stack from this repo, as described in the
README: `docker compose -f docker-compose.yml up -d --build`, then open
http://localhost:8080/apps/shillinq/ (user `admin`, password `admin`).
`docker compose -f docker-compose.yml down -v` removes it.

## Build

```bash
npm run build        # production frontend build (webpack)
npm run dev          # development build
npm run watch        # development build, rebuilds on change
```

Each of these first runs `node scripts/generate-manifest-shell.js`, which regenerates
`src/manifest.d.shell.json` from the fragments in `src/manifest.d/`.

## Test

```bash
composer test:unit       # PHPUnit, tests/Unit and tests/unit (phpunit-unit.xml)
npm run test:unit        # Vitest frontend unit tests
npm run test:e2e         # Playwright, tests/e2e (needs a running Nextcloud)
npm run test:e2e:install # one-time Chromium download for Playwright
```

Outside CI, Playwright has no default target. Set `PLAYWRIGHT_BASE_URL` to the
instance the suite should run against, for example
`PLAYWRIGHT_BASE_URL=http://localhost:8095 npm run test:e2e`; see
`tests/e2e/base-url.ts`.

## Lint and quality

```bash
composer check:strict   # lint, phpcs, phpmd, psalm, phpstan, tests
composer check          # quicker: lint, phpcs, psalm, unit tests
composer cs:fix         # php-cs-fixer
composer phpcs:fix      # phpcbf
npm run lint            # ESLint
npm run stylelint       # Stylelint
npm run format          # Prettier check
npm run test:l10n       # every t()/n() string has a catalogue entry
npm run check:manifest  # validate the manifest
npm run check:registers # validate the register JSON in lib/Settings/
```

`composer check:strict` is the PHP gate CI enforces.

## OpenSpec flow

Work is specified before it is built, with [OpenSpec](openspec/config.yaml):

1. A change starts in `openspec/changes/<change-name>/` with a `proposal.md`,
   `tasks.md`, delta specs under `specs/` and, when it makes a design decision,
   a `design.md`.
2. When it is built, its requirements are merged into `openspec/specs/<capability>/`.
3. The finished change moves to `openspec/changes/archive/`.

## Architecture decisions

`openspec/architecture/` holds this repo's numbered architecture records
(`adr-000` to `adr-002`: data model, bookkeeping tier roadmap, information
architecture). `docs/architecture/integration-points.md` lists the
backend integration points that are shaped but not implemented yet.

## Conventions

- **Procedures are configuration, not code.** `.procedure-code-baseline.json` pins the
  count of procedure-named files; a growing count fails the quality gate. Name code by
  what it does and put procedure specifics in the register or configuration.
- **Pages are manifest config.** Pages and menu entries are declared in
  `src/manifest.json` and in per-change fragments under `src/manifest.d/` (see
  `src/manifest.d/README.md`). Do not edit `src/manifest.d.shell.json` by hand;
  `scripts/generate-manifest-shell.js` regenerates it and the result is committed.
- **Data lives in OpenRegister.** The register and its schemas ship as JSON in
  `lib/Settings/shillinq_register.json`.
- **Every user-visible string is translated** with `t('shillinq', ...)` and checked by
  `npm run test:l10n`.

## Commits

[Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/): `feat:`, `fix:`,
`docs:`, `refactor:`, `chore:`. Present tense, imperative mood, first line at most 72
characters. Pull requests target `development`.
