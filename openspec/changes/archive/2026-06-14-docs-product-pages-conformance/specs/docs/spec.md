# docs Specification

**Status**: in-progress
**Scope**: shillinq
**OpenSpec changes**:
- `docs-product-pages-conformance`

## Purpose

Defines the canonical structure, configuration, and content requirements for shillinq's product documentation site, built on `@conduction/docusaurus-preset` (ADR-030). The spec ensures the docs site conforms to the fleet-wide product-pages layout, exposes an API documentation endpoint, and ships with both English and Dutch locale support.

## ADDED Requirements

### Requirement: REQ-001: Canonical folder structure

The `docs/` directory SHALL follow the canonical product-pages layout:
- `user-guide/` (replaces `tutorials/`) with `admin/` and `user/` subdirectories
- `Features/index.md` (replaces `FEATURES.md` at root)
- `Technical/architecture.md` (replaces `ARCHITECTURE.md` at root)
- `UseCases/index.md` (stub, `draft: true`)
- `Integrations/index.md` (stub, `draft: true`)
- `installation.md` at the docs root

#### Scenario: User navigates user-guide content

- GIVEN the docs site is built and deployed
- WHEN a user browses to `/user-guide/admin/` or `/user-guide/user/`
- THEN the tutorial pages under those paths are accessible
- AND the `tutorials/` path MUST NOT exist (no broken-link fallback at old path)

#### Scenario: Features page is reachable

- GIVEN the docs site is built
- WHEN a user navigates to the Features section in the sidebar
- THEN they land on `docs/Features/index.md` content

#### Scenario: Technical architecture is reachable

- GIVEN the docs site is built
- WHEN a user navigates to the Technical section
- THEN they find `architecture.md` under `Technical/`

#### Scenario: UseCases and Integrations stubs render without error

- GIVEN `UseCases/index.md` and `Integrations/index.md` exist as `draft: true` stubs
- WHEN the docs build runs
- THEN the build SHALL succeed without errors for those pages

### Requirement: REQ-002: No em-dash characters in prose content

All markdown files in `docs/` (excluding `node_modules`, lock files, and code blocks) SHALL contain zero Unicode em-dash characters (U+2014, `—`). Em-dashes MUST be replaced with periods, commas, colons, or parentheses as contextually appropriate.

#### Scenario: Em-dash search returns zero hits

- GIVEN all markdown files have been updated
- WHEN `git grep -E '—' docs/ ':(exclude)docs/node_modules' ':(exclude)*.lock'` is run
- THEN the command returns zero matches

### Requirement: REQ-003: Installation documentation

The `docs/installation.md` file SHALL exist and document:
- Prerequisites: Nextcloud version and Open Register dependency
- How to install from the Nextcloud app store
- Initial configuration steps (register setup, chart of accounts pointer)
- Next steps (link to user-guide)

#### Scenario: Installation page is accessible

- GIVEN the docs build is complete
- WHEN a user navigates to the Installation page from the sidebar
- THEN they see prerequisite and installation steps without placeholder text

### Requirement: REQ-004: API documentation endpoint

The docs site SHALL expose an API documentation page at `/api` powered by `redocusaurus`, fed by `static/oas/shillinq.json`. The page SHALL render even if the OAS file is absent (build continues with a warning or a placeholder; the OAS file ships separately via issue #80).

#### Scenario: Navbar contains API Documentation link

- GIVEN the docs site is built and deployed
- WHEN a user views the navbar
- THEN an "API Documentation" link pointing to `/api` is visible, positioned right of the "Documentation" link

### Requirement: REQ-005: Dutch locale declared

`docs/docusaurus.config.js` SHALL declare `locales: ['en', 'nl']`. The `nl` locale MUST be declared even before Dutch markdown ships, so the locale dropdown is available for translators (issue #79). If enabling `nl` breaks the SSR build, it SHALL be reverted with a comment referencing issue #79 and ADR-030; the rest of the change remains valid.

#### Scenario: Locale config includes nl

- GIVEN `docusaurus.config.js` is read
- WHEN the `i18n.locales` array is inspected
- THEN it contains both `'en'` and `'nl'`

### Requirement: REQ-006: redocusaurus dependency declared

`docs/package.json` SHALL list `redocusaurus` (version `^2.0.0`) in `dependencies`, matching the version used by `openregister/docs/package.json`.

#### Scenario: redocusaurus is installable

- GIVEN `docs/package.json` lists `redocusaurus: "^2.0.0"`
- WHEN `npm install` runs in `docs/`
- THEN the package installs without errors

## Non-Functional Requirements

- **Performance:** Docs build (`npm run build`) SHALL complete in under 3 minutes on a standard CI runner.
- **Accessibility:** All generated pages MUST meet WCAG AA as enforced by the brand preset.
- **Internationalization:** English (`en`) is the primary locale; Dutch (`nl`) MUST be declared (ADR-007). Translation content ships via issue #79.

## Acceptance Criteria

- [ ] `docs/Features/index.md` exists with content from `FEATURES.md`, zero em-dashes outside code blocks.
- [ ] `docs/Technical/architecture.md` exists with content from `ARCHITECTURE.md`, zero em-dashes outside code blocks.
- [ ] `docs/user-guide/admin/` and `docs/user-guide/user/` exist with all tutorials moved from `tutorials/`.
- [ ] `docs/UseCases/index.md` and `docs/Integrations/index.md` exist as `draft: true` stubs with a reference to issue #78.
- [ ] `docs/installation.md` exists with real install steps.
- [ ] `docs/docusaurus.config.js` declares `locales: ['en', 'nl']`, mounts redocusaurus at `/api`, and navbar has "API Documentation" link.
- [ ] `docs/package.json` lists `redocusaurus` in `dependencies`.
- [ ] `git grep -E '—' docs/ ':(exclude)docs/node_modules' ':(exclude)*.lock' ':(exclude)*.lock.*'` returns 0 hits.
- [ ] `cd docs && npm install && npm run build` passes.

## Notes

- `docs/sidebars.js` uses `{ type: 'autogenerated', dirName: '.' }` and picks up new folders automatically; no manual sidebar changes needed.
- The `tutorials/` rename invalidates any existing deep-links under `/tutorials/`. Redirect rules are a Tier-3 follow-up.
- Issue #78: UseCases/Integrations content authoring.
- Issue #79: Dutch translation pass.
- Issue #80: OpenAPI spec (`static/oas/shillinq.json`).
- Issue #81: Final `{{TODO}}` marker cleanup.
