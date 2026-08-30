# Tasks — Waterschappen BBV Variant

> **Spec-only change.** Per `proposal.md` Scope, implementation
> code is deliberately out of scope. The tasks below describe the
> work an `opsx-apply` cycle will execute against the
> `bookkeeping-waterschappen-bbv-variant` spec — recorded now so
> spec-review, dependency planning, and tier-cascade impact are
> visible at proposal time. No source files are edited by this
> change itself.

## Tasks

- [x] Task 1: Confirm no `WaterschapHeffingPosting` schema or `bookkeeping-waterschappen-bbv-variant` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `openspec/changes/**`, `adr-000-data-model.md`)
- [x] Task 2: Author `specs/bookkeeping-waterschappen-bbv-variant/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T4-specialized (NL gov sector)` / `Depends on: bookkeeping-bbv-compliance` header, `REQ-WSB-NNN` requirements using RFC 2119 keywords, `#### Scenario:` blocks with GIVEN/WHEN/THEN
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec and including Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [x] Task 4: Author `design.md` with Reuse Analysis table and Seed Data section per hydra `rules.design`; BBVW reviewer persona confirms the variant-flag shape matches handleiding
- [x] Task 5: Declare the `bbvVariant: gemeente | waterschap | provincie` enum field on `Account` and `BBVProgramma` in `lib/Settings/shillinq_register.json` (default `gemeente`) per REQ-WSB-001
- [x] Task 6: Add `programmaStructure: taakveld | kostentoedeling` discriminator to `BBVProgramma` per REQ-WSB-002; aggregations honour the discriminator at rollup time
- [x] Task 7: Declare the `WaterschapHeffingPosting` schema with `heffingType` enum (watersysteemheffing / zuiveringsheffing / verontreinigingsheffing), `aanslagJaar`, `tariefGrondslag`, `tarief`, `aanslagBedrag`, `journalEntryId` FK + `emuExclusionRule` field per REQ-WSB-004 and REQ-WSB-005
- [x] Task 8: Ship `lib/Settings/seeds/bbv-waterschappen-programmas-2026.json` (BBVW kostentoedeling cluster headers — watersysteembeheer, zuiveringsbeheer, wegenbeheer, muskusratbestrijding, etc.); SPDX in docblock; `_meta` block (`source: 'BBVW handleiding'`, `year: 2026`) per REQ-WSB-003
- [x] Task 9: Extend the repair step under `lib/Migration/` to import the BBVW programma seed idempotently when `featureFlags.gov-waterschap` is enabled (operator edits persist across re-runs) — **superseded by `bookkeeping-waterschappen-bbv-variant-01-config-schemas-seed`**, which wired `SettingsService::seedBbvProgrammes()` + `seedBudgetBbvMappings()` into `lib/Repair/InitializeSettings::seedBbvWaterschappenDemo()` (phase 12). The chain uses the English-slug `BBVProgramme` / `BudgetBBVMapping` registers per ADR-037 (separate seed file `bbv-waterschappen-programmes-2026-demo.json`) instead of editing the Dutch-slug `BBVProgramma` schema this T2 change defined; both co-exist by design (see slice-01 design notes). The Dutch seed `bbv-waterschappen-programmas-2026.json` from Task 8 is retained as a reference artefact; idempotency + operator-edit persistence are delivered by the chain via natural-key dedupe in the slice-01 seeders.
- [x] Task 10: Wire `WaterschapHeffingPosting` to materialise a balanced 2-line `GLTransaction` per T1 REQ-GL-001 when state transitions to `posted`, with `sourceReference` back to the heffing-posting per REQ-WSB-004
- [x] Task 11: Add Waterschapsbelastingen navigation + pages to `src/manifest.json` (`featureFlags.gov-waterschap`, `Bookkeeping > Waterschapsbelastingen`, `type: index` binding to `WaterschapHeffingPosting`, `type: detail` for heffing fields + materialised journal link) per REQ-WSB-006; `node tests/validate-manifest.js` exits 0
- [x] Task 12: Update `openspec/architecture/adr-000-data-model.md` with a one-paragraph annotation for `WaterschapHeffingPosting` cross-referencing this spec

## Verification

`openspec validate` must exit clean on the change folder. BBVW
reviewer persona confirms the variant-flag shape + heffing posting
shape + EMU exclusion defaults match the 2026 BBVW handleiding.
Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 + ADR-032
compliance (no app-local audit; no parallel BBV register; manifest
carries the navigation; `kind: config` honoured). No source code
changes outside
`openspec/changes/add-shillinq-waterschappen-bbv-variant/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation
cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests
covering variant overlay round-trip, kostentoedeling rollup,
balanced GLTransaction materialisation, EMU exclusion behaviour,
seed idempotent re-run (pre-declared on Tasks 5–10 above); Playwright
MCP browser tests for the Waterschapsbelastingen index + detail
pages with the feature flag toggled (pre-declared on Task 11);
`composer test` green at the implementing PR's CI gate. No new REST
endpoints (OR exposes register CRUD generically).

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The
implementation cycle authors
`docs/user-guide/bookkeeping/gov-waterschap/waterschapsbelastingen.md`
per ADR-030 journeydoc convention and commits a screenshot to
`docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The
implementation cycle adds Dutch (`nl_NL`) and English (`en_US`)
translation strings for: `Waterschap`, `Waterschapsbelastingen`,
`Watersysteemheffing`, `Zuiveringsheffing`,
`Verontreinigingsheffing`, `Kostentoedeling`, `EMU exclusion`.
