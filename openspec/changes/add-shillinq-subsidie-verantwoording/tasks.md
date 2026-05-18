# Tasks — Subsidie Verantwoording

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the `bookkeeping-subsidie-verantwoording`
> spec — they are recorded now so the spec-review gate, dependency
> planning, and tier-cascade impact are all visible at proposal time. No
> source files are edited by this change itself.

## Tasks

- [ ] Task 1: Confirm no `Subsidie`/`RepaymentInstallment` schema and no `bookkeeping-subsidie-verantwoording` capability already exists (scan `lib/Settings/shillinq_register.json`, `openspec/specs/**`, `adr-000-data-model.md`)
- [ ] Task 2: Author `specs/bookkeeping-subsidie-verantwoording/spec.md` with `Status: proposed` / `Scope: shillinq` / `Tier: T3 (operations + NL compliance core)` / `Depends on: bookkeeping-general-ledger (T1)` header, `REQ-SUB-NNN` requirements with RFC 2119 keywords, `#### Scenario:` GIVEN/WHEN/THEN blocks
- [ ] Task 3: Author `proposal.md` referencing the shared `nextcloud-app` spec; include Affected Projects / Scope / Risks / Rollback / Open Questions per shillinq config.yaml `rules.proposal`
- [ ] Task 4: Author `design.md` with Reuse Analysis, Seed Data, and Declarative-vs-imperative decision tables; document D2 (terugvordering as FK register not parallel state machine) and D3 (uitbetaling journal entry `pending`)
- [ ] Task 5: Declare the `Subsidie` schema in `lib/Settings/shillinq_register.json` with all REQ-SUB-002 fields (subsidienummer, recipientId, amount, programLabel, state, aangevraagdOp, verleendOp, vastgesteldOp, uitbetaaldOp, beschikkingUri, parentSubsidieId, administrationId)
- [ ] Task 6: Add `x-openregister-lifecycle` to `Subsidie` declaring the 8 transitions per REQ-SUB-003 (aanvraag → verleend, verleend → ingetrokken, verleend → gewijzigd → verleend, verleend → vastgesteld, vastgesteld → uitbetaald, uitbetaald → teruggevorderd, teruggevorderd → in-afbetalingsregeling, in-afbetalingsregeling → uitbetaald) with `requires.approval-workflow` on `verleen` and `terugvorder`
- [ ] Task 7: Declare the `RepaymentInstallment` schema with all REQ-SUB-007 fields (subsidieId, installmentNumber, dueDate, amount, state, paidOn); state enum `scheduled / paid / overdue / written-off`
- [ ] Task 8: Declare the `vastgesteld → uitbetaald` post-transition action: create a `JournalEntry` in `state: pending` (NEVER auto-posted), with accountant approval gate per REQ-SUB-005
- [ ] Task 9: Declare `x-openregister-notifications` firing on vervaldatum approaching, instalment overdue, terugvordering due per REQ-SUB-006
- [ ] Task 10: Ship `lib/Settings/seeds/asv-model-lifecycle.json` (6+ canonical lifecycle states with their Awb article citations) with SPDX header + `_meta.source: "Awb 4.2 + VNG ASV-model 2022"` per REQ-SUB-006
- [ ] Task 11: Extend the repair step under `lib/Migration/` to import the ASV-model seed idempotently
- [ ] Task 12: Add `Subsidies > Aanvragen` and `> Terugvorderingen` navigation + pages to `src/manifest.json` with `type: index` + `type: detail` per REQ-SUB-008; `node tests/validate-manifest.js` exits 0
- [ ] Task 13: Update `openspec/architecture/adr-000-data-model.md` with the 2 new entities (`Subsidie`, `RepaymentInstallment`) and their `Primary spec:` references

## Verification

`openspec validate` must exit clean on the change folder. Subsidie-administrateur-persona peer review confirms the 8-state lifecycle, terugvordering settlement-plan shape, and uitbetaling journal safety constraint match Awb 4.2 + VNG ASV-model guidance. Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031 compliance (declarative lifecycle; settlement plan as FK register not parallel state machine; uitbetaling journal `pending` not `posted`; no app-local approval table). No source code changes outside `openspec/changes/add-shillinq-subsidie-verantwoording/`.

## Tests (company-wide ADR-009)

Spec-only change — no business logic ships here. The implementation cycle (separate `opsx-apply`) is responsible for: PHPUnit unit tests covering all 8 lifecycle transitions, approval-gates honoured on verleen + terugvorder, uitbetaling journal is `pending` not `posted`, instalment overdue notification fires; Playwright MCP browser tests for the 2 new index/detail pages including the terugvordering drill-down; `composer test` green at the implementing PR's CI gate.

## Documentation (company-wide ADR-010)

Spec-only change — no user-facing docs ship here. The implementation cycle authors `docs/user-guide/bookkeeping/subsidie.md` per ADR-030 journeydoc convention and commits a subsidie lifecycle screenshot to `docs/images/`.

## i18n (company-wide ADR-005)

Spec-only change — no user-facing strings ship here. The implementation cycle adds Dutch (`nl_NL`) and English (`en_US`) translation strings for: `Subsidie`, `Aanvraag`, `Verleend`, `Ingetrokken`, `Gewijzigd`, `Vastgesteld`, `Uitbetaald`, `Teruggevorderd`, `Afbetalingsregeling`, `Vervaldatum`, `Termijn`, `Achterstallig`.
