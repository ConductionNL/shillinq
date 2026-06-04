# Design: docs-product-pages-conformance

**status: pr-created**

## Architecture Overview

This change is entirely within `docs/` (the Docusaurus documentation site) and `openspec/` (spec artifacts). No PHP backend, no Vue frontend, no database, no OpenRegister schema is touched.

The docs site is a standard Docusaurus 3 project using `@conduction/docusaurus-preset`. The preset drives brand defaults (tokens, navbar/footer swizzles, i18n scaffolding). All structural conformance work is: file renames, content moves, config edits, and new stub markdown files.

Reference implementation: `openregister/docs/` (the canonical product-pages layout for the fleet).

## Goals / Non-Goals

**Goals:**
- Bring `docs/` folder tree into canonical product-pages shape.
- Eliminate em-dash violations from prose.
- Wire redocusaurus for future API docs (OAS file ships via issue #80).
- Declare `nl` locale so translators can start work (translation content ships via issue #79).
- Create an `installation.md` with real setup steps.

**Non-Goals:**
- Content rewrite of existing tutorial pages.
- Authoring Dutch translations.
- Authoring the OpenAPI JSON spec.
- Real UseCases or Integrations content.

## Decisions

### Decision 1: Folder rename via `git mv` not file copy

`docs/tutorials/` is renamed to `docs/user-guide/` using a git move (not copy-delete). This preserves git history for all 11 tutorial files. The `admin/` and `user/` subdirectories are moved intact.

Alternatives considered:
- Copy-then-delete: would show all files as new+deleted in git history, losing annotation history. Rejected.

### Decision 2: redocusaurus version pinned to `^2.0.0`

`openregister/docs/package.json` uses `redocusaurus: "^2.0.0"`. Shillinq matches this exactly so the fleet stays on the same major version and the OAS file format (JSON) is compatible.

### Decision 3: OAS placeholder path — `static/oas/shillinq.json`

Redocusaurus references `static/oas/shillinq.json`. This file does not exist yet (tracked by issue #80). Redocusaurus 2.x handles a missing spec file: the build may emit a warning but MUST NOT fail. If the build does fail on the missing file, a placeholder empty JSON skeleton (`{"openapi":"3.0.0","info":{"title":"Shillinq","version":"0.0.0"},"paths":{}}`) MUST be committed to `static/oas/shillinq.json` as a build-pass shim, with a comment that issue #80 replaces it.

Alternatives considered:
- Skip redocusaurus entirely until #80 ships: violates the canonical product-pages spec. Rejected.
- Use a URL pointing to the GitHub raw file: requires the file to exist in main; same problem. Rejected.

### Decision 4: `nl` locale declared without translation markdown

Declaring `locales: ['en', 'nl']` in `docusaurus.config.js` without corresponding `i18n/nl/` markdown is safe in Docusaurus 3 as long as `defaultLocale` stays `en` and the `nl` docs source does not exist (Docusaurus skips missing locale dirs). However, if SSR build fails (known edge case with some preset configurations), the `nl` entry is reverted with a one-line comment citing issue #79 and ADR-030. The rest of the change is unaffected.

Alternatives considered:
- Defer locale declaration to issue #79: would mean the locale dropdown is absent for translators when they start work. Rejected.

### Decision 5: Em-dash fix strategy

Em-dashes (U+2014, `—`) are replaced using the Edit tool (never sed/awk/scripts). Replacement rules:
- ADR list title separator (`Architecture — Shillinq`) becomes a colon or parenthesis form (`Architecture (Shillinq)`).
- Inline em-dashes in `intro.md` (lines 8, 11, 23) are replaced with colons or commas per sentence context.
- Em-dashes in code blocks and URLs are left unchanged (not affected by the grep gate since they are inside backtick fences).

### Decision 6: `docs/installation.md` content mirrors openregister

Structure from `openregister/docs/installation.md` is used as a template. Shillinq's install steps differ only in app name and the "Initial configuration" section (shillinq requires a register + chart of accounts setup instead of openregister's register bootstrapping). The step headers and narrative style stay identical for fleet consistency.

## Seed Data

N/A. This change modifies the `docs/` capability only. No OpenRegister schemas are introduced or modified. No `_registers.json` entries are generated.

## Risks / Trade-offs

- [SSR build fails with `nl` locale] Mitigated by revert rule described in Decision 4. The rest of the change still passes acceptance.
- [tutorials/ deep-link breakage] Existing deep-links from external sites (if any) will 404. Redirect rules are deferred to Tier-3 follow-up. Internal links in the docs are updated as part of folder rename.
- [redocusaurus build failure on missing OAS] Mitigated by Decision 3 placeholder shim rule.

## Migration Plan

1. Create the `feature/docs-product-pages-conformance` branch from `origin/development` (already done via worktree).
2. Apply all file and config changes per `tasks.md`.
3. Run `cd docs && npm install && npm run build` locally to confirm the build passes.
4. If `nl` locale causes SSR failure, revert only that line with a comment.
5. Commit and push; open PR targeting `development`.

Rollback: `git revert <commit>` on the feature branch, or close the PR without merging.

## Open Questions

None — approach is fully specified in the change scope.
