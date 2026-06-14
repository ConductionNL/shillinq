# Spec: retire-cost-project

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (operations)
**Depends on:**
- `unify-analytical-dimensions` (unified `CostCenter` shape; `dimensionType` discriminator; shared analytical budget field set — **must land first**)
- `shillinq-nav-ia-cleanup` (single-nav-home decision for the RJ 270 / IFRS 15 `Project` register)
- `bookkeeping-cost-centers-dimensions` (owns `CostCenter` / `AnalyticalDimension` + the GLLine segment-P&L aggregations reused for budget-vs-actuals — REQ-CC-*, REQ-CD-*)
- `bookkeeping-ifrs15-revenue` / `bookkeeping-consolidation-commercial` (own the RJ 270 / IFRS 15 `Project` register preserved untouched)
- ADR-019 integration registry + an OpenProject `IntegrationProvider` (OpenConnector-backed) for `externalProjectRef` resolution

## ADDED Requirements

@e2e exclude unbuilt UI: the cost-center-as-project budget view and the OpenProject-link affordance are not yet implemented

### Requirement: REQ-RCP-002 — The system SHALL let an analytical cost center reference an external OpenProject delivery project without storing OpenProject data

A `CostCenter` (analytical dimension) MUST be able to carry an optional
`externalProjectRef` declared as an **ADR-019 integration-registry reference
property** with `referenceType: openproject`. The reference stores only an
OpenProject project id/URL and a cached display label; resolution of the
linked project's delivery data (tasks, milestones, % delivered) MUST route
through OpenRegister's `ExternalIntegrationRouter` and the OpenProject
`IntegrationProvider` (external storage strategy, OpenConnector-backed).
shillinq MUST NOT store OpenProject's delivery/planning data — delivery lives
in OpenProject, budget-vs-actuals stays in shillinq derived from the GL.

| Property | Type | Required | Purpose |
|---|---|---|---|
| `externalProjectRef` | reference (`referenceType: openproject`) | No (0..1) | OpenProject project id/URL + cached label; resolved via ADR-019 registry |

The property MUST be valid and inert when the OpenProject provider is not yet
registered (ADR-019 permissive-on-read); no cost-center function may depend on
it.

#### Scenario: A cost center is linked to an OpenProject project

- **GIVEN** a `CostCenter` acting as a project-flavoured dimension
- **WHEN** an operator sets `externalProjectRef` to an OpenProject project id via the integration-registry picker
- **THEN** the reference and its cached label MUST be stored on the cost center, and the OpenProject integration's `single-entity` widget MUST render inline next to the property (per ADR-019 `referenceType`)

#### Scenario: shillinq stores no OpenProject delivery data

- **GIVEN** a cost center with an `externalProjectRef`
- **WHEN** the shillinq object is inspected
- **THEN** only the OpenProject reference + cached label MUST be present; no OpenProject tasks, milestones, or planning data MUST be copied into the register

#### Scenario: The reference is inert before the OpenProject provider is configured

- **GIVEN** an environment where the OpenProject `IntegrationProvider` is not registered
- **WHEN** a cost center carries an `externalProjectRef`
- **THEN** the object MUST remain valid (registry permissive-on-read) and all budget-vs-actuals derivation (REQ-RCP-001) MUST continue to work unaffected

## MODIFIED Requirements

### Requirement: REQ-RCP-001 — The `CostCenter` register SHALL absorb the project-budget fields so a cost center can play the time-boxed cost-collector role

The `CostCenter` schema MUST gain the following additive, optional properties (in the `unify-analytical-dimensions` / `bookkeeping-cost-centers-dimensions` register fragment, NOT the monolith) so the management-accounting "cost project" use case is a project-flavoured cost center rather than a separate register:

| Property | Type | Required | Purpose |
|---|---|---|---|
| `totalBudget` | integer (cents, ≥0) | No | Authorised spend ceiling (was `CostProject.totalBudget`) |
| `totalEstimatedCosts` | integer (cents, ≥0) | No | Manager estimate of total cost (was `CostProject.totalEstimatedCosts`) |
| `projectNumber` | string | No | Operator-facing project label (was `CostProject.projectNumber`); non-unique — `code` remains the unique key |
| `startDate` | date | No | Time-box start (was `CostProject.startDate`) |
| `endDate` | date | No | Time-box end (was `CostProject.endDate`) |

Costs-incurred MUST NOT be added as a stored field — it is exactly the
existing GL-derived `CostCenter.spentToDate` aggregation. A `budgetVariance`
MUST be declared via `x-openregister-calculations` as
`(totalBudget ?? 0) - (spentToDate ?? 0)` (mirroring the retired
`CostProject.budgetVariance`). All additions MUST be additive and optional so
a plain departmental cost center is unaffected, and MUST NOT introduce any PHP
service (ADR-022/ADR-031). The project-flavoured `dimensionType` (from
`unify-analytical-dimensions`) marks a cost center acting as a former cost
project so UIs can filter "projects" distinctly.

#### Scenario: A project-flavoured cost center tracks budget vs actuals from the GL

- **GIVEN** a `CostCenter` with `dimensionType = project`, `totalBudget = 5000000`, and GL lines tagged to its `code`
- **WHEN** the cost center is read
- **THEN** `spentToDate` MUST be the GL-derived sum (no stored costs-incurred field) and `budgetVariance` MUST equal `totalBudget - spentToDate`

#### Scenario: The new fields are additive and optional for plain cost centers

- **GIVEN** an existing departmental `CostCenter` with no project fields set
- **WHEN** this change is applied
- **THEN** the cost center MUST remain valid with `totalBudget`, `totalEstimatedCosts`, `projectNumber`, `startDate`, `endDate` absent, and its existing behaviour MUST be unchanged

#### Scenario: No app-local cost-project service is introduced

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for a `CostProjectService` or any PHP wrapping `CostCenter` budget logic
- **THEN** none MUST exist; budget-vs-actuals MUST be entirely declarative (`spentToDate` aggregation + `budgetVariance` calculation), the only PHP being the one-shot migration (REQ-RCP-005)

### Requirement: REQ-RCP-004 — The RJ 270 / IFRS 15 `Project` register SHALL be preserved untouched with exactly one navigation home, and the `CostProjects` navigation SHALL be removed

The revenue-recognition `Project` register MUST NOT be modified as a schema (`recognisedRevenue`, `billedRevenue`, `wipBalance`, `recognitionMethod`, `percentageComplete`, `totalContractValue`) — it is a genuinely different object from the analytical cost collector and stays. Its navigation MUST be collapsed from its current two homes (`Projects` under Bookkeeping and `ProjectenOverzicht` under People & Projects) to **exactly one**, via a `src/menu-layout.json` relocation/removal coordinated with `shillinq-nav-ia-cleanup`.

The `CostProjects` menu leaf MUST be removed from `src/manifest.json` and
`"CostProjects"` MUST be added to `src/menu-layout.json` `removals`. Per the
`removals` contract the `CostProjects` route MUST stay resolvable for deep
links and e2e specs even though the navigation entry is gone (matching the
existing `Consolidations` / `Verplichtingen` precedent). The
`CostProjectDetail` page definition MUST be retired.

#### Scenario: CostProjects is absent from navigation but the route survives

- **GIVEN** the shillinq app after this change
- **WHEN** the navigation menu is rendered
- **THEN** no `CostProjects` entry MUST appear; **AND** a direct deep link to the former `CostProjects` route MUST still resolve (not 404)

#### Scenario: The revenue-recognition Project has a single nav home

- **GIVEN** the shillinq navigation after this change
- **WHEN** the operator looks for the RJ 270 / IFRS 15 `Project` register
- **THEN** exactly one navigation entry (`Projects` OR `ProjectenOverzicht`, not both) MUST lead to it, and the `Project` schema MUST be byte-for-byte unchanged

## REMOVED Requirements

### Requirement: REQ-RCP-003 — The `CostProject` register SHALL be retired as a duplicate of `CostCenter`

The `CostProject` schema and its `CostProjectDetail` page MUST be removed from
the built register/manifest set (dropped, or marked
`x-openregister-deprecated` and excluded from the built schemas). Per ADR-012,
`CostProject` duplicates `CostCenter` — both are analytical, GL-derived,
budget-bearing, administration-scoped registers, and `CostProject.costCenterCode`
already FKs `CostCenter.code`. Its only unique fields (time-boxing +
`projectNumber`) are absorbed into `CostCenter` by REQ-RCP-001. No new
`CostProject` objects MUST be created or seeded after this change; the
cost-project seed rows in `lib/Settings/seeds/project-templates.json` and
`lib/Settings/seeds/cost-center-templates.json` MUST be retargeted to
`CostCenter` (project-flavoured `dimensionType`). The removal MUST occur only
after the migration (REQ-RCP-005) reports zero unmigrated objects.

#### Scenario: CostProject is no longer a creatable register

- **GIVEN** the shillinq register set after this change
- **WHEN** a client attempts to create a `CostProject` object via the OpenRegister object surface
- **THEN** the operation MUST be unavailable/rejected (the schema is not in the built set)

#### Scenario: No CostProject objects are seeded

- **GIVEN** a fresh shillinq install after this change
- **WHEN** seeds are applied
- **THEN** zero `CostProject` objects MUST be created; the former cost-project templates MUST instead seed project-flavoured `CostCenter` objects

### Requirement: REQ-RCP-005 — Existing `CostProject` objects SHALL be migrated to `CostCenter` rows idempotently and fail-safe, dropping no data

A `lib/Repair/RetireCostProjectStep.php` `IRepairStep` (registered in
`appinfo/info.xml`) MUST convert every existing `CostProject` to a `CostCenter`
through the real OpenRegister `ObjectService`, with this mapping:

| `CostProject` | → | `CostCenter` |
|---|---|---|
| `projectNumber` | → | `projectNumber` + minted `code = CP-<projectNumber>` |
| `name`, `description` | → | `name`, `description` |
| `startDate`, `endDate` | → | `startDate`, `endDate` |
| `totalBudget`, `totalEstimatedCosts` | → | `totalBudget`, `totalEstimatedCosts` |
| `costsIncurredToDate` | → | *dropped* (re-derived as `spentToDate`) |
| `administrationId`, `organizationId` | → | same |
| `costCenterCode` | → | `parentCode` |
| `lifecycleState` | → | `draft\|active\|on-hold → active`; `closed\|archived → archived` |
| — | → | `dimensionType = project`; `externalProjectRef` empty |

The step MUST be **idempotent** (write a `migratedFrom: <costProjectId>`
marker; skip already-migrated sources on re-run — re-runs are no-ops),
**fail-safe** (an unmappable `CostProject` is logged and **left in place**,
never deleted; the step deletes NO `CostProject` objects), and **collision-safe**
(`code` is namespaced `CP-…`; a collision gets a disambiguating suffix and a
report line, never a silent overwrite). Because the step only creates cost
centers and marks sources, restoring the `CostProject` register fully rolls the
migration back.

#### Scenario: A cost project becomes a project-flavoured cost center

- **GIVEN** a `CostProject` with `projectNumber = CP-2026-001`, `totalBudget = 5000000`, `costCenterCode = CC-002`, `lifecycleState = active`
- **WHEN** `RetireCostProjectStep` runs
- **THEN** a `CostCenter` MUST exist with `code = CP-CP-2026-001` (or a disambiguated variant), `projectNumber = CP-2026-001`, `totalBudget = 5000000`, `parentCode = CC-002`, `dimensionType = project`, `lifecycleState = active`, and a `migratedFrom` marker; **AND** no stored `costsIncurredToDate` MUST be copied (it is re-derived as `spentToDate`)

#### Scenario: Re-running the migration is a no-op

- **GIVEN** a `CostProject` already migrated (its `migratedFrom` marker exists on a cost center)
- **WHEN** `RetireCostProjectStep` runs again
- **THEN** no duplicate `CostCenter` MUST be created and the source MUST be skipped

#### Scenario: An unmappable cost project is left in place, not dropped

- **GIVEN** a `CostProject` that cannot be mapped (e.g. an irrecoverable `code` collision after disambiguation)
- **WHEN** `RetireCostProjectStep` runs
- **THEN** the source `CostProject` MUST be logged with the reason and LEFT IN PLACE — never deleted — and the step MUST continue with the remaining objects

### Requirement: REQ-RCP-006 — The retirement SHALL eliminate the dual-project-register confusion in the navigation and data model

After this change there MUST be exactly one analytical-project concept (a
project-flavoured `CostCenter`) and exactly one revenue-recognition concept
(the RJ 270 / IFRS 15 `Project` register, one nav home). The parallel
`CostProject` register, its `CostProjects`/`CostProjectDetail` navigation, and
its competing `subLedgerType='cost-project'` GL-tagging concept MUST no longer
be presented to operators as a distinct register (historical `GLLine` rows
carrying that tag remain queryable; collapsing the tag onto `costCenterCode`
is a deferred follow-up).

#### Scenario: Operators see one analytical-project surface and one revenue-recognition surface

- **GIVEN** the shillinq UI after this change
- **WHEN** an operator browses for "projects"
- **THEN** the analytical/management view MUST be the project-flavoured cost center, the revenue-recognition view MUST be the single-home `Project` register, and no separate `Cost Projects` register/nav MUST be presented
