# Spec: bookkeeping-lease-accounting

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (advanced / specialized lease accounting)
**Depends on:** bookkeeping-lease-contracts, bookkeeping-general-ledger (T1), bookkeeping-fixed-assets-depreciation (T4-base)

## Summary

Lease accounting covers the recognition of Right-of-Use (RoU) assets and lease liabilities at commencement, the automatic generation of payment schedules, periodic interest and depreciation posting, and the integration with the GL and fixed-asset engines.

## ADDED Requirements

### Requirement: REQ-LA-001 — When a lease transitions to `active`, the system SHALL generate the opening RoU asset and lease liability

The system SHALL recognise the opening RoU asset and lease liability at activation. At the moment a `lease-contract` transitions from `draft` to `active`:

1. **Compute present value of unavoidable payments**: using the lease's IBR and the payment schedule (frequency, base amount, indexation, extension-options marked "reasonably certain"), compute the PV of all future contractual payments
2. **Opening Lease Liability** = PV
3. **Opening RoU Asset** = PV + initial-direct-costs + restoration-obligation PV − lease-incentives-received
4. **Post a journal entry** with two lines:
   - Debit: RoU asset (to a GL account flagged with `is-lease-account=true`, subtype=`rou-{asset-class}`)
   - Credit: Lease liability (to a GL account flagged with `is-lease-account=true`, subtype=`lease-liability-noncurrent`)
5. **Create a fixed-asset record** with `is-rou-asset=true`, `source-lease=<lease-id>`, asset-type="RoU-{asset-class}", depreciation-method=straight-line, useful-life=lease-term, purchase-cost=opening-RoU-asset

The journal entry is batched and posted at month-end along with other period postings (via bookkeeping-period-close).

#### Scenario: RoU asset and liability are posted on lease activation

- **GIVEN** a lease-contract with:
  - commencement-date = 2024-01-15
  - non-cancellable-term-months = 36
  - base-payment-amount = 1,000 (monthly)
  - ibr-percent = 4.0%
  - initial-direct-costs = 500
  - lease-incentives-received = 0
  - extension-options = [] (none)
- **WHEN** the lease transitions to `active` on 2024-01-15
- **THEN** the system computes:
  - PV of 36 × 1,000 monthly payments at 4% annual IBR = ~33,800 (using standard PV formula)
  - RoU asset = 33,800 + 500 = 34,300
  - A journal entry is queued:
    - Dr. RoU asset (account 1310-Leased vehicles) 34,300
    - Cr. Lease liability (account 2310-Lease obligation) 34,300
- **AND** a fixed-asset record is created with source-lease=<lease-id>

### Requirement: REQ-LA-002 — The system SHALL generate a `lease-payment-schedule` table for periodic reference

One row per payment period (monthly, quarterly, annual, as specified by payment-frequency) from lease commencement to end of term (or reasonably-certain extensions). Each row MUST carry:

| Field | Computation |
|---|---|
| `period-sequence` | 1, 2, 3, ..., N |
| `period-start`, `period-end` | Calculated from payment frequency |
| `payment-due-date` | Calculated per payment-timing (in-advance = period-start, in-arrears = period-end) |
| `contractual-payment-amount` | base-payment + indexation adjustment (if any) |
| `opening-lease-liability` | Closing liability from prior period (or opening balance on period 1) |
| `interest-accrued` | opening-liability × (ibr-percent / 12) for monthly, or (ibr-percent / 4) for quarterly, etc. |
| `payment-applied-total` | contractual-payment-amount |
| `payment-interest-portion` | interest-accrued |
| `payment-principal-portion` | payment-applied-total − interest-accrued |
| `closing-lease-liability` | opening-liability + interest-accrued − payment-principal-portion |
| `opening-rou-asset` | Closing RoU asset from prior period (or opening balance on period 1) |
| `depreciation-charge` | RoU asset / remaining lease months (straight-line) |
| `closing-rou-asset` | opening-rou-asset − depreciation-charge |

The schedule is generated and stored when a lease transitions to `active`. It is immutable until a reassessment event (modification, indexation, extension-option change) triggers regeneration from the event date forward.

#### Scenario: Payment schedule is generated for a 36-month lease

- **GIVEN** the lease-contract from REQ-LA-001 (36 months, 1,000/month, 4% IBR, 34,300 opening liability)
- **WHEN** the lease transitions to `active`
- **THEN** the system generates 36 rows in `lease-payment-schedule`:
  - Period 1: opening-liability=34,300, interest=114.33, payment=1,000, principal=885.67, closing=33,414.33
  - Period 2: opening-liability=33,414.33, interest=111.38, payment=1,000, principal=888.62, closing=32,525.71
  - ... (continuing with decreasing liability and increasing principal portion)
  - Period 36: opening-liability=~1,000, interest=~3.33, payment=1,000, principal=996.67, closing=~0.00

### Requirement: REQ-LA-003 — Periodic month-end posting SHALL generate GL lines for interest, principal payment, and depreciation

Periodic month-end posting SHALL generate the interest, principal, and depreciation GL lines for every active lease. At the end of each fiscal period (monthly, quarterly, as configured), the system:

1. Identifies all active leases with a payment due in that period
2. For each lease, queries the corresponding `lease-payment-schedule` row
3. Posts two journal entries:
   - **Interest + Principal Payment**:
     - Dr. Lease interest expense (GL account with `is-lease-account=true`, subtype=`lease-interest-expense`)
     - Cr. Lease liability (current portion, subtype=`lease-liability-current`) for principal
     - Cr. Bank account for the payment
   - **Depreciation**: Delegated to bookkeeping-fixed-assets-depreciation (the fixed-asset has `is-rou-asset=true`, so standard depreciation rules apply)

The journal entries are batched and routed through an approval gate (optional or required, depending on tenant configuration). Once approved, they are posted to the GL and marked with a source-lease FK (per ADR-022).

#### Scenario: Period-end posting generates interest and principal lines

- **GIVEN** a lease with a monthly payment due on 2024-02-15 (period-sequence=2)
- **WHEN** the period-close process runs for February 2024
- **THEN** the system queries the lease-payment-schedule for period 2:
  - opening-liability=33,414.33, interest=111.38, principal=888.62, payment=1,000
- **AND** posts a journal entry (or two, depending on GL structure):
  - Dr. Lease interest expense 111.38
  - Cr. Lease liability (current) 888.62
  - Cr. Bank account (EUR) 1,000
- **AND** the depreciation charge for the same period (34,300 / 36 = ~952.78) is posted by bookkeeping-fixed-assets-depreciation

### Requirement: REQ-LA-004 — The system SHALL track payment currency and FX rates for non-functional-currency leases

The system SHALL track payment currency and FX rates per period. If a lease's `payment-currency` differs from the company's functional currency (e.g., a EUR company paying a USD lease), the `lease-payment-schedule` must carry:

- `payment-currency` (e.g., USD)
- `fx-rate` (the EUR/USD rate at the payment date)
- GL posting is in functional currency: Cr. Bank (USD) is converted to EUR at the fx-rate

**Note**: Full multi-currency treatment (revaluation of the lease liability on every period close per IAS 21) is deferred to Phase 2 pending bookkeeping-multi-currency capability.

#### Scenario: Lease is paid in USD

- **GIVEN** a lease with payment-currency=USD, company functional-currency=EUR
- **WHEN** a payment is due on 2024-03-15, and the EUR/USD rate is 1.10
- **THEN** the payment-schedule row shows:
  - contractual-payment-amount (USD) = 1,000
  - fx-rate = 1.10
  - Payment in functional currency = 1,000 / 1.10 = 909 EUR
- **AND** the GL posting is:
  - Cr. Bank (USD) 1,000 (posting in USD account)
  - Cr. FX gain/loss (if the rate changed from prior period)

### Requirement: REQ-LA-005 — Restoration obligations SHALL be included in the opening RoU asset and recognized as a non-current liability

Restoration obligations SHALL be capitalised into the opening RoU asset and recognised as a separate non-current liability. If a lease specifies a restoration obligation (e.g., return the building to original condition, estimated cost EUR 75,000, discount rate 4.5%), the opening RoU asset includes:

- PV of restoration obligation = estimated-cost / (1 + discount-rate)^months

The restoration obligation is posted to a separate GL account (subtype=`lease-restoration-obligation`, classified as non-current liability).

#### Scenario: Office building lease includes restoration obligation

- **GIVEN** a lease with restoration-obligation = { estimated-cost: 75,000, discount-rate: 0.045 }
- **WHEN** the lease is activated
- **THEN** the system computes:
  - restoration-obligation-pv = 75,000 / (1.045^5) = ~59,100 (assuming 5-year term)
  - opening-RoU-asset += 59,100
  - A GL posting includes:
    - Dr. RoU asset 59,100
    - Cr. Lease restoration obligation 59,100

### Requirement: REQ-LA-006 — Prepaid or accrued rent SHALL be factored into the opening RoU asset balance

Prepaid or accrued rent SHALL adjust the opening RoU asset. If a lease has prepaid rent (e.g., the lessor required 3 months' rent upfront) or accrued rent (e.g., rent is payable in arrears but there's a timing difference), the opening RoU asset adjusts:

- opening-RoU-asset = PV + initial-direct-costs − lease-incentives − prepaid-rent-balance + accrued-rent-balance

Prepaid and accrued balances are tracked in the `lease-contract` schema and reconciled against the payment schedule.

#### Scenario: Lease has prepaid rent factored into RoU asset

- **GIVEN** a lease with opening-RoU-asset = 34,300 (before adjustment), and 3 months of prepaid rent = 3,000
- **WHEN** the lease is activated
- **THEN** the opening-RoU-asset is reduced:
  - adjusted-RoU-asset = 34,300 − 3,000 = 31,300
  - GL posting:
    - Dr. RoU asset 31,300
    - Cr. Lease liability 31,300
    - (The prepaid rent is already in the bank/cash GL from the advance payment)

---

## Verification

All REQ-LA requirements are testable via:
- Manual walk-through of a sample lease from activation to 3 months of postings
- Comparison of GL output to IFRS 16 illustrative examples (IASB *Illustrative Examples on IFRS 16 Leases*)
- Audit of a period-end payment schedule against contract payment terms

