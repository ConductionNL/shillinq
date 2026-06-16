# Design — Vpb Corporate Tax

**status: pr-created**

## Context

Per Wet modernisering Vpb-plicht (2016), municipal
ondernemingsactiviteiten and certain stichtingen/verenigingen are
Vpb-pligtig. The Vpb-balans is the same GL data filtered to the
Vpb-pligtige accounts per ondernemingsactiviteit. Without dedicated
primitives, the filtering + balans-rendering lives in a spreadsheet
or in a separate product.

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — Vpb tagging is account-level, NOT a parallel Vpb register

Per ADR-031 + the parent envelope's design D7, `Account` gains a
`vpbPligtig: boolean` flag (default `false`). Postings against
flagged accounts contribute to the Vpb-balans. The alternative (a
parallel `VpbAccount` register) was rejected — duplicates the
account surface and creates a sync problem.

### D2 — `VpbBalansLink` ties ondernemingsactiviteit cost-centers to Vpb-pligtige accounts

A `VpbBalansLink` overlay register declares the per-
ondernemingsactiviteit cluster of Vpb-pligtige accounts:
`costCenterId` (FK to a `CostCenter` with `ondernemingsActiviteit:
true`), `accountNumbers` (array of `Account.accountNumber`
strings), `vpbPligtigVanaf` (date the Vpb-pligtigheid started).
This lets the Vpb-balans aggregation group results per
ondernemingsactiviteit. No PHP Vpb-service.

### D3 — Vpb-balans as a declarative aggregation, NOT a materialised register

The Vpb-balans is an `x-openregister-aggregations` declaration
(output `VpbBalansFiltered` with `schema:Dataset` annotation)
filtering `GLLine` on (`accountNumber IN
VpbBalansLink.accountNumbers` AND `periodId IN fiscalYearPeriods`)
and grouping per `costCenterId`. Activa / Passiva / Resultaat
columns; balanced per the T1 balance invariant REQ-GL-005. The
alternative (a materialised `VpbBalansResult` table) was rejected
— the filter is cheap, materialisation introduces sync risk.

### D4 — Vpb-aangifte voorbereiding via docudesk; SBR transmission via T4-base path

The Vpb-aangifte voorbereiding is a docudesk template populated
from the Vpb-balans aggregation. The actual aangifte transmission
rides the T4-base `bookkeeping-sbr-xbrl-reporting` SBR endpoint
(Belastingdienst SBR). Shillinq declares the docudesk template +
SBR payload binding; no PHP Vpb-aangifte service.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Account register | T1 `bookkeeping-chart-of-accounts` | Add `vpbPligtig` flag; no parallel register. |
| Ondernemingsactiviteit cost-center | Sibling `add-shillinq-market-government-separation` | `VpbBalansLink.costCenterId` FK references it. |
| Balance invariant | T1 `bookkeeping-general-ledger` REQ-GL-005 | Vpb-balans honours the same invariant per cost-center. |
| Document rendering | docudesk (ADR-022) | Vpb-aangifte voorbereiding template. |
| SBR transmission | T4-base `bookkeeping-sbr-xbrl-reporting` | Aangifte rides the existing SBR path; no new SBR client. |
| Audit trail | OR audit-trail-immutable (ADR-022) | Every aangifte run writes an event. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.mkb-vpb`. |

**Net new code in implementation cycle**: 1 flag field
(`vpbPligtig`) + 1 overlay schema (`VpbBalansLink`) + 1 aggregation
declaration + 1 docudesk template + 1 manifest entry. No new PHP
service.

## Seed Data

None. Vpb-pligtige accounts + `VpbBalansLink` records are
operationally authored per administration.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Vpb XSD revisions yearly | Multiple docudesk template versions coexist; spec references the regulation, not values. |
| Orphaned Vpb-pligt risk (account flagged but no link) | Aggregation warns on Vpb-pligtige accounts not referenced by any `VpbBalansLink`. |
| Vpb-balans balance check failure | Same invariant as T1 REQ-GL-005; Vpb-balans aggregation surfaces unbalanced cost-centers as warnings. |
| Vpb-aangifte voorbereiding drift from actual aangifte | Voorbereiding renders + audits; the SBR transmission rides T4-base's authoritative path. |
