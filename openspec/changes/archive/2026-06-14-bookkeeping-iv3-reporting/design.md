# Design — IV3 Quarterly Reporting to CBS

**Status:** pr-created

## Context

Dutch SMBs and non-profits are legally required to file quarterly financial
reports (IV3 format) with the Centraal Bureau voor de Statistiek (CBS).
Shillinq's GL is the source of truth for financial transactions; IV3 reporting
is the aggregation and submission of those transactions in CBS-mandated format.

Per ADR-022, submission workflow comes from OR's lifecycle extension, not from
an app-local state machine. Per ADR-031, GL aggregation and IV3 line-item
calculation are declarative, not PHP report services.

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire IV3-reporting surface as **declarative metadata** —
  schemas + lifecycle + aggregations + manifest entries — per
  ADR-031.
- Consume OR's lifecycle abstraction — per ADR-022. Zero
  parallel state-machine table.
- Make the spec a **competent-bookkeeper readable contract** —
  Dutch SMB IV3 flow recognisable end-to-end (GL close, aggregation,
  report generation, validation, CBS submission, filing).
- Declare the CBS IV3 field shape so CBS-Gateway can encode
  and submit additively.
- Enable quarterly financial reporting without spreadsheet-based
  manual curation.

## Non-Goals

- No PHP report-generation service.
- No CBS XML encoding in Shillinq (handled by cbs-gateway app).
- No historical backfill of prior quarters.
- No multi-currency reporting — EUR-only per CBS spec.

## Decisions

### D1 — IV3 Report is a view over GL aggregated by quarter and account

`IV3Report` is not a ledger that stores GL copies; it is a declarative
view. The register declares the quarter, the GL aggregation (sum by
account, exclude inter-company eliminations), and materialises the
`IV3ReportLine` items from that aggregation at report-creation time.

### D2 — IV3 submission is a lifecycle consuming OR's lifecycle extension

`IV3Report` declares the submission workflow (`draft → validated →
submitted → filed`) using OR's `x-openregister-lifecycle` extension.
Shillinq carries no app-local state machine.

### D3 — GL aggregation is declarative, not a PHP service

Per ADR-031, "quarterly GL sum by RGS account excluding inter-company
eliminations" is declared as `x-openregister-aggregations` — a pure
data query, not a `GLAggregationService`.

### D4 — Validation precondition checks mandatory GL accounts are present

On `IV3Report.validate`, a declarative precondition checks that every
mandatory IV3 field code (per CBS spec) has at least one GL account
mapped to it in the chart of accounts. Pure aggregation; no PHP service.

### D5 — IV3 field mapping is driven by chart of accounts

The `Account` register (T1) declares an optional `iv3FieldCode` property.
IV3 report aggregation groups GL transactions by that code. If chart of
accounts is incomplete, the validation precondition rejects the report.

### D6 — CBS submission is scoped to a separate gateway app

Shillinq declares the IV3Report and IV3ReportLine registers only. The
cbs-gateway app (or OpenConnector provider) consumes those registers,
encodes them as IV3 XML per CBS specification, and handles submission
protocol + receipt tracking. Shillinq does not encode CBS XML.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| IV3 submission lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `IV3Report` (`draft → validated → submitted → filed`); audit-trailed transitions |
| GL aggregation by account | OR `x-openregister-aggregations` | Query: SUM(GL.amount where GL.accountId IN [accounts matching IV3FieldCode]) per quarter |
| Mandatory field validation | OR `x-openregister-aggregations` | Precondition on `IV3Report.validate`: every CBS-required IV3 field must have ≥1 mapped GL account |
| Account structure mapping | T1 `Account` register + `iv3FieldCode` property | Account declares optional `iv3FieldCode`; IV3 aggregation groups GL by that code |
| GL source | T1 `GeneralLedgerEntry` register | IV3 report aggregates `GLEntry` filtered by quarter and account |
| CBS submission protocol | cbs-gateway app (separate project) | `IV3Report` register consumed by gateway; no XML encoding in Shillinq |
| Manifest navigation | T1 manifest pattern | 2 entries (IV3 Reports, IV3 Submissions) + their pages |
| Audit trail | T2 `bookkeeping-audit-trail` | Automatic on lifecycle transitions (validated, submitted, filed) |

**Net new code in implementation cycle**: 2 schema declarations + 1
lifecycle block + 2 aggregations + 2 manifest entry pairs. Zero PHP services
(subject to ADR-031 exception: at most 1 single-method `IV3Formatter` if OR's
data-transformation extension is not yet stable).

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| IV3 submission lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| GL quarterly aggregation | Declarative (`x-openregister-aggregations`) | Pure SUM + GROUP BY query |
| Mandatory field validation | Declarative (`x-openregister-aggregations` precondition) | Pure existence check |
| IV3 XML encoding | Gateway app (external) | CBS protocol is external spec; encoding is adapter responsibility |
| CBS submission POST | Gateway app (external) | CBS submission endpoint is external; Shillinq not responsible for HTTP protocol |

No service class authored in this envelope (subject to ADR-031
exception: at most one single-method `IV3Formatter`).

## Seed Data

3–5 example IV3 reports per schema:

**IV3Report** (seed objects):

1. `iv3-report-2026-q1` — Q1 2026, status: draft, administration: `demo-001`,
   aggregation complete but not yet validated
2. `iv3-report-2026-q2` — Q2 2026, status: validated, administration: `demo-001`,
   ready for submission
3. `iv3-report-2026-q3` — Q3 2026, status: submitted, administration: `demo-001`,
   submitted to CBS, awaiting filing confirmation
4. `iv3-report-2025-q4` — Q4 2025, status: filed, administration: `demo-001`,
   final, archived

**IV3ReportLine** (derived from above reports):

- Each seed IV3Report includes 8–12 IV3ReportLine items (one per RGS account
  that had GL activity in that quarter). Line items include debit/credit
  amounts rolled up from GL, CBS field code, and sequence.

Seed data uses realistic Dutch SMB financial figures (EUR, Dutch postcodes,
valid KVK codes, real municipality names).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| CBS IV3 spec version drift | Spec pins version (2024-Q1); new versions trigger spec update; additive changes only |
| GL aggregation slow with many accounts | Aggregation cache via OR's extension if performance gates trip; per-spec optimisation in implementing cycle |
| Chart-of-accounts incomplete (missing IV3 mapping) | Validation precondition rejects report; error message guides operator to add missing account mappings |
| CBS gateway integration timing | Gateway is separate app; Shillinq declares register only; manual CSV export fallback until gateway lands |
| Inter-company elimination exclusion logic | GL consolidation rules formalised in T3; IV3 aggregation updated accordingly |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with the two
   schemas (`IV3Report`, `IV3ReportLine`) + their aggregations;
   `Account` schema (T1) is patched with optional `iv3FieldCode` property (additive).
2. `src/manifest.json` is patched with 2 new menu entries + their
   pages (additive).
3. Seed data (3–5 example IV3 reports) is loaded via
   `components.objects[]` per ADR-001 seeding pattern.
4. No new PHP services (subject to ADR-031 exception).

Down-direction: registers are non-destructive — reverting removes
the manifest entries; IV3 reports remain queryable but unreferenced.

## Open Questions

1. **CBS IV3 2024-Q1 version accuracy** — confirmed during discovery
   if a newer CBS version is current (next expected: 2025-Q1).
2. **Quarterly closing trigger** — whether IV3 report is created
   automatically on GL close or manually by operator; resolved in
   the implementing cycle's UX review.
3. **Inter-company elimination scope** — whether IV3 includes or
   excludes consolidated entities; confirmed during T3 VAT work.
