# Spec: bookkeeping-lease-exemptions

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (advanced / specialized lease accounting)
**Depends on:** bookkeeping-lease-contracts

## Summary

IFRS 16 provides exemptions for short-term leases (≤12 months at commencement) and low-value leases (asset fair value ≤ ~USD 5,000). Leases eligible for exemption are expensed straight-line over the lease term instead of being capitalized. This spec defines the policy elections, contract-by-contract application, and expense posting.

## ADDED Requirements

### Requirement: REQ-LE-001 — Entities SHALL elect portfolio-wide exemption policies

Entities SHALL record portfolio-wide exemption-policy elections in the `lease-portfolio-exemption` register. The register records an entity's policy elections:

| Field | Purpose |
|---|---|
| `policy-effective-date` | Date the policy takes effect |
| `short-term-by-class` | Object: { vehicles: yes/no, real-estate: yes/no, IT-hardware: yes/no, machinery: yes/no, other: yes/no } — whether to apply IFRS 16.5 short-term exemption per asset class |
| `low-value-threshold` | Currency amount (e.g., 5,000 in EUR) — the fair value threshold for low-value assets; the entity may set this differently from the standard ~USD 5,000 |
| `low-value-by-class` | Object: same structure as short-term-by-class — whether to apply IFRS 16.6 low-value exemption per asset class |
| `policy-approver` | FK to organisations.person-id (CFO or lease manager who approved the policy) |
| `policy-document` | FK to docudesk document (e.g., a signed board policy) |
| `superseded-by` | Self-FK to a later `lease-portfolio-exemption` record if the policy changes |

The policy is portfolio-wide: all leases of a given class follow the same exemption election. Individual leases may override the policy only with documented justification (stored in the `lease-contract.classification-rationale` field).

#### Scenario: Entity establishes exemption policy

- **GIVEN** an entity with many vehicle leases (some short-term rentals, some long-term) and no clear exemption policy
- **WHEN** the lease manager drafts a policy: "Vehicles: apply short-term exemption (≤12 months). Low-value exemption: not applied (most vehicles > EUR 5K)."
- **THEN** the policy is saved as a `lease-portfolio-exemption` record:
  - policy-effective-date = 2024-01-01
  - short-term-by-class = { vehicles: yes, real-estate: no, IT-hardware: yes, machinery: no, other: no }
  - low-value-by-class = { vehicles: no, real-estate: no, IT-hardware: no, machinery: no, other: no }
  - The CFO approves and signs

### Requirement: REQ-LE-002 — Short-term exemption (IFRS 16.5) applies to leases with non-cancellable term ≤ 12 months

The short-term exemption SHALL apply to leases meeting all of the criteria below. A lease qualifies for short-term exemption if:
- Non-cancellable term at commencement is ≤ 12 months (IFRS 16.5)
- The entity's portfolio exemption policy elects to apply the exemption for the lease's asset class
- The lease does NOT contain an option to purchase the asset (if it does, the exemption is not allowed)

Once a lease is classified as `short-term-exempt`, all contractual payments are expensed straight-line (not capitalized as RoU asset and liability):

- Monthly expense = (total-lease-payments) / (lease-term-in-months)

No fixed-asset record is created; the GL posting is:
- Dr. Short-term lease expense (account subtype=`short-term-lease-expense`)
- Cr. Bank (on each payment date)

#### Scenario: 12-month vehicle rental qualifies for exemption

- **GIVEN** a lease-contract:
  - asset-class = vehicle
  - non-cancellable-term-months = 12
  - payment-frequency = monthly, base-payment-amount = 500
  - entity's policy: short-term-by-class.vehicles = yes
- **WHEN** the lease is classified
- **THEN** the classification = `short-term-exempt`
- **AND** the total expense = 500 × 12 = 6,000 (posted monthly as 500)
- **AND** no RoU asset or liability is recognized

### Requirement: REQ-LE-003 — Low-value exemption (IFRS 16.6) applies to leases of assets with fair value when new ≤ threshold

The low-value exemption SHALL apply to leases meeting the criteria below. A lease qualifies for low-value exemption if:
- The asset's fair value when new is ≤ the entity's elected low-value-threshold (e.g., EUR 5,000)
- The entity's portfolio exemption policy elects to apply the exemption for the lease's asset class
- The lease term must also be ≤ 5 years (typically)

Once a lease is classified as `low-value-exempt`, all contractual payments are expensed straight-line over the lease term (same treatment as short-term exempt).

#### Scenario: Low-value IT hardware lease qualifies for exemption

- **GIVEN** a lease-contract:
  - asset-class = IT-hardware
  - description = "Laptop lease, 36-month term"
  - asset-fair-value-when-new = 2,000 EUR
  - entity's policy: low-value-threshold = 5,000, low-value-by-class.IT-hardware = yes
- **WHEN** the lease is classified
- **THEN** the classification = `low-value-exempt` (fair value 2,000 < threshold 5,000)
- **AND** the total expense = (base-payment × months), posted monthly as straight-line
- **AND** no RoU asset or liability is recognized

### Requirement: REQ-LE-004 — The system SHALL distinguish short-term and low-value exempts expensed on a straight-line basis

The system SHALL post short-term and low-value exempt leases as straight-line expenses and disclose them in separate disclosure-table buckets. Both short-term and low-value exempt leases are expensed straight-line, but the IFRS 16 disclosure tables distinguish them (IFRS 16.53(d) and 16.53(e)):

- `short-term-lease-expense` — total P&L impact from all short-term exempt leases (line item in expense category)
- `low-value-lease-expense` — total P&L impact from all low-value exempt leases (separate line item)

This allows auditors and users to understand the mix of exempted vs. capitalized leases.

#### Scenario: Disclosure table separates exemption expenses

- **GIVEN** an entity with:
  - 10 short-term vehicle leases (total 50,000 annual expense)
  - 20 low-value IT leases (total 15,000 annual expense)
  - 5 capitalized real-estate leases (total 250,000 RoU depreciation)
- **WHEN** the disclosure table is generated at year-end
- **THEN** the table shows:
  - Total short-term-lease-expense = 50,000
  - Total low-value-lease-expense = 15,000
  - Total RoU depreciation (capitalized leases) = 250,000

### Requirement: REQ-LE-005 — Policy changes and overrides are auditable

Policy changes and per-lease overrides SHALL be recorded as immutable history so auditors can reconstruct the policy in force at every classification. If an exemption policy is changed (e.g., the low-value threshold is raised from EUR 5,000 to EUR 7,000), the change is recorded as a new `lease-portfolio-exemption` record with:
- policy-effective-date = new date
- superseded-by = self-FK to prior policy

All leases remain granularly auditable: a lease contract carries its classification-rationale (which policy version was applied at classification time) and the GL posting carries a reference to the exemption election in effect at the time.

If an individual lease is classified contrary to the portfolio policy (e.g., a vehicle is marked `IFRS16-capitalised` even though the policy is to exempt short-term vehicles), the override is flagged in the classification-rationale field with a business reason. Auditors can query all overrides in one report.

#### Scenario: Low-value threshold is increased mid-year

- **GIVEN** a policy from 2024-01-01: low-value-threshold = 5,000
- **WHEN** on 2024-07-01, the entity re-evaluates and decides to increase the threshold to 7,000 to capture more IT assets
- **THEN** a new `lease-portfolio-exemption` record is created:
  - policy-effective-date = 2024-07-01
  - low-value-threshold = 7,000
  - superseded-by = <id of prior policy>
- **AND** existing leases classified under the old policy (threshold 5,000) retain their classification; new leases from 2024-07-01 onward are classified using the new threshold
- **AND** the disclosure table for 2024 shows both old and new thresholds in a reconciliation note

### Requirement: REQ-LE-006 — Exempt lease GL postings SHALL use dedicated account subtypes

Exempt lease GL postings SHALL use the dedicated subtypes below. To allow disclosure-table aggregation without app-level logic, GL accounts used for exempt leases are flagged:

| Subtype | Purpose |
|---|---|
| `short-term-lease-expense` | Expense GL account for IFRS 16.5 short-term leases |
| `low-value-lease-expense` | Expense GL account for IFRS 16.6 low-value leases |

When a lease is classified as exempt and the first monthly posting is made, the system queries for GL accounts with the appropriate subtype. If no such account exists, an error is raised: "No GL account configured for short-term-lease-expense. Please create an account in the Chart of Accounts first."

#### Scenario: Operator forgets to create exemption expense account

- **GIVEN** a short-term-exempt lease classified, first payment due on 2024-02-15
- **WHEN** the period-end posting process runs and attempts to post:
  - Dr. Short-term-lease-expense 500
  - Cr. Bank 500
- **THEN** the system queries GL for accounts with subtype=`short-term-lease-expense`
- **AND** if no such account exists, the posting fails with an error: "GL account for short-term lease expense not found. Operator action required: create account in Chart of Accounts with `is-lease-account=true`, subtype=`short-term-lease-expense`."

### Requirement: REQ-LE-007 — Exempt leases DO NOT have fixed-asset records or payment schedules

Exempt leases MUST NOT have fixed-asset records or payment-schedule rows; reclassification away from an exemption SHALL produce a catch-up adjustment. Short-term and low-value exempt leases are expensed; they are not capitalized and do not have:
- A `fixed-asset` record (no depreciation schedule)
- A `lease-payment-schedule` table (expense is straight-line; no complex interest accrual)

If an exempt lease is later reclassified (e.g., a short-term lease is extended and the short-term exemption no longer applies), the system:
1. Creates a reassessment-event (event-type = classification-change)
2. Voids prior exempt expense postings (reverse journal entries)
3. Creates a fixed-asset and payment-schedule from the effective classification date
4. Posts opening RoU asset and liability (catch-up adjustment)

#### Scenario: Short-term lease is extended mid-term

- **GIVEN** a short-term-exempt vehicle lease (original 12-month term, classified short-term-exempt on 2024-01-15)
- **WHEN** on 2024-06-15 (month 5), the lessor and lessee agree to extend the term by 18 months (new total = 30 months)
- **THEN** a reassessment-event is created:
  - event-type = term-modification
  - old-contract: 12 months, short-term-exempt
  - new-contract: 30 months, now IFRS16-capitalised (no longer exempt)
- **AND** the system:
  - Reverses prior short-term-lease-expense postings (5 × 500 = 2,500)
  - Creates a fixed-asset record from 2024-06-15 with opening RoU asset = PV of remaining 25 months of payments
  - Creates a lease-payment-schedule from 2024-06-15 forward
  - Posts opening RoU asset and liability catch-up adjustment

---

## Verification

All REQ-LE requirements are testable via:
- Verification that classified leases follow the portfolio policy (or override exceptions are documented)
- GL posting review to confirm exempt leases use the correct account subtypes
- Reconciliation of exempt expense totals in the disclosure table

