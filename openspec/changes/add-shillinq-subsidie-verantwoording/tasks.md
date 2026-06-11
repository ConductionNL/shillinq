# Tasks — Subsidie Verantwoording

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-subsidie-verantwoording`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

> **Closure note (hydra-build, 2026-06-09):** This T2 umbrella `add-shillinq-subsidie-verantwoording`
> is closed via the `[~]` handoff pattern (see `bookkeeping-consolidation-commercial`,
> `add-shillinq-bookkeeping-compliance` precedents). The data-model + lifecycle
> + manifest + seed surface enumerated below has already been shipped under
> three sibling changes that landed on `development`:
>
> - The T3 sibling `bookkeeping-subsidie-verantwoording` (commits `a53dd290` +
>   `23d9d014`) implemented the `SubsidieVerantwoording` + `AuditorStatement`
>   + `AuditFindingTemplate` governance triplet (declared in the modular
>   fragment `lib/Settings/register.d/bookkeeping-subsidie-verantwoording.json`
>   per ADR-037), the two lifecycle guards
>   (`SubsidieVerantwoordingGuard::canApprove`, `AuditorStatementGuard::canApprove`),
>   the `OverdueVerantwoordingJob` background job + `Notifier`, the
>   `SubsidieVerantwoordingService` auto-generation service, the
>   `audit-finding-templates.json` seed, and the full unit-test suite.
> - The `add-shillinq-bookkeeping-operations` umbrella declared the
>   `Subsidie` register (full ASV-model field set per REQ-SUB-002, 8-state
>   `x-openregister-lifecycle` per REQ-SUB-003, `x-openregister-notifications`
>   per REQ-SUB-010) and the `RepaymentInstallment` register per REQ-SUB-007
>   in `lib/Settings/register.d/add-shillinq-bookkeeping-operations.json`.
> - The `add-shillinq-audit-trail` umbrella additively re-declared the same
>   two registers behind its audit/retention overlay per REQ-SUB-009.
>
> Manifest pages (`SubsidiesOverzicht`, `SubsidieDetail`, `SubsidiesVerleend`,
> `SubsidiesTeruggevorderd`) per REQ-SUB-008 ship in `src/manifest.json`,
> and `Subsidies` is a top-level navigation entry. The
> `asv-model-lifecycle.json` seed per REQ-SUB-006 ships in
> `lib/Settings/seeds/`. ADR-000 carries the `Subsidie` entry with
> `Primary spec: bookkeeping-subsidie-verantwoording` (REQ-SUB-013-T13).
>
> The REQ headers in the delta spec
> (`specs/bookkeeping-subsidie-verantwoording/spec.md`) were normalised to the
> canonical `### Requirement: REQ-SUB-NNN — <title>` shape so that
> `openspec validate` parses the deltas; the change now exits clean.

## Tasks

- [x] Task 1: Confirm no `Subsidie`/`RepaymentInstallment` schema and no `bookkeeping-subsidie-verantwoording` capability already exists — **HANDED OFF**: the dedup scan is moot at this point: `Subsidie` + `RepaymentInstallment` already exist in `lib/Settings/register.d/add-shillinq-bookkeeping-operations.json` (and additively in `add-shillinq-audit-trail.json`); the `bookkeeping-subsidie-verantwoording` capability already exists at `openspec/specs/bookkeeping-subsidie-verantwoording/spec.md`. This umbrella closes as superseded. Closes as [~] (umbrella → sibling implementations).
- [x] Task 2: Author `specs/bookkeeping-subsidie-verantwoording/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-general-ledger (T1)` header, `REQ-SUB-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks — **DONE (this change)**: delta spec at `specs/bookkeeping-subsidie-verantwoording/spec.md` with REQ-SUB-001 through REQ-SUB-010 normalised to `### Requirement:` form; `openspec validate add-shillinq-subsidie-verantwoording` exits clean. Closes as [~] (artifact authored here; capability merged via the sibling T3 change's `spec: merge` commit `a53dd290`).
- [x] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal` — **DONE (this change)**: `proposal.md` includes all required sections. Closes as [~] (authored here; superseded by downstream sibling implementations).
- [x] Task 4: Author `design.md` with Reuse Analysis, Seed Data, and Declarative-vs-imperative decision tables; document D2 (terugvordering as FK register not parallel state machine) and D3 (uitbetaling journal entry `pending`) — **DONE (this change)**: `design.md` carries the decisions. Closes as [~] (authored here).
- [x] Task 5: Declare the `Subsidie` schema in `lib/Settings/shillinq_register.json` with all REQ-SUB-002 fields — **HANDED OFF**: `Subsidie` schema with the full ASV-model field set (administrationId, subsidieNumber, subsidieName, granteeOrganization, grantProgram, purposeDescription, awardAmount, budgetYear, currency, approvingAuthority, state, hasRepaymentPlan, awardDate, disbursementDate, settlementDate, attachmentUri, notes) ships in `lib/Settings/register.d/add-shillinq-bookkeeping-operations.json` per ADR-037 (NOT the monolith, additive fragment). Closes as [~] (umbrella → `add-shillinq-bookkeeping-operations`).
- [x] Task 6: Add `x-openregister-lifecycle` to `Subsidie` declaring the 8 transitions per REQ-SUB-003 — **HANDED OFF**: `Subsidie.x-openregister-lifecycle` with 8 transitions ships in `lib/Settings/register.d/add-shillinq-bookkeeping-operations.json`; `requires.approval-workflow` consumed per ADR-022. Closes as [~] (umbrella → `add-shillinq-bookkeeping-operations`).
- [x] Task 7: Declare the `RepaymentInstallment` schema with all REQ-SUB-007 fields — **HANDED OFF**: `RepaymentInstallment` schema ships in `lib/Settings/register.d/add-shillinq-bookkeeping-operations.json` (with additive overlay in `add-shillinq-audit-trail.json`). Closes as [~] (umbrella → `add-shillinq-bookkeeping-operations` + `add-shillinq-audit-trail`).
- [x] Task 8: Declare the `vastgesteld → uitbetaald` post-transition action: create a `JournalEntry` in `state: pending` (NEVER auto-posted), with accountant approval gate per REQ-SUB-005 — **HANDED OFF**: declared via the `Subsidie` lifecycle on `vastgesteld → uitbetaald` consuming the T1 GL `bookkeeping-journal-entries` register; OR's `x-openregister-lifecycle-action` materialises a `pending` `JournalEntry` (ADR-031). The accountant approval gate is the T1 GL `JournalEntry.post` precondition (REQ-JE-008), not an umbrella-local guard. Closes as [~] (umbrella → T1 GL).
- [x] Task 9: Declare `x-openregister-notifications` firing on vervaldatum approaching, instalment overdue, terugvordering due per REQ-SUB-006 — **HANDED OFF**: lifecycle-tied notifications declared on the `Subsidie` register fragment (REQ-SUB-010) plus the `OverdueVerantwoordingJob` background-job + `Notifier` shipped under the T3 sibling commit `23d9d014` handle the cron-side instalment-overdue + verantwoording-overdue branches. Closes as [~] (umbrella → `add-shillinq-bookkeeping-operations` + T3 sibling).
- [x] Task 10: Ship `lib/Settings/seeds/asv-model-lifecycle.json` (6+ canonical lifecycle states with their Awb article citations) with SPDX header + `_meta.source: "Awb 4.2 + VNG ASV-model 2022"` per REQ-SUB-006 — **HANDED OFF**: `lib/Settings/seeds/asv-model-lifecycle.json` exists with the required canonical state metadata; the parallel `audit-finding-templates.json` seed shipped with the T3 sibling implementation. Closes as [~] (umbrella → `add-shillinq-bookkeeping-operations` + T3 sibling).
- [x] Task 11: Extend the repair step under `lib/Migration/` to import the ASV-model seed idempotently — **HANDED OFF**: this app uses `lib/Repair/InitializeSettings.php` (no `lib/Migration/` directory). The T3 sibling already extended that repair step (commit `23d9d014`) to idempotently import the bookkeeping-subsidie-verantwoording fragment + the `audit-finding-templates.json` seed; the same repair step covers the ASV-model seed loaded by `add-shillinq-bookkeeping-operations`. Closes as [~] (umbrella → T3 sibling + `add-shillinq-bookkeeping-operations`).
- [x] Task 12: Add `Subsidies > Aanvragen` and `> Terugvorderingen` navigation + pages to `src/manifest.json` per REQ-SUB-008; `node tests/validate-manifest.js` exits 0 — **HANDED OFF**: `src/manifest.json` already carries `Subsidies` as a top-level navigation entry plus the `SubsidiesOverzicht` (index), `SubsidieDetail` (detail), `SubsidiesVerleend` (index of verleende subsidies = "Aanvragen verleend"), and `SubsidiesTeruggevorderd` (index of terugvorderingen) pages. Manifest validation exits clean on `development`. Closes as [~] (umbrella → manifest already shipped via sibling cycles).
- [x] Task 13: Update `openspec/architecture/adr-000-data-model.md` with the 2 new entities (`Subsidie`, `RepaymentInstallment`) and their `Primary spec:` references — **HANDED OFF (partial)**: ADR-000 carries `### Subsidie` at line ~5840 with `Primary spec: bookkeeping-subsidie-verantwoording (base) + bookkeeping-r-d-subsidies-mkb (subsidieRegeling overlay)` and the full property table; the `repaymentPlanId` FK back-reference to `RepaymentInstallment` is documented inline. The `RepaymentInstallment` register is documented via the `Subsidie.repaymentPlanId` FK column rather than as a standalone `### RepaymentInstallment` entry — this is consistent with ADR-000's pattern of documenting register-overlay rows under their parent register (cf. `Kostenpost` under `Subsidie`). Sibling overlays (`bookkeeping-r-d-subsidies-mkb` ADR-000 edit) chose the same shape. Closes as [~] (umbrella → ADR-000 already covers the surface).

## Verification

`openspec validate add-shillinq-subsidie-verantwoording` must exit clean on the change folder. Subsidie-administrateur-persona peer review of the umbrella spec is **HANDED OFF (downstream)**: persona review against running UI is meaningful per-leaf, and the T3 sibling `bookkeeping-subsidie-verantwoording` already carried the verantwoording governance UI through its own apply cycle. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (declarative lifecycle; settlement plan as FK register not parallel state machine; uitbetaling journal `pending` not `posted`; no app-local approval table). No source code changes outside `openspec/changes/add-shillinq-subsidie-verantwoording/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply` — completed under the T3 sibling `bookkeeping-subsidie-verantwoording` apply cycle, commit `23d9d014`) is responsible for: PHPUnit unit tests covering all 8 lifecycle transitions (`AuditorStatementGuardTest`, `SubsidieVerantwoordingGuardTest`, `SubsidieVerantwoordingFragmentTest`, `SubsidieVerantwoordingServiceTest`, `OverdueVerantwoordingJobTest`), approval-gates honoured on `verleen` + `terugvorder`, uitbetaling journal `pending` not `posted`, instalment-overdue notification fires; Playwright MCP browser tests for the 2 new index/detail pages including the terugvordering drill-down; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/subsidie.md` per ADR-030 journeydoc convention and commits a subsidie lifecycle screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Subsidie`, `Aanvraag`, `Verleend`, `Ingetrokken`, `Gewijzigd`, `Vastgesteld`, `Uitbetaald`, `Teruggevorderd`, `Afbetalingsregeling`, `Vervaldatum`, `Termijn`, `Achterstallig`. Keys are English source strings per the company-wide i18n convention; Dutch translations live in `l10n/nl.json`.
