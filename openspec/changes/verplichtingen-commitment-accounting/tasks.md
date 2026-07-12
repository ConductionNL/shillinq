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
- [ ] Implement
- [ ] Test

### Task 2: Contract-signature trigger (dormant if no Contract lifecycle)
- **spec_ref**: `openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-010`
- **files**: `lib/Listener/CommitmentMaterialisationListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN a `Contract` reaching `signed`/`executed` WHEN the transition fires THEN the same materialisation path runs with `bronReferentie` = contract id
  - GIVEN no first-class Contract lifecycle exists WHEN the app boots THEN the contract branch registers without error and stays dormant (PO branch unaffected)
- [ ] Implement
- [ ] Test

### Task 3: Committed-vs-realised per-budget-line aggregation (declarative)
- **spec_ref**: `openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-011`
- **files**: `lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json`
- **acceptance_criteria**:
  - GIVEN the register config WHEN loaded THEN an `x-openregister-aggregations` query groups `VerplichtingRegel` by programma+kostenplaats+boekjaar+grootboek exposing `geautoriseerd`/`verplicht`/`gerealiseerd`/`vrij`
  - GIVEN a scan for parallel reporting services THEN none compute the same figures (declarative only, ADR-031)
- [ ] Implement
- [ ] Test

### Task 4: Budget-line committed-vs-realised drilldown view
- **spec_ref**: `openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-011`
- **files**: `src/views/BudgetLineCommitments.vue`
- **acceptance_criteria**:
  - GIVEN a budget line with a commitment and partial invoicing WHEN the drilldown opens THEN the four columns show the correct amounts and drilling in lists the underlying `Verplichting`(s)
- [ ] Implement
- [ ] Test

### Task 5: Rechtmatigheid linkage + override afwijking recording
- **spec_ref**: `openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-012`
- **files**: `lib/Service/Commitment/CommitmentMaterialisationService.php`, `lib/Settings/register.d/bookkeeping-verplichtingenadministratie.json`
- **acceptance_criteria**:
  - GIVEN a materialised commitment WHEN it is created THEN rechtmatigheid toetsing (REQ-RV-008) is triggered against its `bronReferentie` at commitment stage
  - GIVEN a commitment created under an override mandaat WHEN materialised THEN the override reason is recorded on the commitment and visible to the REQ-RV-005 aggregation as an afwijking
- [ ] Implement
- [ ] Test

### Task 6: Seed data for auto-materialised commitments
- **spec_ref**: `openspec/changes/verplichtingen-commitment-accounting/specs/bookkeeping-verplichtingenadministratie/spec.md#req-vpl-011`
- **files**: `lib/Settings/register.d/_registers.json`
- **acceptance_criteria**:
  - GIVEN install seed WHEN loaded THEN three `Verplichting`s (inkooporder, raamovereenkomst, subsidiebeschikking) with per-boekjaar regels exist so the drilldown is populated on a fresh instance
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off and `openspec validate` passes
- [ ] Idempotency + fail-closed budget denial covered by PHPUnit; existing REQ-VPL guards re-run green (no regression)
- [ ] Manual browser test of the budget-line committed-vs-realised drilldown

## Quality checklist

- All new/changed business logic covered by PHPUnit unit tests (`tests/Unit/`)
- No new API endpoints (drilldown uses OR list/aggregation API) — Newman N/A
- UI drilldown covered by a Playwright browser test (`BudgetLineCommitments*.spec.js`)
- All tests pass (`composer test`)
- Feature documentation updated in `docs/` (commitment auto-materialisation + budget-line report, ADR-010)
- Dutch (`nl_NL`) and English (`en_US`) strings added; keys English. NL: "Verplichting" (commitment), "Geautoriseerd" (authorized), "Verplicht" (committed), "Gerealiseerd" (realised), "Vrije ruimte" (available), "Budgetregel" (budget line)
- `openspec validate` passes
