# Specification: IV3 Quarterly Reporting to CBS

**Status:** proposed  
**Scope:** shillinq  
**Tier:** T2 (Compliance + Operations)  
**Depends on:** bookkeeping-general-ledger, bookkeeping-chart-of-accounts  

## Context

Dutch law mandates quarterly financial reporting to the Centraal Bureau voor de
Statistiek (CBS) in the IV3 format (Statistics Netherlands, Structural Business
Statistics). Shillinq users must submit GL summaries by the 10th of the month
following the quarter-end.

This spec formalises IV3 reporting as a declarative aggregation (GL → quarterly
summary) plus submission workflow (draft → filed), enabling users to generate,
validate, and submit IV3 reports within the app.

---

## REQ-IV3-001: IV3 Report entity declares reporting period and GL aggregation

**RFC 2119:** MUST

The system MUST provide an `IV3Report` register with the following properties:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportNumber | string | Yes | Unique IV3 report identifier |
| administrationId | string | Yes | FK to Administration; determines GL source |
| fiscalYear | integer | Yes | Reporting year (e.g., 2026) |
| quarter | enum | Yes | One of: Q1, Q2, Q3, Q4 |
| status | enum | Yes | One of: draft, validated, submitted, filed |
| reportDate | datetime | No | Date report was generated |
| submissionDate | datetime | No | Date submitted to CBS |
| filedDate | datetime | No | Date CBS confirmed filing |
| notes | string | No | Operator comments or submission notes |

#### Scenario: Create a draft IV3 report for Q2 2026

Given an administration with completed GL for April–June 2026  
When an operator clicks "Generate IV3 Report" for Q2 2026  
Then a new `IV3Report` object is created with status: draft, reportNumber auto-assigned,
GL aggregation triggered, and `IV3ReportLine` items materialised

---

## REQ-IV3-002: IV3 Report Line items are materialized from GL aggregation

**RFC 2119:** MUST

The system MUST declare `IV3ReportLine` register with:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| reportId | string | Yes | FK to IV3Report |
| iv3FieldCode | string | Yes | CBS IV3 field code (e.g., "K1000", "K2100") |
| accountNumber | string | Yes | RGS account code (e.g., "1000", "2100") |
| debitAmount | number | No | Aggregated debit amount (EUR) |
| creditAmount | number | No | Aggregated credit amount (EUR) |
| netAmount | number | Yes | creditAmount - debitAmount (EUR) |
| sequence | integer | Yes | Display order in report |

#### Scenario: Aggregate GL transactions into IV3 lines

Given an IV3Report for Q2 2026 and GL entries for April–June 2026  
When the system processes the report  
Then GL entries are grouped by RGS account, inter-company eliminations excluded,
and `IV3ReportLine` items created with iv3FieldCodes from the Account register

---

## REQ-IV3-003: Chart of Accounts declares IV3 field mapping

**RFC 2119:** MUST

The system MUST extend the `Account` register (T1) with an optional property:

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| iv3FieldCode | string | No | CBS IV3 field code this account maps to (e.g., "K1000") |

#### Scenario: Account has IV3 field code

Given an Account for "Tangible Fixed Assets" (RGS code 1000)  
When the account is linked to CBS IV3 field K1000  
Then IV3 reports will aggregate GL transactions for this account under K1000

---

## REQ-IV3-004: IV3 Report lifecycle controls submission workflow

**RFC 2119:** MUST

The system MUST declare an `x-openregister-lifecycle` on `IV3Report` with:

| Transition | From | To | Preconditions | Actions |
|---|---|---|---|---|
| create | (new) | draft | None | Report instantiated; GL aggregation runs |
| validate | draft | validated | All mandatory IV3 fields mapped in chart of accounts; GL balances ≥ 0 | Validation audit-trailed; report ready for submission |
| submit | validated | submitted | None | POST to CBS gateway (cbs-gateway app); receipt recorded |
| file | submitted | filed | CBS gateway confirms receipt | Filing date recorded; report archived |

#### Scenario: Validate report before submission

Given a draft IV3Report with GL aggregation complete  
When an operator clicks "Validate"  
Then the system checks that all mandatory CBS IV3 fields have ≥1 mapped GL account,
emits a validation audit event, and transitions status to validated (or rejects with error message)

---

## REQ-IV3-005: Mandatory GL account validation rejects incomplete reports

**RFC 2119:** MUST

The system MUST validate that before transitioning to `validated`,
every mandatory CBS IV3 field (per CBS 2024-Q1 spec) has at least one
GL account mapped to it (via REQ-IV3-003).

**Mandatory IV3 Fields (2024-Q1 CBS standard):**
- K1000, K1100, K2000, K2100, K3000, K4000, K5000 (minimum)
- Additional quarterly-specific fields per CBS guidance

#### Scenario: Report validation fails due to missing GL account mapping

Given a draft IV3Report where Account for "K2100 Current Liabilities" is unmapped  
When operator clicks "Validate"  
Then the system rejects with error: "Cannot validate report: CBS field K2100 is unmapped.
Add a GL account mapped to K2100 in chart of accounts and try again."

---

## REQ-IV3-006: GL aggregation excludes inter-company eliminations

**RFC 2119:** MUST

When aggregating GL transactions for IV3, the system MUST exclude
inter-company eliminations as defined by the bookkeeping-consolidation
spec (T3). GL entries with elimination flag set are excluded from
IV3ReportLine calculations.

#### Scenario: Inter-company transaction excluded from IV3

Given GL entries including an inter-company elimination posting  
When IV3ReportLine items are materialized  
Then the elimination posting is excluded; net IV3 amounts reflect
third-party transactions only

---

## REQ-IV3-007: IV3 Report aggregates GL by quarter only

**RFC 2119:** MUST

The system MUST define quarterly GL aggregation as:
```
SUM(GLEntry.amount WHERE GLEntry.accountId = Account.id
    AND GLEntry.date >= start_of_quarter
    AND GLEntry.date <= end_of_quarter
    AND GLEntry.eliminationFlag = false)
  GROUP BY Account.iv3FieldCode
```

#### Scenario: Quarterly sum calculation

Given GL entries for Q2 2026 (April 1–June 30):
- April: Asset account (K1000) +10,000
- May: Asset account (K1000) +5,000
- June: Asset account (K1000) -2,000

When IV3Report is created for Q2 2026  
Then IV3ReportLine for K1000 shows netAmount: +13,000 (sum of all three entries)

---

## REQ-IV3-008: IV3 Report manifest provides navigation to reports and submissions

**RFC 2119:** MUST

The system MUST add two manifest navigation entries:

| Entry | Type | Path | Label |
|---|---|---|---|
| iv3-reports | index | `/iv3-reports` | "IV3 Reports" |
| iv3-report-detail | detail | `/iv3-reports/:id` | (inherits from report.reportNumber) |

Each entry links to list and detail pages, using `CnIndexPage` and `CnDetailPage` respectively.

#### Scenario: Navigate to IV3 Reports list

Given a Shillinq user with admin permissions  
When the user opens the navigation menu  
Then "IV3 Reports" appears as a top-level item,
leading to a list of all IV3Report objects

---

## REQ-IV3-009: IV3 Report submission is triggered by CBS Gateway integration

**RFC 2119:** MUST

On `IV3Report` transition to `submitted`, the system MUST POST the report
(including all IV3ReportLine items) to the CBS gateway app (or OpenConnector
provider) for XML encoding and submission to the CBS endpoint.

The submission MUST:
1. Encode report metadata + all line items as JSON
2. Call CBS gateway via HTTP POST to `/api/iv3/submit`
3. Record the gateway response (receipt number, timestamp)
4. Update `submissionDate` and await `filed` transition on receipt confirmation

#### Scenario: Submit IV3 report to CBS

Given an IV3Report with status: validated  
When operator clicks "Submit to CBS"  
Then the system POSTs the report to cbs-gateway; receipt is recorded;
status transitions to submitted; operator sees receipt number

---

## REQ-IV3-010: IV3 Report supports audited lifecycle transitions

**RFC 2119:** MUST

Every IV3Report lifecycle transition MUST be audit-trailed with:
- Actor (user ID)
- Timestamp
- Transition type (validate, submit, file)
- Reason/notes (if provided by operator)

The audit trail is recorded in the OpenRegister audit system automatically.

#### Scenario: Audit trail on submission

Given an IV3Report in status: submitted  
When querying the audit trail for that report  
Then transitions show: draft→validated (2026-05-15, admin@example.com, "Q2 review complete")
→ submitted (2026-05-20, admin@example.com, "Submitted to CBS")

---

## REQ-IV3-011: IV3 Report supports manual export in multiple formats

**RFC 2119:** SHOULD

The system SHOULD support exporting an IV3Report in:
- CSV format (line items with headers: reportNumber, iv3FieldCode, accountNumber, netAmount)
- JSON format (full report metadata + line items)

Export is triggered from the detail page action menu.

#### Scenario: Export IV3 report as CSV

Given an IV3Report for Q2 2026  
When operator clicks "Export as CSV"  
Then a downloadable file is generated with columns:
reportNumber, iv3FieldCode, accountNumber, debitAmount, creditAmount, netAmount

---

## Validation Rules

1. **Report uniqueness**: No two IV3Reports with identical (administrationId, fiscalYear, quarter) may exist
2. **GL balance sign**: IV3ReportLine amounts reflect GL debits/credits; negative values allowed
3. **Quarter boundaries**: GL aggregation MUST respect calendar quarter boundaries (Q1: Jan–Mar, Q2: Apr–Jun, etc.)
4. **Account mapping**: iv3FieldCode is optional on Account; if missing, account is excluded from IV3 reporting

---

## Test Scenarios

### Scenario 1: Happy Path — Draft to Filed

1. Create IV3Report for Q2 2026 (status: draft)
2. Verify GL aggregation materialises 8–12 IV3ReportLine items
3. Click "Validate" → verify status→validated, audit trail recorded
4. Click "Submit to CBS" → verify status→submitted, receipt recorded
5. Simulate CBS confirmation → verify status→filed, filedDate set

### Scenario 2: Validation Failure

1. Create IV3Report with unmapped GL accounts (some Account records lack iv3FieldCode)
2. Click "Validate" → verify error message lists missing IV3 fields
3. Operator maps missing Account.iv3FieldCodes in chart of accounts
4. Click "Validate" again → verify success, status→validated

### Scenario 3: Inter-Company Exclusion

1. Create GL entries including inter-company eliminations (eliminationFlag=true)
2. Create IV3Report for the same period
3. Verify IV3ReportLine totals exclude elimination entries
4. Manually compare GL sum vs IV3 line sum → should differ by exactly the elimination amount

---

## Notes

- This spec formalises IV3 reporting as purely declarative — no PHP report
  generation service or spreadsheet export loop required.
- CBS gateway (cbs-gateway app or OpenConnector provider) handles XML encoding
  and submission protocol.
- Seed data in design.md includes 3–5 realistic example reports (draft, validated,
  submitted, filed) for testing.
