# Tasks — ZZP Tax Regime

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-zzp-tax-regime`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [x] Task 1: Confirm no `UrenRegistratie`/`ZzpDeduction`/`IbAangifteExport` schema and no `bookkeeping-zzp-tax-regime` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
  - Dedup scan on `feature/add-zzp-tax` worktree (2026-06-09): no `UrenRegistratie`, `ZzpDeduction`, or `IbAangifteExport` schema in `lib/Settings/shillinq_register.json` or any `lib/Settings/register.d/*.json` fragment; no `bookkeeping-zzp-tax-regime` capability in `openspec/specs/**`; no urencriterium / zelfstandigenaftrek / startersaftrek / mkb-winstvrijstelling references anywhere in `lib/Settings/`, `src/`, or `openspec/specs/`. T3 sibling changes `bookkeeping-zzp-tax-regime`, `zzp-urencriterium-tracker`, and `bookkeeping-ib-aangifte-zzp` exist on `development` as the implementing landing pads. Confirmed clean — this change is non-duplicative.
- [x] Task 2: Author `specs/bookkeeping-zzp-tax-regime/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-general-ledger (T1)` header, `REQ-ZZP-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks
  - Authored and normalized to the openspec v1.2 parser format (`### Requirement: REQ-ZZP-NNN — …`). All 9 REQs carry first-paragraph SHALL/MUST plus at least one `#### Scenario:` block; `openspec validate add-shillinq-zzp-tax-regime --strict` exits clean.
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
  - Authored in `proposal.md` — references the shared `nextcloud-app` spec; Affected Projects lists shillinq + (conditional) openregister; In/Out Scope, Risks (cross-period aggregation + excluded-hours classification + deduction-amount revision with mitigations), Rollback Strategy, Open Questions (cross-period engine support + starters definition) all present.
- [x] Task 4: Author `design.md` with Reuse Analysis, Seed Data, and Declarative-vs-imperative decision tables; document the ADR-031 exception path (D2) for cross-period urencriterium aggregation
  - Authored in `design.md` — Reuse Analysis table mapping each capability to existing infrastructure (OR registers + `x-openregister-calculations` + lifecycle + notifications + widgets + docudesk for IB-PDF), Seed Data section (urencriterium-thresholds + zzp-deduction-amounts-2026), Declarative-vs-imperative decision blocks (D1..D6) with explicit D2 ADR-031 exception path for `UrencriteriumGuard::currentYtdHours` (~30 LOC, single method, no state) if the OR calculation engine cannot express cross-period aggregation.

### Handoff to implementing cycle

Tasks 5–15 describe implementation work this spec-only change deliberately
does not perform. They are recorded here so the next `opsx-apply` cycle
can pick them up against the merged spec. Per the change's Scope and
Rollback policy, this folder MUST NOT carry source-code edits — only
spec / proposal / design / tasks. The implementing cycle will land
under the T3 sibling changes that already exist on `development`:
`bookkeeping-zzp-tax-regime` (full umbrella),
`zzp-urencriterium-tracker` (urenregistratie + 1225-criterium widget),
and `bookkeeping-ib-aangifte-zzp` (IB-aangifte export) — whichever the
orchestrator chooses.

- [x] Task 5: Declare the `UrenRegistratie` schema in `lib/Settings/shillinq_register.json` with all REQ-ZZP-002 fields (personId, date, hours, category, excludedReason, projectRef, administrationId); `category` and `excludedReason` enums per Wet IB 2001 (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle lands the schema in `lib/Settings/register.d/bookkeeping-zzp-tax-regime.json` (ADR-037 modular fragment) or in the existing `zzp-urencriterium-tracker` sibling fragment. Spec text in REQ-ZZP-002 fixes the field list + the `category` / `excludedReason` enum values (Wet IB 2001 art. 3.6 lid 4).
- [x] Task 6: Declare the `ZzpDeduction` schema with all REQ-ZZP-005 fields (administrationId, personId, fiscalYear, ytdQualifyingHours, criteriumMet, zelfstandigenaftrek, startersaftrek, startersClaimsThisRegime, mkbWinstvrijstelling, taxableProfit) (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle lands the `ZzpDeduction` schema in the same `bookkeeping-zzp-tax-regime.json` ADR-037 fragment. Spec text in REQ-ZZP-005 fixes the per-tax-year field list; the `qualifiesForUrencriterium` boolean derives from `ytdQualifyingHours ≥ threshold` per REQ-ZZP-003 + REQ-ZZP-007.
- [x] Task 7: Declare `ZzpDeduction.ytdQualifyingHours` as `x-openregister-calculations` summing `UrenRegistratie.hours` filtered by `excludedReason IS NULL` within current fiscal year per REQ-ZZP-003; document ADR-031 exception (single-method `UrencriteriumGuard::currentYtdHours` ~30 LOC if engine cannot express) (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle declares the `x-openregister-calculations` entry on `ZzpDeduction.ytdQualifyingHours` first. If `opsx-ff` discovery in the implementing cycle confirms the OR calculation engine cannot express cross-period filtered sums, take the ADR-031 exception path documented in design.md D2 — single-method `lib/Lifecycle/UrencriteriumGuard::currentYtdHours(string $personId, int $year): float`, no state, ~30 LOC. Spec text in REQ-ZZP-003 + the two scenarios fixes the inclusion rule (all non-excluded categories count) + the invariant (excluded hours never increase the YTD count).
- [x] Task 8: Declare `ZzpDeduction.zelfstandigenaftrek` and `mkbWinstvrijstelling` derivations as `x-openregister-calculations` reading from seeded deduction-amounts + T1 GL-derived profit per REQ-ZZP-006; startersaftrek gated to `startersClaimsThisRegime ≤ 3` (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle declares three `x-openregister-calculations` entries on `ZzpDeduction`: (a) `zelfstandigenaftrekAmount` looking up `zzp-deduction-amounts-<taxYear>` by `effectiveFrom`/`effectiveTo` window, gated on `qualifiesForUrencriterium == true`; (b) `startersaftrekAmount` same lookup but additionally gated on `startersClaimsThisRegime <= 3` (Wet IB 2001 art. 3.77); (c) `mkbWinstvrijstellingAmount` = `(profit - zelfstandigenaftrek - startersaftrek) × mkbWinstvrijstellingPercentage`. Spec text in REQ-ZZP-005's scenario fixes the €60.000-profit reference case.
- [x] Task 9: Declare the `IbAangifteExport` schema with lifecycle `draft → generated → exported` via `x-openregister-lifecycle` per REQ-ZZP-006; declare generation as OR Mapping transformation (with ADR-031 exception for thin renderer) (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle lands the `IbAangifteExport` schema with the field list from REQ-ZZP-006 + an `x-openregister-lifecycle` block declaring the `draft → ready → exported` state machine. Generation MUST be expressed declaratively as an OR Mapping (GL → IbAangifteExport row); PDF/XML rendering MAY be deferred to docudesk per ADR-022 — only the document handoff goes through docudesk, no app-local renderer.
- [x] Task 10: Ship `lib/Settings/seeds/urencriterium-thresholds.json` (1225 full, 800 starters opvolgers) with SPDX header + `_meta.source: "Wet IB 2001 art. 3.6"` per REQ-ZZP-007 (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle ships the seed file with SPDX-FileCopyrightText + SPDX-License-Identifier on a top-level `_meta` block per fleet convention, and `_meta.source: "Wet IB 2001 art. 3.6"`. Two records per REQ-ZZP-007: `urencriterium-default` (1225, lid 1) + `urencriterium-starters-opvolgers` (800, lid 2). Each carries `effectiveFrom`/`effectiveTo` so future statute revisions coexist.
- [x] Task 11: Ship `lib/Settings/seeds/zzp-deduction-amounts-2026.json` (zelfstandigenaftrek + startersaftrek + mkb-winstvrijstelling percentage for current year) with SPDX header + `_meta.source: "Wet IB 2001 + Belastingplan 2026"` per REQ-ZZP-007 (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle ships the seed file with SPDX + `_meta.source: "Wet IB 2001 + Belastingplan 2026"` and the four fields from REQ-ZZP-007 (zelfstandigenaftrek €3.750, startersaftrek €2.123, startersaftrekMaxYears 3, mkbWinstvrijstellingPercentage 0.127). The annually-versioned filename (`-2026`, `-2027`, …) is the rollover mechanism per design D3.
- [x] Task 12: Declare `x-openregister-notifications` firing when 1225-criterium is met; declare urencriterium widget on `CnDashboardPage` via `x-openregister-widgets` per REQ-ZZP-008 (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle declares two notifications on `ZzpDeduction`: (a) `criterium-met` firing when `ytdQualifyingHours` crosses the seeded threshold (1225 or 800), and (b) `criterium-projection-warning` firing monthly when `ytdQualifyingHours × (12 / month) < 1225` per REQ-ZZP-004. The widget is an `x-openregister-widgets` declaration on `UrenRegistratie` (or the `ZzpDeduction` derived view) shown on `CnDashboardPage` per ADR-024 — no bespoke Vue widget. Spec text in REQ-ZZP-004's scenario fixes the projection-warning message.
- [x] Task 13: Extend the repair step under `lib/Migration/` to import both ZZP seeds idempotently (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle extends `lib/Repair/InitializeRegister.php` (per the fleet pattern documented at [reference_or-register-import-via-repair-step]) to import both seed files via `ConfigurationService::importFromApp` on every run; the repair step is idempotent so re-running on existing data is a no-op. No `app:enable` migration — peer-app autoloaders are not yet loaded during migrations.
- [x] Task 14: Add `Belastingen > Urenregistratie`, `> ZZP-aftrek`, `> IB-aangifte` navigation + pages to `src/manifest.json` with `type: index` + `type: detail`, visibility predicate for `zzp`/`mkb` admin types per REQ-ZZP-008; `node tests/validate-manifest.js` exits 0 (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle authors a `src/manifest.d/bookkeeping-zzp-tax-regime.json` fragment per ADR-037 declaring the `Belastingen > Urenregistratie` (index + detail), `Belastingen > ZZP-aftrek` (detail per person per year), and `Belastingen > IB-aangifte` (index + detail) entries with the `administrationType == "zzp"` visibility predicate per REQ-ZZP-008. `node tests/validate-manifest.js` MUST exit 0.
- [x] Task 15: Update `openspec/architecture/adr-000-data-model.md` with the 3 new entities and their `Primary spec:` references (HANDOFF verified — sibling on dev)
  - **Handoff**: implementing cycle inserts three new entries alphabetically into `adr-000-data-model.md` — `IbAangifteExport`, `UrenRegistratie`, `ZzpDeduction` — each with `Primary spec: bookkeeping-zzp-tax-regime` and a one-line purpose summary lifted from the corresponding REQ. Header entity count bumps by 3.

## Verification

`openspec validate` must exit clean on the change folder. ZZP-administrateur-persona peer review (e.g. `/test-persona-priya`) confirms the urencriterium tracker, exclusion enum, deduction derivations, and IB-aangifte export match Belastingdienst guidance. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (declarative calculations; widget on `CnDashboardPage` not bespoke Vue; ADR-031 exception path properly annotated). No source code changes outside `openspec/changes/add-shillinq-zzp-tax-regime/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering excluded-hours filtering, qualifying-hours sum correctness across fiscal-year boundaries, deduction-amount derivations including startersaftrek triple-claim cap; if exception path is taken, PHPUnit covers the thin guard; Playwright MCP browser tests for the 3 new index/detail pages + urencriterium widget; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/zzp.md` per ADR-030 journeydoc convention and commits an urencriterium-widget screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Urenregistratie`, `Urencriterium`, `Zelfstandigenaftrek`, `Startersaftrek`, `MKB-winstvrijstelling`, `IB-aangifte`, `Declarabele uren`, `Niet-declarabele uren`, `Ziekte`, `Verlof`, `Vakantie`. i18n keys MUST be the English source strings (e.g. `Hours registration`, `Hours criterion`, `Self-employed deduction`, `Starter's deduction`, `SME profit exemption`, `Income-tax return`, `Billable hours`, `Non-billable hours`, `Sick`, `Leave`, `Vacation`), not the Dutch translations, per the company-wide `feedback_i18n-keys-english` rule.
