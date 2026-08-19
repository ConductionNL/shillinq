# Tasks: setup-wizard-english

<!-- HYDRA CAP: max 20 unindented `- [ ]` lines. This file uses 13. -->

## Phase 1: Manifest strings (REQ-SWE-001, REQ-SWE-002)

- [ ] T01 — Rewrite all 17 Dutch fields in `src/manifest.json` `.setup.steps[]` to English source text per design.md's full wizard string inventory table (steps 0–6: `title`/`body`/`options[]`/`optionsByParent[*][]` `label`); keep jurisdiction-specific legal-entity acronyms (`ZZP`, `MKB`, `VZW`, `GmbH`, `Verein`, `Einzelunternehmen`, `BV`, `BBV`) untranslated per the ADR-007 acronym exception; resolve design.md Open Question 2 (`Besloten Vennootschap (BV)` gloss) during this task.
- [ ] T02 — Rewrite the 14 Dutch `pages[].title` / `pages[].config.widgets[].title` / `menu[].label` strings identified in design.md's page-title inventory to English source text; do NOT touch any `id`/`route` value; resolve design.md Open Question 3 (`Aansluitingen` vs `Reconciliations`) before choosing its English title.
- [ ] T03 — For every field changed in T01/T02, add the English string to `l10n/en.json` as an identity entry and move the original Dutch value to `l10n/nl.json` under the same key — no Dutch content discarded.
- [ ] T04 — Validate the edited manifest against `tests/schemas/app-manifest-v2.schema.json` (Ajv or the repo's structural fallback lint) — zero schema errors.

## Phase 2: Locale parity (REQ-SWE-003)

- [ ] T05 — Diff `l10n/en.json` against `l10n/nl.json` at current HEAD (post-T03) and add a real Dutch translation for every remaining en-only key (53 at audit time — re-diff, since T03 already closed some).
- [ ] T06 — Resolve the 3 nl-only keys (`Analytical dimensions`, `Sales`, `Valuation`): grep `src/`, `manifest.json`, `manifest.d/*.json` for a living reference to each; if found, add the matching `en.json` identity entry; if none found, remove the dead `nl.json` entry.
- [ ] T07 — Confirm `node tests/l10n/check-l10n-parity.js` reports zero missing/empty keys in both directions.

## Phase 3: Gate enforcement (REQ-SWE-004)

- [ ] T08 — Wire `check-l10n-parity.js` into `.github/workflows/l10n.yml` as a required step (currently only `check-l10n.js` runs); confirm it fails the job on an injected key gap (positive control — temporarily delete one `nl.json` key, observe the job fail, then restore).
- [ ] T09 — Add a Dutch-token stopword scan (curated list per ADR-057 D3) covering `t('shillinq', '...')`/`n(...)` literal arguments AND `manifest.json`/`manifest.d/*.json` `title`/`body`/`label` values, wired into the same `l10n` CI job; confirm it fails on an injected Dutch string (positive control) and passes clean at HEAD after T01–T02 (negative control).

## Phase 4: e2e proof (REQ-SWE-005)

- [ ] T10 — Add `tests/e2e/setup-wizard-english.spec.ts`: reset shillinq's setup app-config keys server-side before the test (not just a fresh Playwright context — design.md "Fresh-context e2e" explains why browser-context freshness alone does not reproduce the wizard); step through all 7 wizard steps as admin; assert English `title`/`body`/option-label text and the absence of any Dutch token on each visible step.
- [ ] T11 — Run the new spec once against the pre-change Dutch `manifest.json` (negative control per spec.md's "test fails against the pre-change manifest" scenario) to confirm it can fail, then run it against the post-change English manifest to confirm it passes.

## Quality

- [ ] T12 — Run `npm run test:l10n`, `node tests/l10n/check-l10n-parity.js`, and the manifest schema validation; zero failures.
- [ ] T13 — Confirm design.md Open Question 1 (whether the installed `@conduction/nextcloud-vue ^2.3.0` already routes `manifest.setup.steps` through `translate`) has been checked against the actual installed version, not just the `beta.217` checkout used to write this spec; record the finding and, if the gap still exists, file the recommended `nextcloud-vue` follow-up change (out of this change's scope to build).

## Quality checklist

- No `id`/`route`/schema property/enum value is renamed anywhere in this change — display strings (`title`/`body`/`label`) only (per proposal.md Non-Goals; PRs #534/#560 already cover property/enum translation with data migrations).
- No Dutch content is deleted without being preserved in `l10n/nl.json` first.
- The e2e test resets SERVER-side setup state, not only the browser context.
- Jurisdiction-specific legal-entity acronyms are preserved, not invented-English-glossed.
- `l10n/en.json` stays identity-mapped (key === value) for every entry touched.
