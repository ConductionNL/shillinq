# Proposal: bookkeeping-iv3-reporting

`kind: config` per ADR-032 — the centre of mass is declarative
schemas (`IV3Report`, `IV3ReportLine`) +
`x-openregister-lifecycle` for submission workflow +
aggregations for quarterly GL summarisation. No PHP report-generation 
service classes authored (subject to ADR-031 exception: at most 
one single-method `IV3Formatter` if OR's data-transformation extension 
is not yet stable).

## Summary

Introduce the **IV3 quarterly reporting to CBS** capability for Shillinq
as a T2 compliance feature (per `adr-001-bookkeeping-tier-roadmap.md`). 
This capability **enables Dutch SMBs to submit mandated quarterly 
financial data to the Centraal Bureau voor de Statistiek (CBS)** in the 
IV3 standard format. The change declares the `IV3Report` and `IV3ReportLine` 
registers; the submission lifecycle (`draft → validated → submitted → filed`); 
the quarterly GL aggregation for balance-sheet line items; and the
CBS submission gateway integration.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure and `ConfigurationService::importFromApp()` repair-step
seeding.

**Depends on:** [`add-shillinq-general-ledger`](../add-shillinq-general-ledger/proposal.md)
(GL transaction source for reporting),
[`add-shillinq-chart-of-accounts`](../add-shillinq-chart-of-accounts/proposal.md)
(account structure mapping to IV3 fields).

## Motivation

IV3 reporting is a statutory Dutch government obligation. Shillinq's
customer base — SMBs and non-profits — must submit quarterly financial
summaries to CBS. Without native IV3 support, users currently export GL
data, manually curate it in spreadsheets, and submit via CBS's web portal.
This is error-prone and time-consuming. Per ADR-031, IV3 submission is a
pure declarative aggregation (GL → IV3 line items) plus a state machine
(draft → submitted → filed). No custom PHP report service required.

The legacy intelligence-db competitor feature set for shillinq includes
"IV3 export" as a top-tier customer-requested feature.

This is one of the T2 compliance + operations tier capabilities.

## Affected Projects

- [x] Project: shillinq — adds 1 capability spec
  (`bookkeeping-iv3-reporting`); declares 2 new registers
  (`IV3Report`, `IV3ReportLine`) with submission lifecycle;
  adds 2 manifest navigation entries (IV3 Reports, IV3 Submissions).
- [ ] Project: openregister — no source changes; consumes existing
  `x-openregister-lifecycle`, `x-openregister-aggregations`.
- [ ] Project: cbs-gateway — external service integration; handles
  IV3 submission protocol and receipt tracking (separate app).

## Scope

### In Scope

- One new capability spec (`bookkeeping-iv3-reporting`) —
  see the `specs/` folder.
- The `IV3Report` register with reporting-period metadata,
  quarter/year, administration FK, GL-aggregation reference.
- The `IV3ReportLine` register with GL account mapping,
  debit/credit amounts (rolled-up from GL), line sequence,
  CBS field code.
- The IV3 submission lifecycle (`draft → validated → submitted → filed`)
  consuming OR's lifecycle extension.
- GL aggregation: quarterly GL sum by RGS account, excluding
  inter-company eliminations.
- CBS submission gateway: encode IV3 XML per CBS spec; POST to
  submission endpoint; track receipt and filing status.
- Validation precondition: all mandatory GL accounts must be
  represented in the report before submission.
- Audit trail on lifecycle transitions (validated, submitted, filed).

### Out of Scope

- **Implementation code** — spec-only change. PHP services, Vue
  components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle.
- **Historical IV3 backfill** — only prospective quarters.
- **Multi-currency IV3** — EUR-only per CBS spec.
- **VAT/BTW line-item disaggregation** — T3 feature.

## Approach

One delta, adding ADDED Requirements to a brand-new spec:

**`bookkeeping-iv3-reporting`** — declares the two registers, the
submission lifecycle, the GL aggregation shape, the CBS submission
gateway contract, and validation rules.

The spec follows the conduction-schema format (RFC 2119,
`### REQ-{NNN}: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Each requirement is prefixed `REQ-IV3-*` for
traceability.

## New Dependencies

None. Consumes existing OpenRegister abstractions and the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`.

## Impact

- `lib/Settings/shillinq_register.json` — adds 2 new schemas
  (`IV3Report`, `IV3ReportLine`); declares lifecycle on `IV3Report`,
  aggregations for GL quarterly summarisation.
- `src/manifest.json` — adds 2 navigation entries + their
  `type: index` + `type: detail` pages.
- No new PHP services (subject to ADR-031 exception: one
  single-method `IV3Formatter` if OR's data-transformation extension
  is not yet stable).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on `x-openregister-lifecycle`,
  `x-openregister-aggregations`.
- **T1 general ledger** — depends on `add-shillinq-general-ledger`
  for the GL transactions that are aggregated by account and period.
- **T1 chart of accounts** — depends on `add-shillinq-chart-of-accounts`
  for RGS account structure mapping to IV3 field codes.
- **CBS Gateway** — external service integration (separate app or
  OpenConnector provider) handles XML encoding and submission protocol.

## Risks

### Risk 1: CBS IV3 specification version drift

**Severity**: Low-Medium
**Mitigation**: The spec pins the CBS IV3 standard version (currently
2024-Q1). When CBS publishes a new version, the implementing cycle
files an issue; T2 spec is updated; the submission adapter is refreshed.
No breaking schema changes — new IV3 fields are additive.

### Risk 2: GL aggregation misalignment with RGS account hierarchy

**Severity**: Medium
**Mitigation**: Aggregation is defined as "sum by RGS account code
excluding inter-company eliminations per `bookkeeping-consolidation`."
The chart-of-accounts spec (T1) declares the RGS mapping; IV3 report
derives its line items from that mapping. Validation in the
implementing cycle confirms GL balances match IV3 report totals.

### Risk 3: CBS submission gateway integration timing

**Severity**: Low
**Mitigation**: The submission gateway is scoped as a separate
`cbs-gateway` app or OpenConnector provider. Shillinq declares
the IV3Report register only; the gateway consumes it. If the gateway
is not ready at implementation time, IV3 reports can be manually
exported in CSV and submitted via the CBS web portal (current user
flow). When the gateway lands, Shillinq integrates additively.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact. After implementation (separate cycle),
rollback follows the standard pattern: revert the implementing PR;
registers are non-destructive — IV3 reports remain queryable.

## Open Questions

1. **CBS IV3 standard version** — currently pinned to 2024-Q1; resolved
   during discovery if a newer version is in use.
2. **Quarterly closing process** — whether IV3 report creation is
   triggered automatically on GL close or manually by operator;
   resolved in the implementing cycle's UX review.
3. **GL consolidation scope** — whether inter-company eliminations
   are included in IV3 (standard: no); confirmed during T3 VAT work
   when consolidation rules are formalised.
