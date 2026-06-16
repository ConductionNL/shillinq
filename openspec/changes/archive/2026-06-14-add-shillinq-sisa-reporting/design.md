# Design — SiSa Reporting

**status: pr-created**

## Context

SiSa (Single Information Single Audit) is the BZK reporting
framework for specifieke uitkeringen — government grants tied to
specific performance indicators. Every administration with one or
more specifieke uitkeringen owes BZK an annual bijlage at the
jaarrekening. Without dedicated primitives, the bijlage is
spreadsheet-driven; per ADR-031, it should be a declarative
aggregation per controleprotocol.

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — `SisaRegelingIndicator` as a child register of `Subsidie`, NOT a parallel subsidie register

Per ADR-022, the T3 subsidie register is the single source of
subsidie truth. SiSa indicators are per-regeling annotations on
specifieke uitkeringen — modelled as a child register
(`SisaRegelingIndicator`) with FK `subsidieId` to the parent.
The alternative (a parallel `SisaSubsidie` register duplicating
the subsidie data) was rejected per the parent envelope's design
D5.

### D2 — Annual seed of the BZK controleprotocol

The 2026 SiSa controleprotocol enumerates indicators per regeling
with `verplicht: boolean`. Shipping the controleprotocol as a seed
file (`sisa-controleprotocol-2026.json`) means adopters can
discover required indicators per regeling without consulting BZK
docs separately. Seed loading is idempotent — operator additions
to indicators persist across re-runs. The filename version-pins
the year so the 2027 release lives side-by-side.

### D3 — Annual bijlage as a declarative aggregation per controleprotocol

The SiSa-bijlage at jaarrekening is an
`x-openregister-aggregations` declaration grouping
`SisaRegelingIndicator` records by `(regelingCode,
controleprotocol)` for the closed fiscal year, rendered via a
docudesk template matching the BZK-vastgestelde layout. No PHP
SiSa-bijlage service. Missing `verplicht: true` indicatoren MUST
surface as warnings in the audit preview before submission.

### D4 — BZK submission rides openconnector + writes an immutable audit event

Per ADR-019, the BZK upload is an openconnector source row.
Shillinq references the source id from the docudesk template's
output-channel declaration. Every submission writes an audit
event with the regelingen list, controleprotocol version, document
SHA-256, BZK response status, and the docudesk document URI. The
event is linked to the parent jaarrekening via the audit-trail
hash chain.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Subsidie register | T3 `bookkeeping-subsidie-verantwoording` | `SisaRegelingIndicator` attaches via FK; no parallel subsidie register. |
| Document rendering | docudesk (ADR-022) | SiSa-bijlage template registered. |
| External submission | openconnector (ADR-019) | BZK upload source row; auth and protocol mapping are openconnector-side. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Every submission writes an event linked to the parent jaarrekening. |
| Seed data import | `ConfigurationService::importFromApp()` | Loads the controleprotocol seed; idempotent. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.gov-sisa`. |

**Net new code in implementation cycle**: 1 schema declaration
(`SisaRegelingIndicator`) + 1 aggregation declaration + 1 seed JSON
file + 1 docudesk template + 1 openconnector source row + 1
manifest entry. No new PHP service.

## Seed Data

| File | Purpose | Approximate row count |
|---|---|---|
| `lib/Settings/seeds/sisa-controleprotocol-2026.json` | BZK SiSa controleprotocol 2026 — indicatoren per regeling (regelingCode, indicatorCode, indicatorOmschrijving, indicatorType, verplicht: boolean) | ~200 |

SPDX header (EUPL-1.2 + Copyright Conduction B.V.) inside docblock;
`_meta` block (`source: 'BZK SiSa-controleprotocol'`, `year:
2026`); loaded via `ConfigurationService::importFromApp()` in the
repair step.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| BZK controleprotocol revisions yearly | Seed file version-pinned (`*-2026.json` → `-2027.json`); spec references controleprotocol, not values. |
| BZK upload endpoint auth changes | Openconnector source owns the auth/protocol mapping; shillinq references by id. |
| Late controleprotocol publication | Adopters can run with an older seed; warning surfaces in the SiSa-rapportage view when the active controleprotocol year ≠ fiscal year. |
| Missing verplichte indicators per regeling | Aggregation surfaces missing indicators as warnings in the audit preview, before submission. |
