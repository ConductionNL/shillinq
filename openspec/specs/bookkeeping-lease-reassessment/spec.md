---
status: done
---

# Spec: bookkeeping-lease-reassessment

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (advanced / specialized lease accounting)
**Depends on:** bookkeeping-lease-contracts, bookkeeping-lease-accounting

## Purpose

Over the life of a lease, modifications and remeasurement events occur: a lessee extends the term, the IBR changes, the lessor adjusts payment terms, or indexation clauses trigger a recalculation. Each event is captured in a `lease-reassessment-event` record with before/after snapshots, supporting evidence, and GL postings.

## Requirements

@e2e exclude pure backend/compliance: lease reassessment — not browser-testable


### REQ-LR-001: A reassessment event records every modification and remeasurement

The system SHALL satisfy this requirement: A reassessment event records every modification and remeasurement.

The `lease-reassessment-event` schema captures:

| Field | Purpose |
|---|---|
| `reassessment-number` | Sequential number per lease (e.g., VH-2024-001-reassess-001) |
| `event-date` | Date the reassessment is recorded (may be prior to the business trigger) |
| `event-type` | enum: indexation-remeasurement, extension-option-reassessment, termination-option-reassessment, payment-modification, scope-modification, term-modification, IBR-reset, impairment, abandonment, partial-termination, full-termination |
| `trigger-description` | Free-text explaining the business reason (e.g., "CPI increased 2.1% YoY, triggers indexation") |
| `old-contract-snapshot` | JSON blob of the `lease-contract` fields as of event-date-1 (immutable record) |
| `new-contract-snapshot` | JSON blob of the `lease-contract` fields post-event |
| `remeasurement-approach` | enum: catch-up-adjustment (liability/asset adjusted at event date), prospective (future schedule regenerated), separate-lease (if scope modification splits one lease into two) |
| `revised-ibr-percent` | If IBR-reset event, the new IBR with rationale |
| `pre-event-lease-liability` | Liability balance on event-date-1 |
| `post-event-lease-liability` | Liability balance post-event |
| `rou-asset-adjustment` | Change in RoU asset (δ) |
| `pl-impact` | Gain/loss on modification (posted to GL account subtype=`lease-modification-gain-loss`) |
| `supporting-documents` | Array of FK to docudesk documents (e.g., board decision, customer notification, new contract terms) |
| `approver` | FK to organisations.person-id (who signed off the reassessment) |
| `approval-date` | Timestamp of approval |
| `posted-to-gl` | FK to the GL transaction that applied this reassessment; immutable once posted |

#### Scenario: Indexation event creates a reassessment record

- **GIVEN** a lease-contract with indexation-clause = "CPI", indexation-reset-frequency = "annual"
- **WHEN** the annual indexation trigger fires on 2025-01-15 and the CPI index shows a 2.1% increase
- **THEN** a lease-reassessment-event is created:
  - event-type = indexation-remeasurement
  - old-contract-snapshot carries base-payment-amount = 1,000
  - new-contract-snapshot carries base-payment-amount = 1,021 (1,000 × 1.021)
  - post-event-lease-liability is recomputed with the new payment stream

### REQ-LR-002: Extension-option and termination-option reassessments are manual workflows

The system SHALL satisfy this requirement: Extension-option and termination-option reassessments are manual workflows.

When a customer assesses whether an extension option is now "reasonably certain" to be exercised (or a termination option is now "reasonably certain" to be exercised), the operator:

1. Navigates to the lease and selects "Reassess Extension Options"
2. The system displays all current extension-options with their exercise-likelihood (possible, reasonably-certain, unlikely)
3. The operator updates likelihoods and provides a business reason (e.g., "Board decision: will renew for 2 additional years to align with strategic plan")
4. The system creates a `lease-reassessment-event` with:
   - event-type = extension-option-reassessment
   - old-contract-snapshot with original likelihoods
   - new-contract-snapshot with updated likelihoods
   - trigger-description from the operator

The payment schedule is regenerated from the event date forward to include the newly-certain extension periods.

#### Scenario: Operator reassesses extension option

- **GIVEN** a lease-contract with a 2-year extension marked "possible"
- **WHEN** the operator reassesses the extension on 2025-06-01 and changes exercise-likelihood to "reasonably-certain"
- **THEN** a lease-reassessment-event is created
- **AND** the payment schedule is regenerated to include 24 additional months (the extended term)
- **AND** a new GL posting is queued to adjust the lease liability (from the 2-year extension's PV)

### REQ-LR-003: Payment and scope modifications follow the IFRS 16.44 decision tree

The system SHALL satisfy this requirement: Payment and scope modifications follow the IFRS 16.44 decision tree.

If a lease is modified (e.g., a new floor is added to a building lease, or payment terms are renegotiated), the reassessment workflow applies the IFRS 16.44-46 decision tree:

1. **Is this a separate lease?** (IFRS 16.44: does the customer now lease an additional identified asset, or does the modification affect only the original asset?)
   - If yes → separate-lease (creates a new lease-contract record)
   - If no → continue to step 2

2. **Remeasurement approach**: Update the lease liability using the new IBR at the modification date (unless the modification is clearly non-substantive, in which case prospective approach is allowed)

3. **GL posting**: 
   - Adjust lease-liability (Dr. liability reduction or Cr. liability increase)
   - Adjust RoU-asset (Cr. asset reduction or Dr. asset increase)
   - Recognize P&L gain/loss on modification if applicable

#### Scenario: Building lease is extended with additional floor

- **GIVEN** a lease-contract for a 5-floor building, non-cancellable term 60 months, opening liability 1,000,000
- **WHEN** on month 24, the customer negotiates an addition: one extra floor for the final 36 months, additional payment = 50,000 per month
- **THEN** the operator initiates a scope-modification reassessment:
  - Is this a separate lease? Answer: No (same building, same lessor, continuous term)
  - Remeasurement approach: catch-up-adjustment (new IBR at modification date applies to the new scope)
  - Old contract: 5 floors, original payments
  - New contract: 6 floors, original payments + 50,000 additional per month
- **AND** the system:
  - Recomputes lease liability for the new scope (additional floor's PV = 50,000 × (N periods) discounted at new IBR)
  - Posts a GL entry:
    - Dr. RoU asset (additional floor value)
    - Cr. Lease liability (increase)

### REQ-LR-004: Impairment and abandonment are recorded as separate reassessment events

The system SHALL satisfy this requirement: Impairment and abandonment are recorded as separate reassessment events.

If an RoU asset is impaired (e.g., the building is no longer usable due to damage) or abandoned (e.g., the asset is removed from service but the lease is not terminated), a reassessment-event is created:

- **Impairment**: RoU asset is written down to recoverable value; loss is recognized in P&L
- **Abandonment**: RoU asset remains on the books, but is no longer used; depreciation continues; the liability also continues

#### Scenario: Leased vehicle is damaged and abandoned

- **GIVEN** a vehicle lease with RoU asset = 15,000, lease liability = 12,000, 18 months remaining
- **WHEN** the vehicle is damaged beyond repair on month 6 and is abandoned (lessor allows early return with EUR 3,000 penalty)
- **THEN** a reassessment-event is created:
  - event-type = abandonment
  - rou-asset-adjustment = −15,000 (write-off)
  - pl-impact = 15,000 (loss on abandonment) + 3,000 (termination penalty) = 18,000 loss
  - GL posting:
    - Dr. Loss on lease abandonment 18,000
    - Cr. RoU asset 15,000
    - Cr. Bank 3,000 (penalty paid)

### REQ-LR-005: Partial terminations adjust liability and RoU pro-rata

The system SHALL satisfy this requirement: Partial terminations adjust liability and RoU pro-rata.

If a lessee returns part of a lease (e.g., two floors of a five-floor building), the liability and RoU are adjusted pro-rata:

- **Original lease liability** (before termination) = 500,000
- **Floors returned** = 2 of 5 = 40%
- **Liability reduction** = 500,000 × 40% = 200,000

The gain or loss on the partial termination is recognized based on the difference between the liability reduction and the RoU asset reduction.

#### Scenario: Lessee returns two floors

- **GIVEN** a 5-floor lease with RoU = 600,000, liability = 500,000, 36 months remaining
- **WHEN** the lessee returns 2 floors on month 12
- **THEN** the reassessment-event records:
  - event-type = partial-termination
  - old-contract-snapshot: 5 floors, payments = 100,000/month
  - new-contract-snapshot: 3 floors, payments = 60,000/month
  - liability-reduction = 500,000 × (2/5) = 200,000
  - rou-asset-reduction = 600,000 × (2/5) = 240,000
  - pl-impact = 240,000 − 200,000 = 40,000 gain (recognized in P&L)

### REQ-LR-006: Reassessment events MUST be immutable once GL-posted

The system SHALL satisfy this requirement: Reassessment events MUST be immutable once GL-posted.

Once a `lease-reassessment-event` is marked `posted-to-gl` (FK to a GL transaction), no further edits are allowed. If a correction is needed, a new reassessment-event is created (an "adjustment reassessment").

Auditors can walk the full event history of a lease from commencement to period-end and confirm every event was recorded and GL-posted in sequence.

#### Scenario: Auditor reviews event history

- **GIVEN** a lease with 5 reassessment-events (e.g., indexation, extension-option, modification, impairment, recovery)
- **WHEN** the auditor queries all events for this lease
- **THEN** the auditor sees a chronological list of all events with timestamps, posted-to-gl FKs, and before/after snapshots
- **AND** each event is immutable (no edit button visible; only "create correction event" option)

### REQ-LR-007: Reassessment approval routing via decidesk for material events

The system SHALL satisfy this requirement: Reassessment approval routing via decidesk for material events.

If the RoU asset impact of a reassessment exceeds a threshold (e.g., EUR 100,000), the event routes through a `decidesk` board-decision workflow:

1. Operator creates the reassessment-event (status = `pending-approval`)
2. A webhook fires to decidesk, creating a board-decision for the lease manager and CFO to review
3. The decision links back to the shillinq reassessment-event via a FK
4. Once approved in decidesk, the reassessment-event is marked `approved` and GL posting is allowed
5. If rejected, the reassessment-event remains `pending` and can be updated before resubmission

Events below the threshold are auto-approved (or require approval from the lease administrator only, depending on tenant configuration).

#### Scenario: Material reassessment routes through decidesk

- **GIVEN** a lease-contract with RoU = 500,000
- **WHEN** an extension-option-reassessment is created with rou-impact = 150,000 (> threshold of EUR 100,000)
- **THEN** the reassessment-event is created with status = `pending-approval`
- **AND** a webhook to decidesk creates a board-decision titled "IFRS 16 Lease Reassessment – Extension: [Lease Number]"
- **AND** the CFO and lease manager receive a notification to review and vote
- **AND** once approved, the reassessment-event is marked `approved` and GL posting proceeds

---

## Verification

All REQ-LR requirements are testable via:
- Manual walk-through of each event-type with sample contracts and data
- Comparison of before/after snapshots to contract source documents
- Reconciliation of GL postings against manual IFRS 16 calculations
- Audit of the event history for a lease over its lifetime

