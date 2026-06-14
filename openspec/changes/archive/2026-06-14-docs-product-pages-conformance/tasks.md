# Tasks: docs-product-pages-conformance

## Implementation Tasks

### Task 1: Rename tutorials/ to user-guide/

- **spec_ref**: `openspec/changes/docs-product-pages-conformance/specs/docs/spec.md#req-001-canonical-folder-structure`
- **files**: `docs/tutorials/` (rename to `docs/user-guide/`), all files within `docs/tutorials/admin/` and `docs/tutorials/user/` including `_category_.json` files
- **acceptance_criteria**:
  - GIVEN the rename is complete WHEN `ls docs/user-guide/admin/` is run THEN the three admin tutorial files plus `_category_.json` are listed
  - GIVEN the rename is complete WHEN `ls docs/user-guide/user/` is run THEN the eight user tutorial files plus `_category_.json` are listed
  - GIVEN the rename is complete WHEN `ls docs/` is run THEN `tutorials/` is NOT present
- [x] Implement
- [x] Test

### Task 2: Move FEATURES.md to Features/index.md and fix em-dashes

- **spec_ref**: `openspec/changes/docs-product-pages-conformance/specs/docs/spec.md#req-001-canonical-folder-structure`
- **files**: `docs/FEATURES.md` (deleted), `docs/Features/index.md` (created with same content), em-dash fixes in moved file
- **acceptance_criteria**:
  - GIVEN the move is complete WHEN `cat docs/Features/index.md` is run THEN content matches original FEATURES.md
  - GIVEN the move is complete WHEN `ls docs/` is run THEN `FEATURES.md` is NOT present
  - GIVEN the file is updated WHEN `grep -E '—' docs/Features/index.md` is run THEN output is empty
- [x] Implement
- [x] Test

### Task 3: Move ARCHITECTURE.md to Technical/architecture.md and fix em-dashes

- **spec_ref**: `openspec/changes/docs-product-pages-conformance/specs/docs/spec.md#req-001-canonical-folder-structure`
- **files**: `docs/ARCHITECTURE.md` (deleted), `docs/Technical/architecture.md` (created with updated content)
- **acceptance_criteria**:
  - GIVEN the move is complete WHEN `cat docs/Technical/architecture.md` is run THEN content matches original ARCHITECTURE.md
  - GIVEN the move is complete WHEN `ls docs/` is run THEN `ARCHITECTURE.md` is NOT present
  - GIVEN the file is updated WHEN `grep -E '—' docs/Technical/architecture.md` is run THEN output is empty (all em-dashes in title `# Shillinq — Architecture`, description block, and ADR list entries replaced)
- [x] Implement
- [x] Test

### Task 4: Create UseCases/index.md and Integrations/index.md stubs

- **spec_ref**: `openspec/changes/docs-product-pages-conformance/specs/docs/spec.md#req-001-canonical-folder-structure`
- **files**: `docs/UseCases/index.md` (new), `docs/Integrations/index.md` (new)
- **acceptance_criteria**:
  - GIVEN the stubs are created WHEN `cat docs/UseCases/index.md` is run THEN frontmatter contains `draft: true` and body references issue #78
  - GIVEN the stubs are created WHEN `cat docs/Integrations/index.md` is run THEN frontmatter contains `draft: true` and body references issue #78
  - GIVEN the stubs are created WHEN `cd docs && npm run build` is run THEN the build succeeds without errors for these pages
- [x] Implement
- [x] Test

### Task 5: Create docs/installation.md with real install steps

- **spec_ref**: `openspec/changes/docs-product-pages-conformance/specs/docs/spec.md#req-003-installation-documentation`
- **files**: `docs/installation.md` (new)
- **acceptance_criteria**:
  - GIVEN installation.md is created WHEN `cat docs/installation.md` is run THEN it contains: Prerequisites (Nextcloud + Open Register), Install from app store steps, Initial configuration (register + chart of accounts pointer), Next steps (link to user-guide)
  - GIVEN installation.md is created WHEN `grep -E '—' docs/installation.md` is run THEN output is empty (no em-dashes)
- [x] Implement
- [x] Test

### Task 6: Fix em-dashes in docs/intro.md

- **spec_ref**: `openspec/changes/docs-product-pages-conformance/specs/docs/spec.md#req-002-no-em-dash-characters-in-prose-content`
- **files**: `docs/intro.md`
- **acceptance_criteria**:
  - GIVEN intro.md is updated WHEN `grep -E '—' docs/intro.md` is run THEN output is empty
  - Line 8 em-dash (`corporations — bookkeeping`) replaced with colon: `corporations: bookkeeping`
  - Line 11 em-dash (`shilling — one of the oldest`) replaced with comma: `shilling, one of the oldest`
  - Line 23 em-dash (`management — creation`) replaced with parentheses or comma form
- [x] Implement
- [x] Test

### Task 7: Add redocusaurus to docs/package.json

- **spec_ref**: `openspec/changes/docs-product-pages-conformance/specs/docs/spec.md#req-006-redocusaurus-dependency-declared`
- **files**: `docs/package.json`
- **acceptance_criteria**:
  - GIVEN package.json is updated WHEN `grep redocusaurus docs/package.json` is run THEN output shows `"redocusaurus": "^2.0.0"` in the `dependencies` block
- [x] Implement
- [x] Test

### Task 8: Configure redocusaurus in docs/docusaurus.config.js and add API nav link

- **spec_ref**: `openspec/changes/docs-product-pages-conformance/specs/docs/spec.md#req-004-api-documentation-endpoint`
- **files**: `docs/docusaurus.config.js`
- **acceptance_criteria**:
  - GIVEN config is updated WHEN the `plugins:` array (or equivalent preset entry) is inspected THEN it contains a redocusaurus entry with `spec: 'static/oas/shillinq.json'` and `route: '/api'`
  - GIVEN config is updated WHEN the navbar `items:` array is inspected THEN it contains an "API Documentation" link to `/api`, positioned after the "Documentation" sidebar link
  - GIVEN config is updated WHEN `cd docs && npm run build` is run THEN the build succeeds (with optional warning about missing OAS file; must not fail)
- [x] Implement
- [x] Test

### Task 9: Re-enable nl locale in docs/docusaurus.config.js

- **spec_ref**: `openspec/changes/docs-product-pages-conformance/specs/docs/spec.md#req-005-dutch-locale-declared`
- **files**: `docs/docusaurus.config.js`
- **acceptance_criteria**:
  - GIVEN config is updated WHEN the `i18n.locales` array is read THEN it contains both `'en'` and `'nl'`
  - GIVEN the nl locale is declared WHEN `cd docs && npm run build` is run THEN the build succeeds; if SSR fails due to nl locale, revert only the `locales` array to `['en']` with a comment referencing issue #79, and the task still passes with that revert
  - GIVEN config is updated WHEN the `i18n` block is read THEN a comment explains the nl locale depends on issue #79 and can be reverted if SSR breaks
- [x] Implement
- [x] Test

### Task 10: Verify zero em-dashes fleet-wide in docs/

- **spec_ref**: `openspec/changes/docs-product-pages-conformance/specs/docs/spec.md#req-002-no-em-dash-characters-in-prose-content`
- **files**: all `.md` files under `docs/` (except `node_modules`, lock files)
- **acceptance_criteria**:
  - GIVEN all tasks 2-6 are complete WHEN `git grep -E '—' docs/ ':(exclude)docs/node_modules' ':(exclude)*.lock' ':(exclude)*.lock.*'` is run THEN the command returns 0 matches (exit code 0 means no matches in `git grep` with `--quiet`, exit code 1 means no matches without `--quiet`)
  - Note: `git grep` returns exit code 1 when no matches found (this is the passing condition)
- [x] Implement
- [x] Test

### Task 11: Run docs build and confirm pass

- **spec_ref**: `openspec/changes/docs-product-pages-conformance/specs/docs/spec.md#acceptance-criteria`
- **files**: `docs/` (build validation only)
- **acceptance_criteria**:
  - GIVEN all previous tasks are complete WHEN `cd docs && npm install --legacy-peer-deps && npm run build` is run THEN the build exits with code 0
  - GIVEN the build passes WHEN the output is checked THEN no FATAL or ERROR level messages appear (warnings about missing OAS file are acceptable)
- [x] Implement
- [x] Test

## Verification

- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements
- [x] `git grep -E '—' docs/ ':(exclude)docs/node_modules' ':(exclude)*.lock' ':(exclude)*.lock.*'` returns 1 (no matches)
- [x] `cd docs && npm install --legacy-peer-deps && npm run build` exits 0

## Tests (company-wide ADR-009)

<!-- Required for all changes. Mark N/A with justification if not applicable. -->

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — N/A: no PHP code changes in this change
- [x] Newman/Postman tests for new/changed API endpoints — N/A: no API endpoints added or changed
- [x] Browser tests (Playwright MCP) for UI changes — N/A: docs site changes; build pass is the verification gate
- [x] All tests pass (`composer test`, `newman run`) — N/A: docs-only change; `npm run build` in `docs/` is the test gate

## Documentation (company-wide ADR-010)

<!-- This change IS the documentation update — it brings docs/ into canonical conformance. -->

- [x] Feature documentation updated in `docs/` — this change IS the docs update
- [x] Screenshot captured and committed to `docs/images/` — N/A: structural/config changes only; no new UI screenshots required

## i18n (company-wide ADR-005)

<!-- No new user-facing application strings are added. The nl locale is declared in config (issue #79 ships translation content). -->

- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added — N/A: no new app strings; nl locale is declared but translation content ships via issue #79
