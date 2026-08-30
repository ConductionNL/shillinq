# Design — Payroll + Detachering Bridge

## Context

Payroll management is the highest-demand feature for Dutch SMBs using Shillinq. Integration with detachering (temporary staffing) and compliance with tax/social-security law (loonbelasting, SV contributions) are requirements from the field.

The change is **spec-only**. Implementation lands later through `opsx-apply` and the standard Hydra pipeline; this doc explains *why* the shape is what it is.

## Goals

- Express the entire payroll-detachering surface as **declarative metadata** — schemas + lifecycle + aggregations + manifest entries — per ADR-031.
- Consume OR's lifecycle abstraction — per ADR-022. Zero parallel payroll-status table.
- Make the spec a **payroll-administrator-readable contract** — Dutch SMB payroll flow recognisable end-to-end (employee intake, payroll calculation, tax/SV deductions, determination letter generation, GL posting, external software sync).
- Carry forward the **original Shillinq payroll scope** under the declarative T2 envelope.
- Support **detachering-specific workflows** — employee classification, placement fees, onboarding/exit processing.
- Declare the UBL Peppol BIS 30 field shape so T4 can attach payroll-disbursement emission additively.
- Enable **external payroll-software integration** via REST API + webhooks (read `Payroll`, `Deduction`, `Employee` schemas; write back via webhook on changes).

## Non-Goals

- No PHP payroll-calculation service — aggregations handle tax/SV rate validation; external software handles actual payroll math.
- No UBL Peppol BIS 30 outbound emission — T4.
- No multi-currency translation — T5.
- No real-time bidirectional payroll sync — T3.
- No pension fund direct administration — delegated to external pension providers.
- No custom GL account mapping — per-contract-type account FK on `Employee`.

## Decisions

### D1 — Payroll is a sub-ledger that materialises GL transactions

Symmetric to D1 of `add-shillinq-accounts-receivable-core`: `Payroll` is a sub-ledger register; issuing a payroll period materialises a balanced `GLTransaction` (debit salary-expense, credit payroll-liability / bank) per the T1 `JournalEntry` pattern.

### D2 — Payroll calculation is delegated to external software or OR's calculation extension

`Payroll` schema declares the gross salary/wage input and deduction line items as outputs; the calculation logic itself (net = gross - deductions) is performed by external payroll software (reading via REST API) or by OR's calculation-workflow extension (if stable). Shillinq carries no in-app payroll math service.

If OR's calculation-workflow extension is not yet stable, ADR-031's exception path applies: a single-method `OCA\Shillinq\Payroll\PayrollCalculationGuard` ships, cited in the spec.

### D3 — Deductions are granular, auditable line items

Each deduction (loonbelasting, employee SV, employer SV, pension, garnishment) is a separate `Deduction` record linked to its parent `Payroll`. This enables per-deduction audit trail, rate validation, and integration with tax/social-security reporting (T3).

### D4 — Determination letters are archival documents with PDF attachment

`DeterminationLetter` (werkgeversverklaring, loonstrookje, salary certificate) is a generated, immutable document record with optional PDF attachment. No workflow — generated on payroll issue, archived for 7 years per Dutch law (Archiefwet).

### D5 — Tax/SV deduction aggregations validate against statutory rates

Per-employee annual totals (sum of annual deductions) are computed via aggregation; a precondition checks that deductions fall within statutory limits per `Employee.taxYear` and `taxClassification`. No PHP validation service.

### D6 — Detachering is a contract-type classification with placement-fee GL handling

`Employee.contractType` enum includes `detached` (gedetacheerde werknemer). Detached employees trigger placement-fee posting: `Payroll.placementFeeAmount` materialises as an AP transaction (vendor invoice) to `Payroll.placementAgencyId` on payroll issue.

### D7 — Payroll integration is REST API + webhook, not direct DB sync

External payroll software reads `Employee`, `Payroll`, `Deduction` schemas via OpenRegister REST API; Shillinq-originated changes publish webhooks (CloudEvents format) to a configured external endpoint. Bidirectional sync deferred to T3.

### D8 — UBL Peppol BIS 30 field shape declared, NOT computed

T2 declares the UBL payroll-disbursement field shape on `Payroll` (employee identifiers, salary/deduction breakdown) so T4 can attach outbound emission additively. T2 does not compute or emit UBL — that ships with T4 e-payroll outbound.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| Payroll record lifecycle | OR `x-openregister-lifecycle` (ADR-031) | Lifecycle on `Payroll` (`draft → calculated → issued → paid`); materialises balanced `GLTransaction` per T1 pattern |
| Payroll calculation | OR calculation-workflow (if stable; else gap) | Consumed via lifecycle reference or external software; PHP guard fallback per ADR-031 exception if needed |
| Tax/SV rate validation | OR `x-openregister-aggregations` | Aggregation precondition validating annual deductions against statutory limits |
| Annual deduction totals | OR `x-openregister-aggregations` | GROUP BY `(employeeId, deductionType)` summing annual `Deduction.amount` |
| Materialised GL posting (payroll issue) | T1 `JournalEntry` materialisation pattern | Same lifecycle action shape |
| Placement-fee posting (detachering) | T1 `JournalEntry` materialisation pattern | Debit fee-expense, credit AP to placement agency |
| Employee master | New T2 register (or OR contact abstraction if stable) | Per ADR-022 review |
| Determination letter generation | External rendering service (docudesk-style) | PDF attachment via OR `files` relation; no template engine in Shillinq |
| External payroll integration | OR REST API + webhook (per ADR-019 integration registry) | Bidirectional sync via CloudEvents; external software reads schemas, publishes `payroll-changed` event |
| UBL Peppol BIS 30 field shape | UBL/Peppol public standard | Declared as schema fields; not computed in T2 |
| Audit trail | T2 `bookkeeping-audit-trail` (automatic) | Automatic on lifecycle transitions; PII masking on BSN per ADR-005 |
| Manifest navigation | T1 manifest pattern | 6 entries (Employees, Payroll, Payroll Calendar, Deductions, Determination Letters, Tax/SV Reports) + their pages |

**Net new code in implementation cycle**: 4 schema declarations + 1 lifecycle block + 2 aggregations + 6 manifest entry pairs + optional external-integration webhook receiver. At most 1 single-method PHP guard (`PayrollCalculationGuard`) gated by ADR-031 exception.

## Declarative-vs-imperative decision (per ADR-031)

| Behaviour | Decision | Why |
|---|---|---|
| Payroll record lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Payroll calculation | Consumed from OR calculation-workflow if stable; else single-method `PayrollCalculationGuard` per ADR-031 exception; or delegated to external software | Resolution in discovery; spec shape-neutral |
| Tax/SV rate validation | Declarative (`x-openregister-aggregations` precondition) | Pure range check against statutory tables |
| Annual deduction aggregation | Declarative (`x-openregister-aggregations`) | GROUP BY + SUM |
| Payroll-issue GL posting | Lifecycle action invoking T1's materialisation extension | No new service |
| Placement-fee posting | Lifecycle action invoking T1's materialisation extension (detached employees only) | No new service |
| Determination letter generation | External rendering (docudesk or similar) or OR template service | No payroll-specific logic |
| UBL field shape | Declarative — schema fields | Declared, not computed in T2 |

No service class authored in this envelope (subject to ADR-031 exception: at most one single-method `PayrollCalculationGuard`).

## Seed Data

### Employee records

```json
{
  "@self": { "register": "shillinq", "schema": "Employee", "slug": "employee-001-john-smith" },
  "employeeNumber": "EMP-001",
  "legalName": "John Smith",
  "bsn": "123456789",
  "contractType": "employee",
  "taxClassification": "employee",
  "taxNumber": "119999999",
  "onboardingDate": "2024-01-15",
  "exitDate": null,
  "salaryScale": "scale-a",
  "contactEmail": "john.smith@example.com",
  "contactPhone": "+31612345678",
  "administrationId": "admin-001"
}
```

```json
{
  "@self": { "register": "shillinq", "schema": "Employee", "slug": "employee-002-maria-garcia" },
  "employeeNumber": "EMP-002",
  "legalName": "Maria Garcia",
  "bsn": "987654321",
  "contractType": "detached",
  "taxClassification": "detached-worker",
  "taxNumber": "119999998",
  "onboardingDate": "2024-03-01",
  "exitDate": null,
  "salaryScale": "scale-b",
  "placementAgencyId": "supplier-staffing-001",
  "contactEmail": "maria.garcia@example.com",
  "administrationId": "admin-001"
}
```

```json
{
  "@self": { "register": "shillinq", "schema": "Employee", "slug": "employee-003-freelancer-tech" },
  "employeeNumber": "EMP-003",
  "legalName": "Tech Solutions BV",
  "bsn": null,
  "contractType": "freelancer",
  "taxClassification": "b2b-contractor",
  "taxNumber": "NL002345678B01",
  "onboardingDate": "2024-02-01",
  "exitDate": null,
  "salaryScale": null,
  "contactEmail": "contact@techsolutions.nl",
  "administrationId": "admin-001"
}
```

### Payroll records (sample)

```json
{
  "@self": { "register": "shillinq", "schema": "Payroll", "slug": "payroll-2026-05-emp-001" },
  "payrollNumber": "PAY-2026-05-001",
  "employeeId": "employee-001-john-smith",
  "period": "2026-05",
  "periodStartDate": "2026-05-01",
  "periodEndDate": "2026-05-31",
  "grossAmount": 3500.00,
  "netAmount": 2650.00,
  "currency": "EUR",
  "status": "draft",
  "payDate": "2026-06-15",
  "administrationId": "admin-001"
}
```

```json
{
  "@self": { "register": "shillinq", "schema": "Payroll", "slug": "payroll-2026-05-emp-002" },
  "payrollNumber": "PAY-2026-05-002",
  "employeeId": "employee-002-maria-garcia",
  "period": "2026-05",
  "periodStartDate": "2026-05-01",
  "periodEndDate": "2026-05-31",
  "grossAmount": 2800.00,
  "netAmount": 2200.00,
  "placementFeeAmount": 350.00,
  "placementAgencyId": "supplier-staffing-001",
  "currency": "EUR",
  "status": "draft",
  "payDate": "2026-06-15",
  "administrationId": "admin-001"
}
```

### Deduction records

```json
{
  "@self": { "register": "shillinq", "schema": "Deduction", "slug": "deduction-2026-05-emp-001-tax" },
  "payrollId": "payroll-2026-05-emp-001",
  "deductionType": "income-tax",
  "deductionName": "Loonbelasting",
  "amount": 420.00,
  "rate": 12.0,
  "rateSource": "statutory-2026",
  "taxYear": 2026,
  "administrationId": "admin-001"
}
```

```json
{
  "@self": { "register": "shillinq", "schema": "Deduction", "slug": "deduction-2026-05-emp-001-sv" },
  "payrollId": "payroll-2026-05-emp-001",
  "deductionType": "social-security",
  "deductionName": "SV bijdrage werknemer",
  "amount": 430.00,
  "rate": 12.3,
  "rateSource": "statutory-2026",
  "taxYear": 2026,
  "administrationId": "admin-001"
}
```

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR calculation-workflow not yet stable | Spec shape-neutral; PHP guard fallback (`PayrollCalculationGuard`, single-method, ~20 LOC) per ADR-031 exception; remove when OR extension lands |
| Tax/SV statutory rates change annually | Seed data update task in tasks.md; no code changes required — annual register data import |
| BSN privacy (PII) in audit trail | Audit logs use `employeeId` FK only; BSN not logged per ADR-005 PII rule. Display masking configurable. |
| Detachering placement-fee reconciliation | Placement-fee posting creates AP transaction matched against vendor invoice via bank-rec (T2) |
| External payroll-software sync gaps | Webhook format (CloudEvents) well-defined; external software responsible for idempotency; Shillinq exposes REST API for polling fallback |
| Payroll archival compliance (7-year retention) | Retention policy per ADR on data archival; automatic destruction schedule post-retention period |
| UBL field shape drifts before T4 lands | Pin UBL Peppol BIS 30 in the spec; T4 attaches additively |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the four schemas (additive — no existing schema changes).
2. `src/manifest.json` is patched with 6 new menu entries + their pages (additive).
3. If OR's calculation-workflow extension is not yet stable, `lib/Payroll/PayrollCalculationGuard.php` ships (single method, ~20 LOC, ADR-031 exception annotated).
4. Seed data (sample employees, payroll records, deductions) imported on first app startup via `ConfigurationService::importFromApp()`.

Down-direction: registers are non-destructive — reverting removes the manifest entries; payroll records remain queryable but unreferenced.

## Open Questions

1. **OR calculation-workflow stability** — resolved in `opsx-ff` discovery; OR issue filed if needed.
2. **Tax/SV rate data source** — Belastingdienst statutory tables vs. third-party tax-software API. Resolved in implementing cycle.
3. **Detachering classification rules** — employee vs. contractor vs. freelancer; varying tax treatment. Industry-standard rules resolved during implementing cycle.
4. **Payroll archival compliance** — 7-year retention rule (BTW/loonbelasting); automated destruction schedule. Resolved per data-archival ADR.
5. **Determination letter PDF rendering** — in-app template (docudesk style) vs. external service (Peppol BIS 30 format). Resolved in implementing cycle's UX/compliance review.
6. **Real-time sync boundary** — T2 webhook inbound (one-way) vs. T3 bidirectional. Current scope fixed in T2; T3 umbrella change will expand.

