# Design — Rekenkamer Audit Pack

**status: pr-created**

## Context

T2's `bookkeeping-audit-trail` already provides the immutable
hash-chained event log per ADR-022. Rekenkamers and accountants
expect three outputs on top: a standardised audit-file
(NIVRA-bestand), reproducible substantive samples, and a redacted
slice fit for raadsleden review. Each of these is **purely a
projection** of the existing audit-trail + GL data — no parallel
storage, no re-implementation.

This change is **spec-only**. Implementation lands later through
`opsx-apply`; this doc explains *why* the shape is what it is.

## Decisions

### D1 — Audit-pack as presentation manifest, NOT a new audit register

Per ADR-022, the OR audit-trail-immutable surface is the single
source of audit truth. Building a parallel `RekenkamerExport` or
`NivraRecord` register would duplicate the audit surface and create
a sync problem. Instead, each audit-pack output is declared as:

1. An `x-openregister-aggregations` declaration that projects the
   audit-trail + GL into the required shape.
2. A `docudesk` template that renders the projection.
3. (Optionally) an `openconnector` source row for external
   submission (per accountant per administration).

The alternative (a thin `RekenkamerAuditService` orchestrating the
three exports) was rejected per ADR-031 — every behaviour here is
either a query (aggregation) or a render (docudesk template), both
declarative.

### D2 — Deterministic steekproef via seed parameter on the aggregation

For substantive testing, the audit-pack exposes a `steekproef`
aggregation that, given `periodId`, `sampleSize`, and a `seed`,
returns a deterministic random sample of `GLTransaction` records.
Reproducibility is mandatory — re-running with the same seed yields
the same sample. If the aggregation engine cannot guarantee this,
the implementing cycle falls back to a single-method ~20-LOC PHP
sampler per ADR-031 exception path (documented explicitly in the
implementing design doc).

### D3 — Redaction profile as schema metadata, not service-side filtering

The redaction profile is declared on the aggregation as metadata:
fields tagged `redactFor: ['raadsleden']` MUST be replaced by a
stable hash (or `[REDACTED]` placeholder) at projection time. This
keeps the redaction policy auditable + reviewable on its own (the
aggregation declaration carries the redaction rule) rather than
buried in service code.

### D4 — Every export writes an immutable audit event

Per REQ-REK-005, producing any audit-pack output (NIVRA, steekproef,
ledenraadpleging) writes an audit event recording the operator id,
output type, period id, document URI, and SHA-256. This is enforced
by OR's audit engine (the document-export event is a first-class
audit-trail entry), not by app-local logging.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Hash-chained audit log | T2 `bookkeeping-audit-trail` (ADR-022) | Audit-pack projects from the existing log — no parallel register. |
| Trial balance + chart of accounts for NIVRA | T2 `bookkeeping-financial-statements` + T1 `bookkeeping-chart-of-accounts` | NIVRA-bestand aggregation bundles them. |
| Document rendering | docudesk (ADR-022) | 3 templates registered; shillinq references by URI. |
| Deterministic sampling | `x-openregister-aggregations` with seed | If engine-limited, ADR-031 exception path (single-method PHP sampler). |
| Redaction | Aggregation metadata `redactFor` | Declarative — no service-side filtering. |
| External submission | openconnector (ADR-019) | 1 source row per accountant per administration. |
| Export audit event | OR audit-trail-immutable | Every export records an event with hash. |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted) | 1 entry behind `featureFlags.gov-rekenkamer` with 3 sub-pages. |

**Net new code in implementation cycle**: 0 schema declarations + 3
aggregation declarations + 3 docudesk template references + 1
openconnector source row + 1 manifest entry. No new PHP service
(unless steekproef engine-limit fallback applies — single ~20-LOC
method).

## Seed Data

None. The audit-pack reads from operational audit-trail + GL data;
no seed required.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| NIVRA standard version evolves | Multiple templates may coexist (`nivra-bestand-2026.xml` → `-2027.xml`); spec references the standard, not values. |
| Steekproef seed reproducibility across engine versions | If aggregation engine drops reproducibility, ADR-031 exception path applies (single-method PHP sampler). |
| Redaction rules drift between profiles | Each aggregation declaration carries its own redaction metadata; reviewer reads the declaration end-to-end. |
| External audit-portal endpoint changes | openconnector source row owns the protocol mapping; shillinq references by id. |
