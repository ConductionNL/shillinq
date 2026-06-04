# Design — R&D Subsidies MKB

## Context

T3's `bookkeeping-subsidie-verantwoording` already provides the generic subsidie register with budget bewaking, voortgangsrapportage, and kostendossiers. Each R&D regeling has its own kostencategorieën + audit-trail eisen (e.g. EU Horizon requires `Audit Certificate` with explicit timesheet evidence; MIT requires a declaration from the WBSO/S&O administration; EFRO requires a procurement dossier per purchase over a drempel). Without dedicated overlays, every regeling needs bespoke spreadsheets + audit packs.

This change is **spec-only**. Implementation lands later through `opsx-apply`; this doc explains *why* the shape is what it is.

**status: pr-created** — All tasks verified complete; implementation already present in development from bookkeeping-subsidie-verantwoording + add-shillinq-r-d-subsidies-mkb. PR #103 formalises the spec documents.

## Decisions

### D1 — Each regeling as a variant overlay on existing `Subsidie`, NOT a parallel `RDSubsidie` register

Per ADR-022 + the parent envelope's design D11, all R&D subsidies ride the existing `Subsidie` register. A `subsidieRegeling` enum field selects the regeling (mit / sbir / eu-horizon / efro / react-eu / other); per-regeling kostencategorieën are enforced via JSON Schema `oneOf`/`if-then` constraints. No parallel `RDSubsidie` register. No PHP regeling-resolver service.

### D2 — Per-regeling kostencategorieën via JSON Schema `oneOf`/`if-then`

Each regeling's allowed kostencategorieën are declared declaratively in the schema (e.g. when `subsidieRegeling: 'eu-horizon'`, only `personnel | subcontracting | other-direct | indirect-25-percent` are valid). An invalid combination fails at save time with a schema validation error. No PHP category validator.

### D3 — Per-regeling voortgangsrapportage as a declarative aggregation + docudesk template

Each regeling's voortgangsrapportage layout differs (EU Horizon Periodic Report, MIT voortgangsrapport, EFRO progress dossier). Modelled as one `x-openregister-aggregations` declaration per regeling (grouping `kostenpost` by `(kostencategorie, periodId)` filtered on the parent subsidie) + one docudesk template per regeling. No PHP report renderer.

### D4 — Per-regeling audit-pack as a docudesk template assembling from OR audit-trail-immutable

Per regeling, a docudesk template assembles an audit-pack from the OR audit-trail-immutable + the subsidie's kostendossier + relevant external attachments. EU Horizon's audit-pack references the related S&O-uren-staten (from sibling WBSO spec) via the personnel kostenposten's links. No PHP audit-pack builder.

### D5 — Budget monitoring via `x-openregister-calculations` with ≥90% warning

Each regeling has per-kostencategorie sub-maxima (e.g. Horizon indirect-25% is bound to 25% of direct costs). A calculation block per regeling verifies this and surfaces a warning when ≥90% of the sub-max is reached. Declarative; no PHP budget watcher.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Subsidie register | T3 `bookkeeping-subsidie-verantwoording` | Overlay `subsidieRegeling` enum + kostencategorieën constraint. |
| Kostendossier | T3 same | Reused per regeling with per-regeling categorieën. |
| Budget bewaking | T3 same | Extended with per-regeling sub-max warnings. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Audit-packs project from the existing log. |
| Document rendering | docudesk (ADR-022) | Per-regeling audit-pack + voortgangs templates. |
| S&O-uren references | Sibling `bookkeeping-wbso-sno-administratie` | Horizon audit-pack includes per-personnel uren-staat URIs. |
| Aggregation engine | `x-openregister-aggregations` | Per-regeling voortgangsrapportage. |
| Calculation engine | `x-openregister-calculations` (ADR-031) | Per-regeling budget monitoring with sub-max warnings. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.mkb-r-d-subsidies`. |

**Net new code in implementation cycle**: 1 enum + N kostencategorie constraint blocks (one per regeling) + 5 aggregation declarations + 5 docudesk templates + 5 calculation blocks + 1 manifest entry. No new PHP service.

## Seed Data

None. The regelingen + categorieën are schema-declared; no per-administration seed required.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| New R&D regelingen emerge | Add an enum value + a kostencategorieën block + a template; no spec churn. |
| Kostencategorieën evolve per regeling | JSON Schema edits; no code change. |
| Audit-pack layouts evolve | docudesk template versioning; multiple per regeling allowed. |
| EU Horizon indirect-25% sub-max calculation edge | Warning fires at ≥90%; hard-stop is not implemented (RvO / EU prefers warning + adjust). |
