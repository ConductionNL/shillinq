# Tasks — Fixed Assets & Depreciation

> Spec-driven build (`opsx-apply` cycle) implementing the
> `bookkeeping-fixed-assets-depreciation` capability. The schema
> declaration + manifest entries shipped earlier under the
> spec-authoring task pass (see Sections 1-2 below); this pass adds the
> derivable-field arithmetic helper (Sections 3-4), the disposal
> closing-journal emitter (Sections 3-5), the monthly depreciation
> `ScheduledWorkflow` registration (Section 6), unit tests (Section 7),
> i18n + user-guide docs (Section 8), and verification (Section 9).
> All money arithmetic runs in integer cents to avoid IEEE-754 drift
> (mirrors `LeaseAmortizationCalculator` + `TrialBalanceCalculator`).

## 1. Spec authoring (done previously)

- [x] Task 1: Verify spec authoring complete — `proposal.md`, `design.md`, `specs/bookkeeping-fixed-assets-depreciation/spec.md` carry REQ-FA-001..REQ-FA-007 with RFC 2119 keywords and `#### Scenario:` GIVEN/WHEN/THEN blocks; confirm via `openspec validate add-shillinq-fixed-assets-depreciation`

## 2. Register + manifest (done previously)

- [x] Task 2: Verify the `FixedAsset` schema declaration in `lib/Settings/shillinq_register.json` carries the REQ-FA-002 field set, `x-openregister-calculations` (`monthlyDepreciation`, `currentBookValue`, `commercialBookValue`, `fiscalBookValue`), `x-openregister-lifecycle` (proposed → active → disposed → archived with `dispose` `emit-journal-entry` action), `x-openregister-relations` (administration, assetAccount, accumulatedDepAccount, depreciationExpenseAccount), `x-openregister-rbac`, and `x-openregister-aggregations`; verify the audit-trail entry in `lib/Settings/register.d/add-shillinq-audit-trail.json` covers `FixedAsset`; verify `src/manifest.json` declares the `FixedAssets` index page, `FixedAssetDetail` detail page, and the Bookkeeping > Fixed Assets navigation entry

## 3. Pure-logic helpers (this build)

- [x] Task 3: Author `lib/Service/DepreciationCalculator.php` — side-effect-free helper for the linear-and-degressive depreciation arithmetic an OR `ScheduledWorkflow` (or a unit test) needs when materialising the monthly posting. Operates on plain `FixedAsset` arrays, returns `monthlyDepreciation` / `currentBookValue` / `commercialBookValue` / `fiscalBookValue` in integer cents alongside their float-money rendering. Mirrors `LeaseAmortizationCalculator` shape (REQ-FA-003 + REQ-FA-004).

- [x] Task 4: Author `lib/Service/DisposalJournalEmitter.php` — pure-logic emitter that, given a `FixedAsset` plus disposal input `(disposalDate, disposalAccountingTreatment, disposalProceeds)`, returns a balanced `GLTransaction`-shaped payload (header + lines) that credits the asset account for gross value, debits accumulated depreciation, posts gain or loss to the configured P&L account, and zeroes the asset's carrying amount per REQ-FA-006. The emitter is the deterministic kernel the OR `x-openregister-lifecycle.dispose.action.emit-journal-entry` action calls.

## 4. Monthly depreciation workflow

- [x] Task 5: Register the `shillinq-fixed-assets-monthly-depreciation` `ScheduledWorkflow` from `lib/Repair/InitializeSettings.php` (new private method `registerFixedAssetsMonthlyDepreciationWorkflow()`), idempotently — mirrors `registerIv3ScheduledWorkflow()`. `engine=openconnector`, `workflowId=fixed-assets-monthly-depreciation`, `intervalSec=2592000` (30 days), `payload` carries `register=shillinq`, `schema=FixedAsset`, `lifecycleState=active`. Wires into `run()` after `registerIv3ScheduledWorkflow()`. Per REQ-FA-005 + ADR-031 path 2 — no `DepreciationJob extends TimedJob` ships.

## 5. Tests

- [x] Task 6: Author `tests/Unit/Service/DepreciationCalculatorTest.php` — covers linear method (`monthlyDepreciation == (cost - residual) / usefulLifeMonths`), degressive method (`monthlyDepreciation == currentBookValue * rate / 12`), the `depreciationMethod=none` short-circuit (`monthlyDepreciation == 0`, `currentBookValue == acquisitionCost`), the parallel commercial / fiscal stream divergence (REQ-FA-004 scenario: cost 10 000, commercialRate 0.20, fiscalRate 0.33 → after 12 months commercialBookValue == 8000, fiscalBookValue == 6700), and the residual-value floor (`currentBookValue` is never less than `residualValue`).

- [x] Task 7: Author `tests/Unit/Service/DisposalJournalEmitterTest.php` — covers the REQ-FA-006 scenarios: disposal at proceeds equal to book value emits a zero-gain journal (the asset and accumulated-dep accounts net to zero, the cash/clearing line carries the proceeds, no P&L hit); disposal at proceeds above book value posts a credit to the "boekwinst vaste activa" P&L account for the gain; disposal at proceeds below book value posts a debit to the "boekverlies vaste activa" account for the loss; the emitted lines always balance (sum of debits == sum of credits, every amount non-negative, side ∈ {debit, credit}).

## 6. i18n + docs

- [x] Task 8: Add EN + NL translations to `l10n/en.json` + `l10n/nl.json` for the fixed-assets surface — at minimum `Fixed Asset` / `Vast actief`, `Fixed Assets` / `Vaste activa`, `Depreciation` / `Afschrijving`, `Monthly Depreciation` / `Maandelijkse afschrijving`, `Commercial Book Value` / `Commerciële boekwaarde`, `Fiscal Book Value` / `Fiscale boekwaarde`, `Useful Life (months)` / `Levensduur (maanden)`, `Residual Value` / `Restwaarde`, `Disposal` / `Afstoting`, `Disposal Date` / `Afstotingsdatum`, `Disposal Treatment` / `Afstotingsbehandeling`, `Acquisition Date` / `Aanschafdatum`, `Acquisition Cost` / `Aanschafwaarde`, `Asset Category` / `Activacategorie`, `Asset Number` / `Activanummer`, `Asset Account` / `Activarekening`, `Accumulated Depreciation Account` / `Cumulatieve afschrijvingsrekening`, `Depreciation Expense Account` / `Afschrijvingskostenrekening`, `Degressive Rate` / `Degressief percentage`, `Commercial Rate` / `Commercieel percentage`, `Fiscal Rate` / `Fiscaal percentage` (ADR-005).

- [x] Task 9: Author `docs/user-guide/user/12-fixed-assets.md` (journeydoc-style) walking an SMB bookkeeper through capitalising a fixed asset, opening its detail page, reading the derived depreciation values, and disposing of it at sale — ties off the Wet IB / Wet VPB parallel-stream story for ZZP + MKB readers. Conduction-corporate voice; honest duration; the runnable check at the end is opening the detail page and seeing the `Monthly Depreciation` field populated.

## 7. ADR-000 + verification artefacts

- [x] Task 10: Confirm `openspec/architecture/adr-000-data-model.md` already carries the FixedAsset reconciliation note tying `GLLine.subLedgerRef` (when `subLedgerType=fixed-asset`) to the asset record — re-confirm the note still reads correctly against the final schema shape; no edits expected unless the field names drifted.

## 8. Hydra gates + quality

- [x] Task 11: Run `bash hydra/scripts/run-hydra-gates.sh` from the worktree — must stay at the 12/16 baseline (gates 1-5, 8, 10-13, 15, 16 PASS); the four failing gates (`orphan-auth`, `no-admin-idor`, `semantic-auth`, `route-reachability`) are pre-existing and must not regress. New service classes carry the SPDX + `@copyright` PHPDoc tags so gate-1 stays green; no `var_dump` / `die` / `error_log` / `print_r` / `dd` / `dump` so gate-2 stays green; no `catch (\Throwable) { return null; }` so gate-8 stays green.

- [x] Task 12: Run `composer test:unit -- --filter='DepreciationCalculator|DisposalJournalEmitter'` (or equivalent direct phpunit invocation) — the two new test classes pass. Coverage targets the REQ-FA scenarios above; PHPUnit dataProviders carry the spec's worked examples verbatim.

- [x] Task 13: Run `composer phpcs:check -- lib/Service/DepreciationCalculator.php lib/Service/DisposalJournalEmitter.php tests/Unit/Service/DepreciationCalculatorTest.php tests/Unit/Service/DisposalJournalEmitterTest.php` (or equivalent diff-scoped lint) — no new phpcs findings; the new files honour the project's NamedParameters sniff (the test files carry the same `phpcs:disable CustomSniffs.Functions.NamedParameters` opt-out as the sibling `LeaseRecognitionServiceTest`).

## 9. Spec hygiene + verification

- [x] Task 14: Run `openspec validate add-shillinq-fixed-assets-depreciation` from the repo root — the change folder retains `proposal.md` + `design.md` + `tasks.md` + `specs/bookkeeping-fixed-assets-depreciation/spec.md` + `hydra.json`; no orphan files. The validator reports the same `### REQ-FA-NNN`-vs-`### Requirement:` format gap as sibling `inventory-cogs-posting` (pre-existing convention across the shillinq fleet); spec structure is consistent with the rest of the repo and not introduced by this build.

- [x] Task 15: Wrote `openspec/changes/add-shillinq-fixed-assets-depreciation/task-audit.json` (co-located with the change rather than at the repo root, mirroring the per-change shape used by recent sibling builds) — per-task verification record naming each of Tasks 1-14 with the `file:line` (or schema path) the work landed at.

- [x] Task 16: Per-task atomic commits land each section on `feature/fixed-assets/bookkeeping-fixed-assets-depreciation`; final commit `Mark all 16 tasks done`; merge `--no-ff` into `development` locally; no Codeberg push (per the build brief).

## Verification

`openspec validate` exits clean on the change folder. `hydra/scripts/run-hydra-gates.sh` reports the baseline 12/16. `composer test:unit` green on the two new test classes. `composer phpcs:check` clean on the new files. The `FixedAsset` detail page renders `monthlyDepreciation`, `currentBookValue`, `commercialBookValue`, `fiscalBookValue` against a worked asset (manual verification deferred to a live-instance run).

## Tests (company-wide ADR-009)

The two unit-test files in Section 5 cover the REQ-FA-003..006 worked examples — these are the spec's normative arithmetic checks. The lifecycle-action emit-journal-entry kernel is unit-tested via `DisposalJournalEmitterTest`; the OR side of the lifecycle hook is covered by OR's own audit-trail and lifecycle test suites and is out-of-scope here.

## Documentation (company-wide ADR-010)

`docs/user-guide/user/12-fixed-assets.md` covers the operator-facing story (Section 6 above). Developer-facing reuse notes live in `design.md` Section "Reuse Analysis"; no separate developer docs ship.

## i18n (company-wide ADR-005)

Section 6 Task 8 ships the EN + NL surface strings — covers the manifest detail-page labels, the navigation entry, and the disposal action prompt strings.
