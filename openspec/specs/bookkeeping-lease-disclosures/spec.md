---
status: done
---

# Spec: bookkeeping-lease-disclosures

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (advanced / specialized lease accounting)
**Depends on:** bookkeeping-lease-contracts, bookkeeping-lease-accounting, bookkeeping-lease-reassessment, bookkeeping-lease-exemptions

## Purpose

IFRS 16 paragraphs 51–60 mandate detailed quantitative and qualitative disclosures about leases. The disclosure-table register materializes this information at period close, allowing one-click export to XBRL and audit-ready PDF reports.

## Requirements

@e2e exclude unbuilt UI: lease disclosures pages not yet implemented


### REQ-LD-001: The system SHALL generate a `lease-disclosure-table` snapshot at period close

The system SHALL satisfy this requirement: The system SHALL generate a `lease-disclosure-table` snapshot at period close.

At the end of each fiscal period (annual, quarterly, as configured), the system:

1. Queries all `lease-contract` records with status = active or modified in the period
2. Aggregates data from `lease-payment-schedule` and `lease-reassessment-event` records
3. Generates a single `lease-disclosure-table` record per fiscal period with:

| Field | IFRS 16 Ref | Aggregation |
|---|---|---|
| fiscal-period | 51 | The ISO 8601 date range (e.g., "2024-01-01 to 2024-12-31") |
| total-rou-asset-by-class | 51(a) | Sum of closing RoU asset by asset-class (vehicles, real-estate, IT, machinery, other) |
| total-rou-additions-in-period | 51(b) | Sum of new RoU assets recognized in the period (from new leases + scope modifications) |
| total-rou-depreciation-in-period | 51(b) | Sum of RoU depreciation charged in the period |
| total-rou-disposals-in-period | 51(b) | Sum of RoU assets disposed/terminated in the period |
| closing-rou-by-class | 51(c) | Closing RoU asset by class (detail, not just total) |
| total-lease-liability-current | 51(d) | Current portion of lease liability (due within 12 months) |
| total-lease-liability-noncurrent | 51(d) | Non-current portion |
| maturity-analysis | 52 | Undiscounted future lease payments bucketed: <1y, 1-2y, 2-3y, 3-4y, 4-5y, >5y |
| weighted-average-ibr-by-class | 51(e) | Weighted average IBR by asset class |
| total-interest-expense | 53(d) | Total interest accrued on lease liabilities in the period |
| total-short-term-lease-expense | 53(e) | Total expense from IFRS 16.5 short-term exempt leases |
| total-low-value-lease-expense | 53(f) | Total expense from IFRS 16.6 low-value exempt leases |
| total-variable-lease-expense | 53(g) | Total variable lease expense (e.g., indexation adjustments beyond fixed contract terms) |
| total-sublease-income | 53(h) | Total income from subleases (Phase 2) |
| cash-outflow-for-leases-operating | 54 | Total cash paid for operating leases (Phase 2) |
| cash-outflow-for-leases-financing | 54 | Total cash paid for financing leases (GL posting basis) |
| qualitative-narrative-seeds | 59 | Seed text for disclosures (nature of leasing activities, future cash commitments, restrictions/covenants, off-balance-sheet commitments) |

#### Scenario: Annual disclosure table is generated at 2024 year-end

- **GIVEN** an entity with 50 active leases across vehicles (15), real-estate (8), IT (20), machinery (7)
- **WHEN** the period-close process runs on 2024-12-31
- **THEN** a `lease-disclosure-table` record is generated:
  - fiscal-period = "2024-01-01 to 2024-12-31"
  - total-rou-asset-by-class = { vehicles: 150,000, real-estate: 2,500,000, IT: 45,000, machinery: 120,000, other: 0 }
  - total-rou-additions = 450,000 (12 new leases in 2024)
  - total-rou-depreciation = 320,000
  - total-rou-disposals = 50,000 (2 leases terminated)
  - weighted-average-ibr-by-class = { vehicles: 4.2%, real-estate: 3.8%, IT: 4.5%, machinery: 4.0% }
  - maturity-analysis = { "<1y": 450,000, "1-2y": 430,000, "2-3y": 420,000, ... ">5y": 800,000 }

### REQ-LD-002: The maturity analysis SHALL be disclosed in undiscounted future cash-flow basis

IFRS 16.52(a) requires the maturity analysis to show undiscounted future lease payments (not PV). The table MUST include:

- Lease payments due within 12 months
- Lease payments due 1-2 years, 2-3 years, 3-4 years, 4-5 years, and more than 5 years
- The sum of all payments (which will exceed the current lease-liability balance due to the discount applied at commencement)

#### Scenario: Maturity analysis bucket aggregation

- **GIVEN** a lease with remaining term = 48 months, monthly payment = 1,000
- **WHEN** the disclosure table is generated
- **THEN** the maturity-analysis bucket "<1y" includes 12 × 1,000 = 12,000 (undiscounted)
- **AND** the bucket "1-2y" includes 12 × 1,000 = 12,000
- **AND** the sum across all buckets = 48 × 1,000 = 48,000 (undiscounted total future payments)
- **BUT** the current lease-liability on the balance sheet may be only ~45,000 (PV discounted at the IBR)

### REQ-LD-003: Weighted-average IBR SHALL be calculated per asset class

IFRS 16.51(e) requires disclosure of the weighted-average IBR. The system MUST compute:

- For each asset-class, the average IBR weighted by the opening lease-liability balance

**Formula**: (Sum of (lease-liability-opening × ibr-percent) per lease) / (Sum of lease-liability-opening per asset-class)

#### Scenario: Weighted-average IBR calculation

- **GIVEN** two vehicle leases:
  - Lease 1: opening-liability = 30,000, IBR = 4.0%
  - Lease 2: opening-liability = 20,000, IBR = 4.5%
- **WHEN** the disclosure table is generated
- **THEN** weighted-average-ibr-vehicles = (30,000 × 4.0% + 20,000 × 4.5%) / (30,000 + 20,000) = (1,200 + 900) / 50,000 = 4.2%

### REQ-LD-004: The disclosure table SHALL be auditor-friendly and exportable

The materialized `lease-disclosure-table` record MUST support:

1. **Export to PDF**: A one-click download generates an IFRS 16 disclosure note (English or Dutch) suitable for inclusion in the financial statements notes section
2. **Export to CSV**: Raw data export for manual audit procedures or import into Excel
3. **Export to XBRL**: Integration with bookkeeping-sbr-xbrl-reporting to emit ESEF/EFRAG iXBRL tags (Phase 2)
4. **Audit sign-off**: The table carries an approval-date and approver (FK to organisations.person-id) confirming the disclosure has been reviewed

#### Scenario: CFO exports IFRS 16 disclosure note to PDF

- **GIVEN** a materialized `lease-disclosure-table` for FY 2024
- **WHEN** the CFO clicks "Export Disclosure Note to PDF"
- **THEN** the system generates a PDF containing:
  - IFRS 16.51(a)-(e) quantitative disclosures (RoU, liability, IBR)
  - IFRS 16.52 maturity analysis table
  - IFRS 16.53(d)-(h) expense breakdown (interest, short-term, low-value, variable)
  - IFRS 16.59 qualitative narrative (nature of leasing, future commitments, restrictions)
  - Boilerplate Dutch IFRS 16 guidance (legal citations)
- **AND** the PDF is signed with the CFO's name and approval date

### REQ-LD-005: The disclosure table SHALL include qualitative narrative seeds

The system SHALL satisfy this requirement: The disclosure table SHALL include qualitative narrative seeds.

IFRS 16.59 requires qualitative disclosures:

- Nature of the entity's leasing activities
- Future cash outflows not yet reflected in lease liabilities (e.g., extension options not yet deemed "reasonably certain")
- Restrictions or covenants imposed by leases (e.g., lessor-mandated insurance, maintenance)
- Sale-and-leaseback transactions (if any)

The `lease-disclosure-table.qualitative-narrative-seeds` field is populated with template text that the operator can refine. For example:

```
**Nature of leasing activities:**
The entity leases [NUMBER] assets across the following classes:
- Vehicles: [NUMBER], total lease liability [AMOUNT]
- Real estate (buildings): [NUMBER], total lease liability [AMOUNT]
- IT hardware: [NUMBER], total lease liability [AMOUNT]
- Machinery and equipment: [NUMBER], total lease liability [AMOUNT]

**Future cash outflows:**
The entity has [NUMBER] extension options with exercise likelihood "possible" or "unlikely" 
that are not included in the lease liability as of [PERIOD-END]:
- Lease [VH-2024-001]: 2-year extension, estimated additional payments [AMOUNT]
- ...

**Restrictions and covenants:**
[Operator to populate from lease contracts]

**Off-balance-sheet commitments:**
[Operator to populate]
```

#### Scenario: Operator populates qualitative narrative

- **GIVEN** a `lease-disclosure-table` with qualitative-narrative-seeds containing templates
- **WHEN** the operator opens the table and clicks "Edit Qualitative Narrative"
- **THEN** the operator sees the template text and fills in actual numbers and details from the lease register
- **AND** the completed narrative is stored in the disclosure-table record and exported with the PDF

### REQ-LD-006: Period-end disclosure table SHALL be materialized and immutable

The system SHALL satisfy this requirement: Period-end disclosure table SHALL be materialized and immutable.

Once a `lease-disclosure-table` is generated and materialized at period close, it is immutable (read-only after approval). If lease data is later corrected (e.g., a lease is re-classified), a correction entry is made to the disclosure-table with a note explaining the restatement, but the original materialized snapshot is preserved for audit trail purposes.

This prevents accidental changes to published disclosures and ensures auditors can compare period-to-period changes reliably.

#### Scenario: Lease is reclassified in a later period; prior disclosure is unchanged

- **GIVEN** a lease-contract classified as `IFRS16-capitalised` in 2024, included in the 2024 year-end disclosure-table
- **WHEN** in 2025-Q1, the lease is reclassified to `short-term-exempt` (after reviewing the contract, it was actually a 12-month renewal)
- **THEN** the 2024 disclosure-table remains immutable and unchanged
- **AND** a restatement note is added to the 2025 disclosure-table: "The 2024 disclosure-table included [Lease Number] as capitalised; subsequent review determined it qualifies for short-term exemption. Restated 2024 figures are below."
- **AND** the restated 2024 figures are recalculated and disclosed in the 2025 note

### REQ-LD-007: The disclosure table SHALL support in-app narrative validation

The system SHALL satisfy this requirement: The disclosure table SHALL support in-app narrative validation.

Before period-close, the operator is prompted to validate that the qualitative narrative is complete and accurate:

1. **Reviewer checklist**: "Nature of leasing activities" — yes/no
2. "Future cash-flow commitments disclosed" — yes/no
3. "Restrictions and covenants disclosed" — yes/no
4. "No material off-balance-sheet commitments omitted" — yes/no

The disclosure-table is marked as "ready for approval" only after all checklist items are confirmed.

#### Scenario: Operator validates disclosures before period close

- **GIVEN** a `lease-disclosure-table` for FY 2024 with qualitative narrative completed
- **WHEN** the operator clicks "Validate Disclosures"
- **THEN** the system displays a checklist of 4 items (nature, future commitments, restrictions, off-balance-sheet)
- **AND** the operator confirms each item (or notes "N/A" with justification)
- **AND** once all items are checked, the table is marked "approved" and ready for export

---

## Verification

All REQ-LD requirements are testable via:
- Generation of a complete disclosure-table for a sample lease portfolio
- Comparison of quantitative aggregations (RoU, liability, IBR, maturity analysis) against manual spreadsheet calculations
- Verification that PDF and CSV exports contain the expected data without formatting errors
- Audit of the immutability of materialized disclosure-tables and restatement handling

