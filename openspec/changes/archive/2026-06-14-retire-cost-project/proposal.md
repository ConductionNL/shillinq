# Proposal: retire-cost-project

`kind: deduplication + config + integration` per ADR-012/ADR-037/ADR-019 —
retires the redundant `CostProject` register by folding its
management-accounting fields into the existing `CostCenter` analytical
dimension, adds an ADR-019 integration-registry link to an external
**OpenProject** delivery project (`externalProjectRef`), keeps the genuine
RJ 270/IFRS 15 revenue-recognition `Project` register untouched, and migrates
existing `CostProject` objects via an `lib/Repair/*` data-migration step.
No new PHP business logic (ADR-022/ADR-031).

## Summary

Shillinq today carries **two** project registers plus a duplicated project
navigation:

1. **`Project`** — the RJ 270 / IFRS 15 **revenue-recognition** consultancy
   project (POC, WIP, billing, `recognisedRevenue`, `percentageComplete`,
   `totalContractValue`, `wipBalance`, `recognitionMethod`). This is genuine
   accounting logic and **stays**.
2. **`CostProject`** — a **management-accounting** analytical project
   (`totalBudget`, `totalEstimatedCosts`, `costsIncurredToDate`,
   `costCenterCode`). Its own schema description states its costs/P&L are
   "derived from GL via x-openregister-aggregations" and that it is
   "**distinct from the RJ 270 revenue-recognition Project register**". In
   substance this is a **time-boxed cost collector with a budget** — i.e. a
   variant of `CostCenter` (the analytical `kostenplaats` dimension), which
   already derives `spentToDate` / `segmentPnl` from the GL the same way and
   already carries the `costCenterCode` link `CostProject` points at.

Per the **ADR-012 deduplication** rule, two registers that model the same
thing (an analytical, GL-derived, budget-tracked grouping of cost) MUST NOT
coexist. Ruben's decision frames it directly: *"I don't understand why we
have Projects and Cost Projects — can we just attach an OpenProject project
to a cost center?"* — **yes**. The management-accounting "cost project" view
is expressed as **a `CostCenter` (analytical dimension) optionally linked to
an external OpenProject delivery project**, NOT a second project register.

This change therefore:

- **RETIRES** the `CostProject` register and its `CostProjects` /
  `CostProjectDetail` navigation (the page stays routable for deep links per
  the established `menu-layout.json` `removals` pattern).
- **MODIFIES** `CostCenter` to absorb the project-budget fields
  (`totalBudget`, `totalEstimatedCosts`, optional `projectNumber`,
  `startDate`/`endDate`) so a cost center can play the time-boxed
  cost-collector role, coordinated with the sibling change
  `unify-analytical-dimensions` (CostCenter / KostenDrager / project-flavoured
  dimension unification).
- **ADDS** an `externalProjectRef` property on the analytical dimension
  carrying an OpenProject project id/URL, rendered and dispatched through the
  **ADR-019 integration registry** (`referenceType: openproject`, external
  storage strategy via OpenConnector). Delivery/planning data lives in
  OpenProject; budget-vs-actuals stays in shillinq, derived from the GL.
- **KEEPS** the `Project` register and ensures it has **one** nav home
  (coordinated with `shillinq-nav-ia-cleanup`).
- **MIGRATES** existing `CostProject` objects → `CostCenter` rows (preserving
  `projectNumber`, budget fields, `costCenterCode` lineage, optional
  `externalProjectRef`) via a fail-safe `lib/Repair/*` step — no data is
  silently dropped.

**Depends on:**
- `unify-analytical-dimensions` (the CostCenter / KostenDrager /
  project-flavoured analytical-dimension unification this change folds
  `CostProject` into; provides the `dimensionType` discriminator and the
  shared budget field set). **Hard dependency** — `CostProject`'s fields land
  on the unified `CostCenter` shape defined there.
- `shillinq-nav-ia-cleanup` (the single-nav-home cleanup that this change
  coordinates with so `Project` keeps exactly one navigation entry and the
  `CostProjects` leaf is removed cleanly).
- `bookkeeping-cost-centers-dimensions` (owns `CostCenter`,
  `AnalyticalDimension`, and the GLLine segment-P&L aggregations — REQ-CC-*,
  REQ-CD-*; the budget-vs-actuals view reuses `CostCenter.spentToDate` /
  `segmentPnl`).
- `bookkeeping-consolidation-commercial` / `bookkeeping-ifrs15-revenue`
  (own the RJ 270 / IFRS 15 `Project` register that this change explicitly
  preserves untouched).
- ADR-019 integration registry + an OpenProject integration provider
  (external storage strategy, OpenConnector-backed) for `externalProjectRef`.

## Motivation

`CostProject` and `CostCenter` are the same accounting object with cosmetic
differences:

| Concern | `CostProject` | `CostCenter` |
|---|---|---|
| Purpose | `analytical` | `dimension` (analytical) |
| Costs/P&L source | derived from GL aggregations | derived from GL aggregations |
| Budget tracking | `totalBudget` + variance calc | `budget` + `allocatedBudget` calc |
| GL link | `subLedgerType='cost-project'` tag | `costCenterCode` on `GLLine` |
| Hierarchy | flat | `parentCode` self-relation |
| Department link | `costCenterCode` → `CostCenter` | self |

The only material thing `CostProject` adds over `CostCenter` is **time-boxing**
(`startDate`/`endDate`) and an operator-facing `projectNumber` — both of which
are trivially additive properties on `CostCenter`, not a reason for a second
register. Meanwhile `CostProject` *under-delivers*: no hierarchy roll-up, a
parallel `subLedgerType='cost-project'` GL-tagging scheme that competes with
the canonical `costCenterCode` dimension, and a second nav home for "projects"
that confuses it with the revenue-recognition `Project`.

The user-facing confusion ("why do we have Projects AND Cost Projects?") is
the symptom; the architectural defect is a duplicated analytical register.
Folding it into `CostCenter` removes the duplication, removes the parallel
GL-tagging scheme, and gives the time-boxed-cost-collector use case real
hierarchy roll-up for free. Where a cost center genuinely tracks a delivery
project, the **delivery** side (tasks, planning, milestones, % delivered)
belongs in **OpenProject** — a purpose-built tool — reached via the ADR-019
registry, not re-modelled in a finance app.

## Affected Projects

- [x] Project: shillinq — `CostCenter` schema gains project-budget fields +
  `externalProjectRef`; `CostProject` schema + `CostProjects`/`CostProjectDetail`
  nav removed; `lib/Repair/*` migration; manifest + `menu-layout.json` edits;
  i18n.
- [ ] Project: openregister — consumer only (object surface, lifecycle,
  aggregations, ADR-019 registry, `ExternalIntegrationRouter`); no OR changes.
- [ ] Project: openconnector — provides the OpenProject external connection
  the OpenProject integration provider routes through; configuration only,
  no code change owned by this shillinq change.

## Scope

### In Scope

- **MODIFY `CostCenter`** (in the `unify-analytical-dimensions` /
  `bookkeeping-cost-centers-dimensions` register fragment, NOT the monolith):
  add `totalBudget`, `totalEstimatedCosts`, optional `projectNumber`,
  `startDate`, `endDate`, and `externalProjectRef`; reuse the existing
  GL-derived `spentToDate` / `segmentPnl` aggregations and the
  `allocatedBudget` calculation for budget-vs-actuals. A cost center flagged
  as the project-flavoured `dimensionType` (from `unify-analytical-dimensions`)
  is the canonical replacement for a `CostProject`.
- **ADD `externalProjectRef`** as an ADR-019 integration-registry reference
  property (`referenceType: openproject`): an OpenProject project id/URL that
  OR's `ExternalIntegrationRouter` resolves through the OpenProject provider
  (external storage strategy, OpenConnector-backed). shillinq stores only the
  reference + cached display label, never OpenProject's delivery data.
- **REMOVE the `CostProject` register**: drop the schema from the register
  fragment / monolith; mark it superseded; stop seeding `CostProject`
  templates (`lib/Settings/seeds/project-templates.json` cost-project rows
  retargeted to `CostCenter`).
- **REMOVE the `CostProjects` + `CostProjectDetail` navigation**: delete the
  `CostProjects` menu leaf in `src/manifest.json` and add `CostProjects` to
  `src/menu-layout.json` `removals`; the `CostProjectDetail` page definition
  is retired. Per the `menu-layout.json` `removals` contract, any residual
  route stays resolvable for deep links / e2e but is absent from navigation.
- **KEEP `Project`** (RJ 270 / IFRS 15) entirely untouched as a schema, and
  ensure it has exactly **one** nav home: keep `Projects` (Bookkeeping) OR
  `ProjectenOverzicht` (People & Projects) — not both — via a
  `menu-layout.json` relocation/removal coordinated with
  `shillinq-nav-ia-cleanup`.
- **MIGRATE** existing `CostProject` objects to `CostCenter` rows in a
  `lib/Repair/RetireCostProjectStep.php` step: map `projectNumber`→`projectNumber`,
  `name`→`name`, `description`→`description`, `startDate`/`endDate`→same,
  `totalBudget`→`totalBudget`, `totalEstimatedCosts`→`totalEstimatedCosts`,
  `costsIncurredToDate` is dropped (re-derived from GL on read),
  `administrationId`→`administrationId`, `organizationId`→`organizationId`,
  `costCenterCode`→`parentCode` (the migrated cost center nests under the
  department it pointed at), `lifecycleState` mapped to the CostCenter
  lifecycle (`active|on-hold→active`, `closed|archived→archived`,
  `draft→active`). A synthetic `code` is minted from `projectNumber`. The
  optional `externalProjectRef` is left empty (operator links OpenProject
  later). The step is idempotent (keyed on a `migratedFrom` marker) and
  fail-safe (never deletes a `CostProject` whose mapping fails — it logs and
  skips, leaving the source object for manual review).
- **i18n**: ENGLISH source keys; `nl` + `en` catalogs for the new CostCenter
  field labels and the OpenProject-link UI.

### Out of Scope

- **The RJ 270 / IFRS 15 `Project` register** — preserved verbatim; this
  change must not touch its revenue-recognition fields, lifecycle, or
  aggregations. (`KEEP`, not `MODIFY`.)
- **The unification of `CostCenter` / `KostenDrager` / project-flavoured
  dimension** — owned by `unify-analytical-dimensions`; this change *depends
  on* its `dimensionType` discriminator and shared budget shape and does not
  re-specify it.
- **Building the OpenProject integration provider** (PHP `IntegrationProvider`
  + frontend tab/widget + OpenConnector source) — owned by an OpenRegister /
  integration-leaf change per ADR-019; here we only declare the
  `externalProjectRef` reference property and its `referenceType: openproject`
  binding, and document the dependency.
- **The broader nav IA reshuffle** — owned by `shillinq-nav-ia-cleanup`; this
  change contributes only the `CostProjects` removal and the single-`Project`-
  home decision.
- **Re-tagging historical `GLLine` rows** that used
  `subLedgerType='cost-project'` — the migration maps the *register* objects;
  GL lines remain queryable, and a follow-up consolidation can collapse the
  `subLedgerType='cost-project'` tag onto `costCenterCode` (noted as an open
  question).

## Approach

1. **`unify-analytical-dimensions` lands first** (hard dependency), giving
   `CostCenter` its `dimensionType` discriminator and the shared analytical
   budget field set.
2. **MODIFY `CostCenter`**: add the project-budget + `externalProjectRef`
   properties to the cost-centers register fragment; reuse the existing
   GL-derived aggregations for budget-vs-actuals.
3. **REMOVE `CostProject`**: drop the schema, retarget its seed templates to
   `CostCenter`, delete the `CostProjects`/`CostProjectDetail` manifest nav,
   add `CostProjects` to `menu-layout.json` `removals`.
4. **MIGRATE**: `lib/Repair/RetireCostProjectStep.php` converts existing
   objects through the real OR `ObjectService` surface (find/saveObject),
   idempotent + fail-safe, never silently dropping data.
5. **KEEP `Project`** untouched; collapse it to one nav home via
   `menu-layout.json` (coordinated with `shillinq-nav-ia-cleanup`).
6. **OpenProject linkage** is exercised through the ADR-019 registry once the
   OpenProject provider is configured; until then `externalProjectRef` is an
   inert, valid-but-empty reference property (registry permissive-on-read).

Specs: one spec file `retire-cost-project` with REQ-RCP-001 … REQ-RCP-006.

## New Dependencies

None at the code level. `externalProjectRef` resolution rides OR's existing
`ExternalIntegrationRouter` + an OpenProject `IntegrationProvider`
(OpenConnector-backed); no new PHP dependency in shillinq. The migration uses
the existing OR `ObjectService` and the standard `IRepairStep` surface.

## Impact

- `lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json` (or the
  `unify-analytical-dimensions` fragment) — MODIFY `CostCenter`: add
  `totalBudget`, `totalEstimatedCosts`, `projectNumber`, `startDate`,
  `endDate`, `externalProjectRef` (with `referenceType: openproject`).
- `lib/Settings/shillinq_register.json` / register fragment — REMOVE the
  `CostProject` schema (or mark `x-openregister-deprecated` + drop from the
  built register set).
- `lib/Settings/seeds/project-templates.json`,
  `lib/Settings/seeds/cost-center-templates.json` — retarget cost-project seed
  rows to `CostCenter`.
- `src/manifest.json` — REMOVE the `CostProjects` menu leaf and the
  `CostProjects` / `CostProjectDetail` page definitions.
- `src/menu-layout.json` — add `"CostProjects"` to `removals`; relocate/remove
  one of `Projects` / `ProjectenOverzicht` so `Project` has a single home
  (coordinated with `shillinq-nav-ia-cleanup`).
- `lib/Repair/RetireCostProjectStep.php` — NEW idempotent, fail-safe migration
  (registered as an `IRepairStep` in `appinfo/info.xml`).
- `l10n/en.json`, `l10n/nl.json` — new CostCenter project-field labels +
  OpenProject-link strings (ENGLISH source keys).
- `tests/Unit/Repair/RetireCostProjectStepTest.php` — migration mapping,
  idempotency, fail-safe-skip, lifecycle mapping.
- `tests/e2e/` — cost-center-as-project view, OpenProject link affordance,
  absence of the `CostProjects` nav entry (gate-19).

## Cross-Project Dependencies

- **unify-analytical-dimensions** — provides the unified `CostCenter` shape
  (`dimensionType`, shared budget fields) this change folds `CostProject`
  into. Must land first.
- **shillinq-nav-ia-cleanup** — owns the single-nav-home decision for
  `Project`; this change contributes the `CostProjects` removal.
- **bookkeeping-cost-centers-dimensions** — owns `CostCenter` /
  `AnalyticalDimension` and the GLLine segment-P&L aggregations reused for
  budget-vs-actuals.
- **ADR-019 OpenProject integration provider** (OpenRegister + OpenConnector)
  — resolves `externalProjectRef`; shillinq declares the reference property
  and consumes the provider, it does not own it.

## Risks

### Risk 1: Loss of cost-project data during migration

**Severity**: High
**Mitigation**: `RetireCostProjectStep` is fail-safe — every `CostProject`
that cannot be mapped (missing required CostCenter fields, code collision) is
logged and **left in place**, never deleted; the schema is dropped from the
built register only after the step reports zero unmigrated objects in a
dry-run. The step is idempotent (`migratedFrom` marker) so re-runs are
no-ops. `costsIncurredToDate` is intentionally not copied (it is a read-time
GL derivation on `CostCenter.spentToDate`), so no stored figure is lost.

### Risk 2: `code` collisions when minting CostCenter codes from projectNumber

**Severity**: Medium
**Mitigation**: the minted `code` is `CP-<projectNumber>` namespaced to avoid
collision with existing department cost-center codes; on collision the step
appends a disambiguating suffix and records the original `projectNumber` so
the cost center remains findable. Collisions are reported, never silently
overwritten.

### Risk 3: OpenProject provider not yet available when externalProjectRef ships

**Severity**: Low
**Mitigation**: ADR-019's registry is permissive-on-read for
not-yet-registered ids — `externalProjectRef` is a valid, inert reference
property until the OpenProject provider is configured. No cost-center
function depends on it; budget-vs-actuals is fully GL-derived inside shillinq.

### Risk 4: Deep links / e2e specs targeting the removed CostProjects route

**Severity**: Low
**Mitigation**: per the `menu-layout.json` `removals` contract the route
stays resolvable for deep links even though the nav entry is gone; e2e specs
that asserted the nav entry are updated to assert its **absence** plus the
presence of the project-flavoured cost-center view.

## Rollback Strategy

**During implementation (before merge):** revert the implementing PR — the
`CostCenter` field additions are additive, the `CostProject` removal and nav
edits are reverted together, and the `IRepairStep` is removed before it runs
in any environment that matters.

**Post-merge, before adoption:** re-declaring the `CostProject` schema and
restoring the `CostProjects` nav leaf + `CostProjectDetail` page (a single
revert) brings the register back; the additive `CostCenter` fields are inert
if unused.

**Production, after migration ran:** the migration is non-destructive — it
*creates* CostCenter rows and marks (does not delete) source CostProjects;
restoring the `CostProject` register makes the originals visible again, and
the duplicate migrated cost centers can be archived. After confidence, a
follow-up step deletes the marked-migrated `CostProject` objects.

## Open Questions

1. **`subLedgerType='cost-project'` GL tags** — historical GL lines tagged to
   cost projects via `subLedgerType`/`subLedgerRef` keep working, but should
   a follow-up collapse them onto `costCenterCode` so there is a single GL
   dimension? Leaning yes, deferred to a `consolidate-gl-dimensions` change.
2. **`projectNumber` uniqueness on CostCenter** — do migrated project numbers
   need to stay globally unique (operator-facing), or is the namespaced
   `code` sufficient? Spec assumes `projectNumber` is an optional,
   non-unique label and `code` is the unique key; confirm with ops.
3. **OpenProject link cardinality** — one cost center ↔ one OpenProject
   project, or many? Spec assumes 0..1 (`externalProjectRef` is a single
   reference); a programme of projects maps to a parent cost center with
   per-child links if needed.
