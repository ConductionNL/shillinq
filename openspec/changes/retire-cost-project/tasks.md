# Tasks — Retire Cost Project

> Deduplication-first per ADR-012: `CostProject` is the same analytical,
> GL-derived, budget-bearing object as `CostCenter` and is folded into it.
> Declarative-first per ADR-031/ADR-037: the cost-center-as-project view is a
> register/manifest change; budget-vs-actuals is GL-derived via existing
> `x-openregister-aggregations`. The only PHP shipped is a one-shot,
> idempotent, fail-safe `IRepairStep` migration. OpenProject linkage is an
> ADR-019 integration-registry reference property, not embedded data.
> Depends on `unify-analytical-dimensions` (must land first) and coordinates
> with `shillinq-nav-ia-cleanup`.

## Phase 0: Deduplication Check

- [ ] Task 1: Document the duplication explicitly. Confirm `CostProject` and
  `CostCenter` model the same analytical object: both `x-openregister-purpose`
  analytical/dimension, both derive costs/P&L from `GLLine` via
  `x-openregister-aggregations`, both budget-track, and `CostProject.costCenterCode`
  already FKs `CostCenter.code`. Confirm the only `CostProject`-unique fields
  are time-boxing (`startDate`/`endDate`) + `projectNumber` (both trivially
  additive on `CostCenter`). Confirm the RJ 270 / IFRS 15 `Project` register is
  a genuinely DIFFERENT object (revenue recognition: `recognisedRevenue`,
  `wipBalance`, `percentageComplete`, `totalContractValue`) and is NOT a
  duplicate — it stays. Confirm no `CostProjectService` / app-local PHP exists
  for `CostProject` (ADR-031 already honoured). Record findings here even if
  "no PHP service found".

- [ ] Task 2: Confirm the dependency on `unify-analytical-dimensions`: it must
  provide the `dimensionType` discriminator and the shared analytical budget
  field set on the unified `CostCenter` shape this change folds `CostProject`
  into. If that change is not yet authored/landed, this change is blocked on
  it; record the blocking relationship.

## Phase 1: MODIFY CostCenter (absorb project-budget + OpenProject link)

- [ ] Task 3: In the cost-centers register fragment
  (`lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json` or the
  `unify-analytical-dimensions` fragment, NOT the monolith) add to `CostCenter`
  per REQ-RCP-001: `totalBudget` (integer cents, ≥0, nullable),
  `totalEstimatedCosts` (integer cents, ≥0, nullable), `projectNumber`
  (string, optional, non-unique label), `startDate`, `endDate` (date,
  nullable). All additive/optional so a plain departmental cost center is
  unaffected.

- [ ] Task 4: Add the budget-vs-actuals derivation per REQ-RCP-001 by REUSING
  the existing `CostCenter.spentToDate` aggregation (GL-derived) as
  costs-incurred and declaring a `budgetVariance`
  `x-openregister-calculations` (`(totalBudget ?? 0) - (spentToDate ?? 0)`),
  mirroring the retired `CostProject.budgetVariance`. No new aggregation, no
  PHP.

- [ ] Task 5: Add `externalProjectRef` to `CostCenter` per REQ-RCP-002 as an
  ADR-019 integration-registry reference property: `referenceType: openproject`,
  storing an OpenProject project id/URL + cached display label only. Document
  that resolution routes through OR's `ExternalIntegrationRouter` + the
  OpenProject `IntegrationProvider` (external storage strategy,
  OpenConnector-backed), and that shillinq stores NO OpenProject delivery
  data. Mark cardinality 0..1.

## Phase 2: REMOVE CostProject (register + seeds)

- [ ] Task 6: Remove the `CostProject` schema from the built register per
  REQ-RCP-003 (drop from `lib/Settings/shillinq_register.json` / fragment, or
  mark `x-openregister-deprecated` and exclude from the built set). Ensure the
  removal happens only after the migration step (Phase 4) reports zero
  unmigrated objects in a dry-run.

- [ ] Task 7: Retarget the cost-project seed rows per REQ-RCP-003: in
  `lib/Settings/seeds/project-templates.json` and
  `lib/Settings/seeds/cost-center-templates.json`, convert any `CostProject`
  seed objects to `CostCenter` objects (project-flavoured `dimensionType`,
  budget fields, minted `code`). No `CostProject` objects are seeded after
  this change.

## Phase 3: REMOVE CostProjects navigation (keep Project single-home)

- [ ] Task 8: Remove the `CostProjects` menu leaf from `src/manifest.json` per
  REQ-RCP-004 and add `"CostProjects"` to `src/menu-layout.json` `removals`
  (the page stays routable for deep links per the `removals` contract). Retire
  the `CostProjectDetail` page definition (nothing links to it post-migration).

- [ ] Task 9: Ensure the RJ 270 / IFRS 15 `Project` register has exactly ONE
  nav home per REQ-RCP-004: keep `Projects` (Bookkeeping) OR `ProjectenOverzicht`
  (People & Projects), not both, via a `menu-layout.json` relocation/removal
  coordinated with `shillinq-nav-ia-cleanup`. The `Project` SCHEMA is NOT
  modified.

## Phase 4: MIGRATE existing CostProject objects

- [ ] Task 10: Implement `lib/Repair/RetireCostProjectStep.php` per REQ-RCP-005
  as an `IRepairStep`, registered in `appinfo/info.xml`. For each existing
  `CostProject` (via the real OR `ObjectService` find/findAll), create a
  `CostCenter` mapping: `projectNumber`→`projectNumber` + minted
  `code = CP-<projectNumber>`; `name`/`description`/`startDate`/`endDate`/
  `totalBudget`/`totalEstimatedCosts`/`administrationId`/`organizationId`
  straight across; `costCenterCode`→`parentCode`; `lifecycleState` mapped
  (`draft|active|on-hold → active`, `closed|archived → archived`);
  `dimensionType = project`; `externalProjectRef` empty;
  `costsIncurredToDate` dropped (re-derived as `spentToDate`). SPDX headers +
  `@spec` annotations.

- [ ] Task 11: Make the step idempotent per REQ-RCP-005: write a
  `migratedFrom: <costProjectId>` marker on the created cost center; on re-run,
  skip any `CostProject` whose marker already exists. Re-runs are no-ops.

- [ ] Task 12: Make the step fail-safe per REQ-RCP-005: a `CostProject` that
  cannot be mapped (missing required CostCenter field, irrecoverable `code`
  collision after suffix disambiguation) is logged with the reason and LEFT
  IN PLACE — never deleted. The step deletes NO `CostProject` objects; source
  removal is a later operator-confirmed step. Mint `code` namespaced (`CP-…`),
  append a disambiguating suffix on collision, and report it (never silent
  overwrite).

- [ ] Task 13: Unit-test the migration
  (`tests/Unit/Repair/RetireCostProjectStepTest.php`) per REQ-RCP-005: field
  mapping correctness, lifecycle mapping table, `costsIncurredToDate` drop,
  `code` minting + collision disambiguation, idempotent re-run (no duplicate
  cost centers), fail-safe skip (unmappable source left in place, not
  deleted).

## Phase 5: Frontend (cost-center-as-project view + OpenProject link)

- [ ] Task 14: Surface the project-flavoured cost-center view per REQ-RCP-001/002:
  show `totalBudget` / `spentToDate` / `budgetVariance` and the `startDate`–
  `endDate` window on the CostCenter detail page when `dimensionType = project`;
  render `externalProjectRef` via the ADR-019 integration registry
  (`referenceType: openproject` → OpenProject `single-entity` widget inline).
  Any `NcSelect` carries `inputLabel`; modals/dialogs in their own files under
  `src/modals/` / `src/dialogs/`; initial state (if any) via `IInitialState` +
  `loadState()` (ADR-004 gates).

## Phase 6: i18n

- [ ] Task 15: Add new strings with ENGLISH source keys to `l10n/en.json` and
  Dutch translations to `l10n/nl.json` per REQ-RCP-001/002 (CostCenter
  project-budget field labels, "Linked OpenProject project", budget-variance
  labels); verify the l10n gate and no Dutch source keys in `t('shillinq', …)`.

## Phase 7: Tests, Gates, Docs

- [ ] Task 16: Author Playwright e2e UI specs (gate-19, UI-only — API
  assertions go to Newman) per REQ-RCP-004/006: the `CostProjects` nav entry is
  ABSENT; a project-flavoured cost center shows budget-vs-actuals and the
  OpenProject link affordance; the `Project` register has exactly one nav home.
  Annotate scenarios with `@e2e` references; reason-bearing `@e2e exclude` only
  for true backend scenarios.

- [ ] Task 17: Add Newman integration assertions
  (`tests/integration/*.postman_collection.json`) per REQ-RCP-003/005: creating
  a `CostProject` object via the OR surface is rejected/unavailable (schema
  removed); a migrated `CostCenter` carries the expected project fields +
  `migratedFrom` marker; `spentToDate` derives from GL on the migrated cost
  center.

- [ ] Task 18: Run `composer check:strict` + the full hydra gate suite (spdx,
  spec-coverage `@spec` on the repair step, route-auth for any new routes,
  notification-dialect, e2e-coverage, redundant-controller) and fix everything
  including pre-existing issues encountered; update `docs/` and the README
  (cost projects are now project-flavoured cost centers optionally linked to
  OpenProject; the revenue-recognition Project register is unchanged); bump
  `appinfo/info.xml` `<version>` (bundle-affecting change). Confirm the
  migration ran clean (zero unmigrated) before the `CostProject` schema is
  dropped from the built set.
