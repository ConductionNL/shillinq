# Design — IFRS 16 Leases

## Context

IFRS 16 (effective 1 January 2019) requires lessees to recognise virtually all leases longer than 12 months and worth more than ~EUR 5,000 as a Right-of-Use (RoU) asset and a corresponding lease liability on the balance sheet. Before IFRS 16, most leases (office, vehicles, equipment) were "operating leases" and appeared in the P&L only as monthly expense; now they are capitalised. The accounting is mechanically correct but operationally painful: a customer with 50–500 leases must track contract terms, extension options, IBR, indexation clauses, modifications, and generate disclosure notes every period.

This capability lands in Shillinq as a tier-4-specialised change (parallel to T4-base), adding five new register schemas, integrating with the GL and fixed-asset depreciation engines, and providing disclosure-table automation. The feature is spec-only; implementation lands later via opsx-apply.

## Goals

- Express the entire IFRS 16 surface as declarative metadata — schemas with `x-openregister-lifecycle` rules and manifest entries — per ADR-031. No custom IFRS 16 service classes.
- Consume every OpenRegister and Shillinq abstraction that already exists for GL posting (bookkeeping-general-ledger), fixed-asset lifecycle (bookkeeping-fixed-assets-depreciation), organisations (lessor counterparties), and document attachment (docudesk).
- Make the spec auditor-friendly: a Big-4 senior associate should recognise the model as IFRS 16-compliant with no surprises (RoU asset + liability, payment schedules, reassessment events, disclosure tables).
- Lay the foundation for Phase-2 extensions: multi-currency leases, CSRD E1 carbon footprint, lessor accounting (IFRS 16.63+), sale-and-leaseback detailed accounting, cloud-infra commitments.

## Non-Goals

- ASC 842 (US GAAP) reconciliation — out of scope; documented in proposal as Phase 2.
- Real-time analytics dashboard — disclosure table is the deliverable; dashboards are Phase 2.
- Lessor accounting — out of scope; Shillinq is for lessees only.
- Integration with Visual Lease / LeaseAccelerator data migration — deferred; planned as a docudesk import profile.

## Decisions

### D1 — Five separate schemas with FK relations, not a monolithic lease record

The IFRS 16 model naturally splits into:
- **`lease-contract`** — master register, one row per lease; attributes like lessor, term, IBR, classification
- **`lease-payment-schedule`** — derived table, one row per contractual payment period; interest accrual, liability reductions
- **`lease-reassessment-event`** — log of modifications/remeasurements; before/after snapshots, GL postings, approvals
- **`lease-disclosure-table`** — period-end snapshot; aggregated RoU, liability, expense, maturity analysis
- **`lease-portfolio-exemption`** — policy record; short-term and low-value election by asset class

Each schema is a separate OpenRegister record type (per ADR-024), not fields within a single lease record. This allows:
- **Auditability**: every reassessment event is a timestamped, signed record with before/after GL snapshots.
- **Reusability**: a disclosure-table row can be queried without loading the full lease contract; a payment-schedule row can be updated independently if a reconciliation adjustment is needed.
- **Extensibility**: Phase-2 extensions (e.g., sublease income tracking, lease assets by cost centre) add new schemas without reshaping the core five.

**Alternative considered**: A single `Lease` record with nested `paymentSchedules[]`, `reassessmentEvents[]`, `disclosureTableRows[]` arrays. Rejected — the nested arrays prevent OR's generic CRUD from working; queries (e.g., "all payment periods in the next 90 days") require app-local aggregation; reassessment events are not fully auditable because they are embedded.

### D2 — Payment schedule is derived, not manually entered

The `lease-payment-schedule` is computed and stored (not calculated on-the-fly) when a lease transitions to `active`. The system:
1. Takes the lease's commencement date, payment frequency, base payment amount, indexation clause, extension options marked "reasonably certain"
2. Builds an array of periods from commencement to lease term end (or reasonably-certain extension end)
3. For each period: computes expected payment (base + indexation), applies the periodic IBR, derives interest, principal, and closing liability
4. Stores one `lease-payment-schedule` row per period

Benefits:
- **Predictability**: the next period's posting is deterministic; no "missing indexation clause" surprises at month-end.
- **Auditability**: the schedule is a timestamped snapshot; if a modification happens mid-term, the before/after schedule is compared.
- **Performance**: the disclosure table aggregates pre-computed schedules in O(n) time, not O(n²) recalculation.

The schedule is regenerated when a lease is reassessed (e.g., an indexation event or extension-option reassessment changes the term or payment).

**Alternative considered**: Calculate schedule on-the-fly per query. Rejected — the calculation is complex (IBR curve interpolation, indexation lookups, extension-option likelihood scoring) and non-idempotent if any source data changes; not suitable for near-real-time GL posting.

### D3 — RoU asset is a fixed-asset, not a GL account

When a lease transitions to `active`, the system:
1. Creates a `fixed-asset` record with `is-rou-asset = true`, `source-lease = <lease-id>`, `asset-type = "RoU (vehicles/real-estate/IT/other)"`, and `depreciation-schedule = straight-line over lease term`
2. Posts the opening RoU asset and lease liability via a journal entry (consumed from bookkeeping-journal-entries)

This integrates RoU assets into the standard fixed-asset depreciation engine: monthly depreciation is computed automatically by bookkeeping-fixed-assets-depreciation, not by custom IFRS 16 code. Auditors see RoU assets *as fixed assets* in the asset register, not as a separate GL line item.

**Alternative considered**: Store RoU asset balance directly on a GL account (e.g., `1110-Leased vehicles`); depreciation is a custom IFRS 16 calculation. Rejected — loses the integration with the depreciation engine, prevents auditors from navigating the asset lifecycle, and requires custom deferred-tax calculation (depreciation feeds deferred-tax in bookkeeping-multi-currency or Phase-2 tax module).

### D4 — Reassessment event as a first-class record, not a journal-entry detail

Every IFRS 16 modification/remeasurement (indexation, extension-option reassessment, term change, IBR reset, etc.) creates a `lease-reassessment-event` record. This record:
- Carries before/after `lease-contract` snapshots (JSON blobs)
- Links to one or more GL postings (interest catch-up, liability adjustment, gain/loss)
- Requires approver sign-off (delegated to decidesk if RoU impact > EUR 100K)
- Is immutable once posted; any correction is a new event

Benefits:
- **Auditability**: the auditor can walk every lease from commencement to period-end and see every reassessment with justification.
- **Reproducibility**: a re-audit in year+2 can re-run the entire lease schedule and confirm it matches the Shillinq output.
- **Integration with decidesk**: material events (>EUR 100K) route through board-decision workflows; the link is bidirectional (the decidesk issue references the reassessment event).

**Alternative considered**: Reassessment as a GL-posting annotation. Rejected — GL postings are atomic and immutable; a reassessment may involve multiple GL lines (interest catch-up, liability reduction, gain/loss), and each would need the same annotation, leading to redundancy and audit confusion.

### D5 — Disclosure table is generated on-demand and materialized at period close

The `lease-disclosure-table` is a period-end snapshot (one row per fiscal year / quarter). It is generated on-demand by querying all active leases, their payment schedules, and reassessment events, then aggregating (sum, average, maturity bucketing). It is materialized (stored as a record) at period close so:
- Auditors can compare period-to-period changes (is the RoU reduction expected?).
- Corrections to prior-period leases do not retroactively change the published disclosure (instead, a note explains the restatement).
- Export to XBRL / SBR is against the materialized snapshot, not a live query.

**Alternative considered**: Compute disclosure table on export only, never store it. Rejected — a customer corrects a lease in Q2 2026; the period-close disclosure for Q1 2026 (already published) should not change; but a live query would re-aggregate Q1's leases against the corrected contract, leading to restatement confusion.

## Schema Sketch (for design review)

```yaml
lease-contract:
  primary-key: lease-number (sequential, per-organisation)
  attributes:
    - lease-number
    - counterparty (FK organisations.organisation-id)
    - description
    - asset-class (enum: vehicle | real-estate | IT-hardware | machinery | other)
    - commencement-date
    - end-date
    - non-cancellable-term-months
    - extension-options (array: { months, exercise-likelihood, exercise-by-date })
    - termination-options (array: { months-after-commencement, penalty-amount })
    - payment-frequency (monthly | quarterly | annual | irregular)
    - payment-timing (in-advance | in-arrears)
    - base-payment-amount
    - payment-currency (ISO 4217)
    - indexation-clause (none | fixed-percent-per-year | CPI | sector-index-name)
    - indexation-reset-frequency (annual | quarterly | on-demand)
    - residual-value-guarantee (yes/no, if yes: estimated-amount)
    - purchase-option (price, exercise-likelihood)
    - initial-direct-costs
    - lease-incentives-received
    - restoration-obligation (estimated-cost, discount-rate)
    - ibr-percent
    - ibr-derivation-method (group-policy | yield-curve | weighted-average | external-quote)
    - ibr-source-document (FK docudesk-document-id)
    - classification (finance-lease | operating-pre-IFRS16 | IFRS16-capitalised | short-term-exempt | low-value-exempt)
    - classification-rationale (free-text + checklist JSON)
    - transition-method (modified-retrospective | full-retrospective | N/A-post-transition)
    - status (draft | active | modified | terminated | expired)
    - created-at (timestamp)
    - approver (FK organisations.person-id)
    - approval-date (timestamp)

lease-payment-schedule:
  primary-key: (lease-contract-id, period-sequence)
  attributes:
    - lease-contract (FK)
    - period-sequence (1, 2, 3, ... N)
    - period-start (date)
    - period-end (date)
    - payment-due-date
    - contractual-payment-amount
    - payment-currency
    - fx-rate (if non-functional-currency)
    - opening-lease-liability
    - interest-accrued (opening-liability × periodic-IBR)
    - payment-applied-total
    - payment-interest-portion (computed)
    - payment-principal-portion (computed)
    - closing-lease-liability
    - opening-rou-asset
    - depreciation-charge
    - closing-rou-asset
    - posted-to-gl (FK bookkeeping-general-ledger-transaction-id, or null)

lease-reassessment-event:
  attributes:
    - reassessment-number (sequential per lease)
    - lease-contract (FK)
    - event-date
    - event-type (enum: indexation-remeasurement | extension-option-reassessment | termination-option-reassessment | payment-modification | scope-modification | term-modification | IBR-reset | impairment | abandonment | partial-termination | full-termination)
    - trigger-description (free-text)
    - old-contract-snapshot (JSON lease-contract fields as of event-date-1)
    - new-contract-snapshot (JSON lease-contract fields post-event)
    - remeasurement-approach (catch-up-adjustment | prospective | separate-lease)
    - revised-ibr-percent (if IBR-reset event)
    - revised-ibr-rationale (free-text)
    - pre-event-lease-liability
    - post-event-lease-liability
    - rou-asset-adjustment
    - pl-impact (gain/loss on modification)
    - supporting-documents (array: FK docudesk-document-ids)
    - approver (FK organisations.person-id)
    - approval-date (timestamp)
    - posted-to-gl (FK bookkeeping-general-ledger-transaction-id, or null)

lease-disclosure-table:
  primary-key: (organisation-id, fiscal-period)
  attributes:
    - fiscal-period (ISO 8601 YYYY-MM-DD range, e.g., "2026-01-01 to 2026-12-31")
    - total-rou-asset-by-class (object: { vehicles, real-estate, IT-hardware, machinery, other })
    - total-rou-additions-in-period
    - total-rou-depreciation-in-period
    - total-rou-disposals-in-period
    - closing-rou-by-class (object)
    - total-lease-liability-current
    - total-lease-liability-noncurrent
    - maturity-analysis (object: { <1y, 1-2y, 2-3y, 3-4y, 4-5y, >5y } => amounts)
    - weighted-average-ibr-by-class (object)
    - total-interest-expense
    - total-short-term-lease-expense
    - total-low-value-lease-expense
    - total-variable-lease-expense (e.g., indexation adjustments)
    - total-sublease-income
    - cash-outflow-for-leases-operating-portion
    - cash-outflow-for-leases-financing-portion
    - qualitative-narrative (free-text seed for disclosure note)
    - generated-at (timestamp)
    - materialized-at-period-close (boolean, timestamp)

lease-portfolio-exemption:
  primary-key: (organisation-id, effective-date)
  attributes:
    - policy-effective-date
    - short-term-by-class (object: { vehicles: yes/no, real-estate: yes/no, IT: yes/no, machinery: yes/no, other: yes/no })
    - low-value-threshold (amount in functional currency)
    - low-value-by-class (object: same keys as short-term)
    - policy-approver (FK organisations.person-id)
    - policy-document (FK docudesk-document-id)
    - superseded-by (self-FK for policy history)
```

Also extend:
- **`fixed-asset`** (existing): add `is-rou-asset` boolean, `source-lease` FK to lease-contract
- **`Account`** (existing): add `is-lease-account` boolean, `lease-account-subtype` enum (rou-vehicles | rou-real-estate | rou-IT | rou-other | lease-liability-current | lease-liability-noncurrent | lease-interest-expense | lease-depreciation | short-term-lease-expense | low-value-lease-expense)

## Seed Data Examples

### Example 1: Vehicle lease (typical)
```json
{
  "lease-number": "VH-2024-001",
  "counterparty": "Lease-Hire BV (KvK 12345678)",
  "description": "Company car lease – Mercedes E-class, 3-year term with 2-year extension option",
  "asset-class": "vehicle",
  "commencement-date": "2024-01-15",
  "end-date": "2027-01-14",
  "non-cancellable-term-months": 36,
  "extension-options": [
    {
      "months": 24,
      "exercise-likelihood": "reasonably-certain",
      "exercise-by-date": "2026-06-14"
    }
  ],
  "termination-options": [],
  "payment-frequency": "monthly",
  "payment-timing": "in-arrears",
  "base-payment-amount": 425.00,
  "payment-currency": "EUR",
  "indexation-clause": "fixed-percent-per-year",
  "indexation-percent": 2.0,
  "indexation-reset-frequency": "annual",
  "residual-value-guarantee": false,
  "purchase-option": null,
  "initial-direct-costs": 150.00,
  "lease-incentives-received": 0.00,
  "restoration-obligation": {
    "estimated-cost": 2500.00,
    "discount-rate": 0.04
  },
  "ibr-percent": 4.25,
  "ibr-derivation-method": "group-policy",
  "ibr-source-document": "IBR_matrix_2024-Q1.pdf",
  "classification": "IFRS16-capitalised",
  "transition-method": "N/A-post-transition",
  "status": "active",
  "approver": "CFO (Jan de Vries)"
}
```

### Example 2: Office building lease (real estate, with indexation)
```json
{
  "lease-number": "RE-2023-005",
  "counterparty": "Property Partners NV (KvK 87654321)",
  "description": "Headquarters lease – Amsterdam, 8-story office building, 5-year non-cancellable term, renewal negotiable",
  "asset-class": "real-estate",
  "commencement-date": "2023-03-01",
  "end-date": "2028-02-28",
  "non-cancellable-term-months": 60,
  "extension-options": [
    {
      "months": 60,
      "exercise-likelihood": "reasonably-certain",
      "exercise-by-date": "2027-06-01"
    }
  ],
  "termination-options": [],
  "payment-frequency": "quarterly",
  "payment-timing": "in-advance",
  "base-payment-amount": 95000.00,
  "payment-currency": "EUR",
  "indexation-clause": "CPI",
  "indexation-cpi-basket": "Dutch consumer price index",
  "indexation-reset-frequency": "annual",
  "residual-value-guarantee": false,
  "purchase-option": null,
  "initial-direct-costs": 5000.00,
  "lease-incentives-received": 10000.00,
  "restoration-obligation": {
    "estimated-cost": 75000.00,
    "discount-rate": 0.045
  },
  "ibr-percent": 3.85,
  "ibr-derivation-method": "yield-curve",
  "ibr-source-document": "IBR_derivation_2023-03-01.xlsx",
  "classification": "IFRS16-capitalised",
  "transition-method": "N/A-post-transition",
  "status": "active",
  "approver": "Treasurer (Maria García)"
}
```

### Example 3: Short-term exemption (copier lease)
```json
{
  "lease-number": "IT-2024-032",
  "counterparty": "Copier Rental Solutions BV (KvK 11111111)",
  "description": "Copier/printer lease – Xerox AltaLink, 12-month non-cancellable term",
  "asset-class": "IT-hardware",
  "commencement-date": "2024-06-01",
  "end-date": "2025-05-31",
  "non-cancellable-term-months": 12,
  "extension-options": [],
  "termination-options": [],
  "payment-frequency": "monthly",
  "payment-timing": "in-arrears",
  "base-payment-amount": 850.00,
  "payment-currency": "EUR",
  "indexation-clause": "none",
  "residual-value-guarantee": false,
  "purchase-option": null,
  "initial-direct-costs": 0.00,
  "lease-incentives-received": 0.00,
  "restoration-obligation": null,
  "ibr-percent": 0.0,
  "ibr-derivation-method": "N/A",
  "classification": "short-term-exempt",
  "classification-rationale": "Non-cancellable term is exactly 12 months; IFRS 16.5 exemption applied",
  "transition-method": "N/A-post-transition",
  "status": "active",
  "approver": "Lease Administrator (Petra Jansen)"
}
```

## Reuse Analysis

| Capability | Reused from | Reused interface | Note |
|---|---|---|---|
| GL posting (RoU asset, liability, interest, depreciation) | bookkeeping-general-ledger | Journal entry API; posts via `JournalEntry.create()` | No custom GL code |
| Fixed-asset lifecycle (depreciation schedule) | bookkeeping-fixed-assets-depreciation | Fixed-asset schema + depreciation-schedule engine; RoU assets flagged as `is-rou-asset=true` | Depreciation is automatic; no custom calculation |
| Lessor counterparty | organisations | Organisation record; FK to `counterparty-id` | Sourced from the existing organisations register |
| Source contract PDF | docudesk | Document FK + file attachment; stored in docudesk, referenced by `ibr-source-document` FK | No local file storage |
| Approval workflow | openregister (built-in) | Approval-workflow abstraction; lifecycle transitions on `lease-reassessment-event` route through decidesk if threshold exceeded | Borrowed from OR; no custom approval code |
| Audit trail | openregister (built-in) | OR audit-trail-immutable; every schema change is logged | No custom audit table |
| XBRL/SBR export | bookkeeping-sbr-xbrl-reporting | Disclosure-table schema mapped to ESEF/EFRAG taxonomy | Integration is downstream; this change exports CSV/PDF disclosure table |

## Known Limitations (Phase 2)

1. **Multi-currency leases**: Lease denominated in USD, company functional currency EUR. Payment schedule is in USD; monthly FX revaluation needed. Deferred pending bookkeeping-multi-currency capability.
2. **Lessor accounting**: IFRS 16.63+. Shillinq is lessee-only; lessor accounting (finance vs operating, lease receivables, residual value recognition) is out of scope.
3. **Sale-and-leaseback detailed**: IFRS 16.99A (November 2023 amendments). RoU asset may be at fair value or adjusted based on leaseback treatment. Deferred.
4. **Sublease income**: A lessee may sublease part of the RoU asset and record sublease income. Currently, the disclosure table has a `total-sublease-income` field but no tracking of sublease contracts. Phase 2 will add a `subleases` register.
5. **Cloud-infra commitments**: Clauses for dedicated servers, dark fibre, etc., increasingly look like leases if the customer controls the identified asset. The schema can express them (asset-class: "other"), but the IBR derivation for these contracts is unclear; Phase 2 will add guidance.

---

## See also

- IFRS 16 *Leases* (IASB 2016, effective 1 January 2019)
- EFRAG *Leases — Implementation Guidance* (November 2023)
- RJ 292 *Richtlijnen voor de Jaarverslaggeving* — Dutch GAAP lease accounting
- ADR-031 (declarative-first, schema-driven business logic)
- ADR-022 (consume OR abstractions; no parallel tables)
