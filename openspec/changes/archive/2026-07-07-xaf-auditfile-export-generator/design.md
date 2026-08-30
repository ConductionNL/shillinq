# Design: xaf-auditfile-export-generator

## Context

The Dutch Auditfile Financieel (XAF) 3.2 is the standard hand-over format between
a bookkeeping package and an accountant or the Belastingdienst. Shillinq claims
it (REQ-MA-007 scenario + a ticked task) but ships only a scope descriptor and an
OECD SAF-T generator (a different standard).

## Decisions

### D1 — New `XafAuditfileGenerator`, mirror the SAF-T generator

Build `lib/Reporting/Generator/XafAuditfileGenerator.php` on the exact pattern
already proven by `SaftReportGenerator`: `implements ReportGeneratorInterface`,
`use ReportDataTrait`, byte-native `XMLWriter`, empty-but-well-formed containers
when a block has no rows. Only the namespace, element vocabulary, and block
structure change (XAF 3.2, not SAF-T 2.00). Reusing the pattern keeps the two
audit-file surfaces consistent and testable.

### D2 — Keep SAF-T; do not conflate

SAF-T stays as the `saft` report id (OECD, international). XAF is a new `xaf`
report id (Dutch). The spec makes the non-substitution explicit because the bug
that produced this gap was treating "an XMLWriter audit file exists" as "XAF
exists". They are different files for different authorities.

### D3 — Data isolation via `administrationId`

Every source schema the generator reads (`Account`, `GLTransaction`, `GLLine`,
`Payee`, customer master) carries `administrationId`. The generator filters
strictly on the context administration; the spec asserts no foreign row leaks.
This reuses `ReportDataTrait`'s administration-context scoping (the same isolation
SAF-T relies on).

### D4 — Streaming route + ZIP bundle

Add a streaming administration-export controller action/route so
`exportScope()`'s `format: 'xaf-3.2'` descriptor resolves to real bytes. For a
*full* export the response is a ZIP bundling the XAF file plus the
administration's attached NC-Files documents (documents live in NC Files — link,
don't store — so the bundle references/streams them from Files). A plain XAF-only
response is available for the API path.

## XAF 3.2 block mapping (source → element)

| XAF block | Source |
|---|---|
| `header` | administration identity, period, software id/version |
| `company` | administration `Company`/identity, KvK, BTW |
| `generalLedger` / `ledgerAccount` | `Account` (RGS-coded chart of accounts) |
| `customersSuppliers` | `Payee` (AP) + customer master (AR) |
| `transactions` / `journal` / `trLine` | `GLTransaction` + `GLLine` |

## Non-goals

- No incremental backup, no 7-year archival read-only mode (REQ-MA-007's other
  scenarios).
- No XAF *import* — that already ships (`AuditfileParser`,
  `administration-import-migration`). This is export only.
- No change to `SaftReportGenerator`.

## Risks

XAF 3.2 has a formal XSD; the generator must validate against it. The spec's
"valid, namespaced" assertion should be enforced by a PHPUnit test that loads the
XAF 3.2 schema and validates the emitted document — the same rigor SEPA pain.001
and IV3 generators already apply in this repo.
