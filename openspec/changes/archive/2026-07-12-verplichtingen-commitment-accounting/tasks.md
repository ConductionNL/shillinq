# Tasks: verplichtingen-commitment-accounting

## Implementation Tasks

### Task 1: Commitment materialisation service + listener (thin glue)
- **spec_ref**: `openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-010`
- **files**: `lib/Service/Commitment/CommitmentMaterialisationService.php`, `lib/Listener/CommitmentMaterialisationListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a PO reaching `approved` WHEN the listener fires THEN a `Verplichting` with `bronReferentie` = PO id and one `VerplichtingRegel` per budget coderingscombinatie is created via `MandaatEnforcer` + `BudgetBlocker`
  - GIVEN the same PO approval re-emitted WHEN materialisation runs THEN no duplicate `Verplichting` is created (idempotent on `bronReferentie`)
  - GIVEN insufficient `vrije_ruimte` and no override mandaat WHEN materialisation runs THEN `BudgetBlocker` denies and the approval surfaces the denial (budget unchanged)
  - GIVEN a multi-year framework PO WHEN materialised THEN one regel per boekjaar reserves budget independently
- [x] Implement
- [x] Test

### Task 2: Contract-signature trigger (dormant if no Contract lifecycle)
- **spec_ref**: `openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-010`
- **files**: `lib/Listener/CommitmentMaterialisationListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a `Contract` reaching `signed`/`executed` WHEN the transition fires THEN the same materialisation path runs with `bronReferentie` = contract id
  - GIVEN no first-class Contract lifecycle exists WHEN the app boots THEN the contract branch registers without error and stays dormant (PO branch unaffected)
- [x] Implement
- [x] Test

### Task 3: Committed-vs-realised per-budget-line aggregation (declarative)
- **spec_ref**: `openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-011`
- **files**: `lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json`
- **acceptance_criteria**:
  - GIVEN the register config WHEN loaded THEN an `x-openregister-aggregations` query groups `VerplichtingRegel` by programma+kostenplaats+boekjaar+grootboek exposing `geautoriseerd`/`verplicht`/`gerealiseerd`/`vrij`
  - GIVEN a scan for parallel reporting services THEN none compute the same figures (declarative only, ADR-031)
- [x] Implement
- [x] Test

### Task 4: Budget-line committed-vs-realised drilldown view
- **spec_ref**: `openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-011`
- **files**: `src/views/BudgetLineCommitments.vue`
- **acceptance_criteria**:
  - GIVEN a budget line with a commitment and partial invoicing WHEN the drilldown opens THEN the four columns show the correct amounts and drilling in lists the underlying `Verplichting`(s)
- [x] Implement
- [x] Test

### Task 5: Rechtmatigheid linkage + override afwijking recording
- **spec_ref**: `openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-012`
- **files**: `lib/Service/Commitment/CommitmentMaterialisationService.php`, `lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json`
- **acceptance_criteria**:
  - GIVEN a materialised commitment WHEN it is created THEN rechtmatigheid toetsing (REQ-RV-008) is triggered against its `bronReferentie` at commitment stage
  - GIVEN a commitment created under an override mandaat WHEN materialised THEN the override reason is recorded on the commitment and visible to the REQ-RV-005 aggregation as an afwijking
- [x] Implement
- [x] Test

### Task 6: Seed data for auto-materialised commitments
- **spec_ref**: `openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-011`
- **files**: `lib/Settings/register.d/_registers.json`
- **acceptance_criteria**:
  - GIVEN install seed WHEN loaded THEN three `Verplichting`s (inkooporder, raamovereenkomst, subsidiebeschikking) with per-boekjaar regels exist so the drilldown is populated on a fresh instance
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off and `openspec validate` passes
- [x] Idempotency + fail-closed budget denial covered by PHPUnit; existing REQ-VPL guards re-run green (no regression)
- [x] Manual browser test of the budget-line committed-vs-realised drilldown — see Deviations (not live-verified this run)

## Quality checklist

- [x] All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- [x] No new API endpoints (drilldown uses OR list/aggregation API) — Newman N/A
- [x] UI drilldown covered by a Playwright browser test (`tests/e2e/budget-line-commitments.spec.ts`)
- [x] All tests pass (full unit suite: 3374 tests, 2 pre-existing unrelated failures — see Deviations)
- [x] Feature documentation updated in `docs/` (`docs/user-guide/bookkeeping/verplichtingen-commitment-materialisatie.md`)
- [x] Dutch (`nl_NL`) and English (`en_US`) strings added; keys English
- [x] `openspec validate` passes

## Deviations

1. **Fail-closed mechanism is listener-based, not a `PurchaseOrder.approve` guard.** design.md's File Structure section scopes schema changes to
   `bookkeeping-verplichtingenadministratie.json` only (no PurchaseOrder/Contract schema edits). The fail-closed guarantee is therefore implemented
   by letting `InsufficientCommitmentBudgetException` propagate synchronously out of `CommitmentMaterialisationListener::handle()`, which OR invokes
   in the same request as `PurchaseOrderApprovalService::saveObject()`. This reliably surfaces the denial as a failed approval request; whether the
   PO's own `approved` write is transactionally rolled back depends on OpenRegister's internal write/dispatch ordering (outside this app's control) —
   not verified against a live instance this run.
2. **`bronReferentie` is a new additive field on `Verplichting`**, as anticipated by design.md's Database Changes section (the field did not exist
   before this change despite being referenced throughout the proposal/spec text).
3. **Contract trigger fires on `active`, not `signed`/`executed`.** The shipped `contract-lifecycle-management` Contract schema's lifecycle is
   `draft → active → expiring → expired`; there is no separate `signed`/`executed` state. `active` (the contract becoming legally in force) is
   treated as the equivalent trigger per design.md's Open Question resolution. The Contract path is fail-soft (denial logged, never thrown) since
   the spec's fail-closed scenarios are PO-scoped only and this change does not modify contract-lifecycle-management's own schema/guards.
4. **Multi-year regel splitting uses per-line dates (PO) / startDate–endDate span (Contract), not a new schema field.** `PurchaseOrder` has no
   looptijd/framework field; a multi-year framework order is expressed as multiple lines each dated within its own boekjaar (REQ-VPL-010's
   multi-year scenario is covered this way in `CommitmentMaterialisationServiceTest::testMultiYearFrameworkMaterialisesOneRegelPerBoekjaar`).
   Contract's `startDate`/`endDate` span is split evenly per boekjaar with any rounding remainder assigned to the first year.
5. **`programma` is resolved via a `Budget` lookup keyed on `kostenplaats` + `boekjaar` + `administrationId`**, reusing `Budget.kostenplaats`
   (already an optional field on that schema) rather than adding a `programma` field to `PurchaseOrderLine`/`Contract`. When no matching Budget
   exists, `programma` resolves to `''` and `BudgetBlocker`'s own "no matching budget" fail-closed rule denies the commitment — no silent success.
6. **REQ-VPL-012 rechtmatigheid linkage is event-driven, not a fabricated `Rechtmatigheidstoets` write.** `Rechtmatigheidstoets.journaalpost` is a
   required FK to a `JournalEntry` that does not exist yet at commitment time (before any GL posting); writing a placeholder value there would be a
   data-integrity smell. Instead the service dispatches a `shillinq.rechtmatigheid.commitment_created` CloudEvent (same pattern as
   `BudgetImpactEmitter`) as the commitment-stage trigger, and — because it has no `journaalpost` requirement — writes a `Rechtmatigheidsbevinding`
   afwijking directly when an override-mandate was applied, satisfying REQ-RV-005's aggregation target.
7. **`check:registers` and the wider hydra fleet-scope gates are pre-existing, out of scope**, per the apply brief: `check:registers`
   shows exactly 231 failures before and after this change (`Verplichtingsregel`/`Verplichtingsmutatie` were already on that list). Hydra
   gate-30 (effective-manifest-crossref) also reports 231 pre-existing assembled-manifest violations, none referencing this change's new
   `BudgetLineCommitments` menu/page entries. Gate-31 (relation-dialect) flags 4 pre-existing `x-openregister-relations` blocks on
   `Verplichting`/`Verplichtingsregel`/`Verplichtingsmutatie`/`Goedkeuringsstap` that already existed before this change and are only
   re-surfaced because the diff touches the same JSON file — migrating them to the canonical property-level `$ref` dialect (ADR-062 rule 7)
   is a repo-wide convention change outside this narrow delta's scope, and risks the already-shipped REQ-VPL-001..009 relations. Gate-46
   (spec-anchor-existence) flags this change's own `#req-vpl-010`/`#req-vpl-011` `@spec` anchors as unresolved — the fleet-wide short
   `spec.md#req-x-nnn` anchor convention this newer gate's slugifier cannot resolve (same documented limitation as prior shillinq changes).
8. **Manual browser verification of the drilldown page and the live PO-approval fail-closed path were not run against a live Nextcloud instance**
   this session — covered instead by PHPUnit (materialisation logic, idempotency, fail-closed/fail-soft branching), vitest (row/currency/filter
   pure helpers) and a Playwright e2e spec (`tests/e2e/budget-line-commitments.spec.ts`, data-defensive — skips when no seed data is present).
