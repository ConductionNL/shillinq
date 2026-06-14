# Specifications — BCF VAT Compensation Claims

**Status:** Proposed  
**Spec ID:** bookkeeping-bcf-vat-compensation  
**Tier:** T3 (Operations + NL Compliance Core)  
**App:** shillinq  
**Depends On:** bookkeeping-vat-btw-filing (T3), bookkeeping-bbv-compliance (T3)

## Feature: Submit BCF Declaration

**User story demand:** 1350 (450 tender mentions)

Dutch municipalities and public bodies must file BCF (Btw-compensatiefonds / VAT Compensation Fund) claims quarterly with Belastingdienst to recover non-recoverable VAT (~€3M/year per gemeente). This spec defines the complete lifecycle: claim preparation, submission, acceptance, settlement, and audit integration.

---

## Requirements

### REQ-BCF-001: BcfClaim Entity Definition

The system SHALL define a `BcfClaim` register with the following schema properties:

| Property | Type | Required | Description |
|---|---|---|---|
| `claimQuarter` | string (quarter ID) | Yes | Quarter identifier (e.g., `2026-Q1`, `2026-Q2`) matching T2 fiscal periods |
| `administrationId` | string (UUID) | Yes | FK to `Administration` (single administration per claim) |
| `totalCompensableAmount` | decimal (EUR) | No | Total compensable VAT (derived; read-only; auto-calculated from GL aggregation) |
| `breakdown` | object (array of line items) | No | Compensable-VAT breakdown by account (derived; read-only; auto-calculated) |
| `state` | enum | Yes | Claim lifecycle state: `draft`, `submitted`, `accepted`, `settled` |
| `submittedOn` | datetime | No | Timestamp when claim was submitted to Belastingdienst |
| `acceptedOn` | datetime | No | Timestamp when Belastingdienst accepted the claim |
| `settledOn` | datetime | No | Timestamp when Belastingdienst settled the payment |
| `attachmentUri` | string (URI) | No | Reference to uploaded supporting documents (e.g., PDFs, spreadsheets) |
| `notes` | string (text) | No | Operator notes (not transmitted to Belastingdienst) |

**Relations:**
- `administrationId` → `Administration` (many-to-one)
- `breakdown[].accountId` → `Account` (via BBV mapping) (many-to-many implicit)

---

### REQ-BCF-002: Compensable-VAT Aggregation

#### Scenario: Calculate Compensable VAT for a Quarter

**GIVEN** an administration with BBV account mappings where:
- RGS 3610 (personnel, public service) is mapped to `bcfCompensable: true`, `compensablePercentage: 100`
- RGS 3650 (personnel, commercial) is mapped to `bcfCompensable: false`, `compensablePercentage: 0`
- RGS 4100 (utilities, mixed-use facility) is mapped to `bcfCompensable: true`, `compensablePercentage: 50`

**AND** the general ledger contains postings for Q1 2026:
- Account 3610: €100,000 VAT posted (public service expense, 21% rate)
- Account 3650: €50,000 VAT posted (commercial sale, not compensable)
- Account 4100: €40,000 VAT posted (utilities, mixed-use, 50% compensable)

**WHEN** a `BcfClaim` is created for `claimQuarter: 2026-Q1`

**THEN** the claim's `totalCompensableAmount` SHALL equal:
```
(100,000 × 100%) + (0) + (40,000 × 50%) = 120,000 EUR
```

**AND** the claim's `breakdown` SHALL list:
```
[
  { accountNumber: "3610", amount: 100,000, compensablePercentage: 100 },
  { accountNumber: "4100", amount: 20,000, compensablePercentage: 50 }
]
```

**Implementation note:** Aggregation is computed via `x-openregister-aggregations` at save time. The query filters:
- `GLLine.periodId = BcfClaim.claimQuarter`
- `GLLine.account → BbvAccountMapping.bcfCompensable = true`
- Multiplies `GLLine.amount` by `BbvAccountMapping.compensablePercentage / 100`

---

### REQ-BCF-003: Claim Lifecycle State Machine

#### Scenario: Lifecycle Transitions with Guards

The system SHALL implement the following state transitions:

| From | To | Guard Condition | Side Effect | Trigger |
|---|---|---|---|---|
| `draft` | `submitted` | ✓ `totalCompensableAmount > 0` <br> ✓ `claimQuarter is closed` (period-lock in T2) <br> ✓ Approval workflow approved by `bcf-administrator` | Submit claim to Belastingdienst via DigiKoppeling (scheduled quarterly) | Operator clicks "Submit" after approval |
| `submitted` | `accepted` | None (external actor) | None | Belastingdienst reviews and accepts (typically 14-30 days) |
| `accepted` | `settled` | None (external actor) | None | Belastingdienst processes payment (typically 30-60 days post-acceptance) |
| `accepted` / `settled` | `draft` | Operator is administration owner | Revert to draft (rare: if Belastingdienst requests correction) | Operator reverts manually (out-of-band) |

**Lifecycle diagram:**
```
[draft] → (submit, approved) → [submitted] → (accept, external) → [accepted] → (settle, external) → [settled]
  ↑                                                                    ↓
  └────────────────── (revert, operator) ←───────────────────────────┘
```

**Guard enforcement:**
- `draft → submitted`: Fail if `totalCompensableAmount ≤ 0` (error: "Claim is empty")
- `draft → submitted`: Fail if `claimQuarter` is open (error: "Quarter is not closed; cannot submit mid-period")
- All transitions: Audit-trail records state change, timestamp, and actor (operator or webhook source)

---

### REQ-BCF-004: Field-Level Compensable Flagging on BbvAccountMapping

#### Scenario: Configure Compensable Percentage per Account

**GIVEN** an administration with BBV account mappings for all RGS accounts

**WHEN** the operator opens the account mapping editor and selects account "4100 (utilities)"

**THEN** the operator SHALL see:
- `bcfCompensable` checkbox (checked for utilities, unchecked for purely commercial)
- `compensablePercentage` slider (0-100, default 100)

**AND** the operator SHALL be able to:
- Check/uncheck `bcfCompensable` for any account
- Adjust `compensablePercentage` (0-100) for mixed-use accounts
- All changes are audit-trailed and reversible

**AND** existing `BcfClaim` records for past quarters SHALL NOT change when the mapping is edited (aggregation is computed at claim-save time and frozen)

---

### REQ-BCF-005: Quarterly DigiKoppeling Submission

#### Scenario: Automatic Quarterly Submission

**GIVEN** the system is configured with a `ScheduledWorkflow` for quarterly BCF submission (first business day of Q+1)

**AND** there are `BcfClaim` records in `submitted` state for the previous closed quarter

**WHEN** the scheduled workflow runs (cron)

**THEN** the system SHALL:
1. Find all `BcfClaim` records for the closed quarter in `submitted` state
2. For each claim, invoke OpenConnector's `digikoppeling-bcf` source with:
   - Claim data (administrationId, claimQuarter, totalCompensableAmount, breakdown, attachment)
   - Operator's digital signature (from approval workflow)
3. Upon successful response, log the submission timestamp in `BcfClaim.submittedOn`
4. On error (network, cert, or validation), log the error and retry on next scheduled run (exponential backoff)

**Implementation note:** Submission is handled by OpenConnector's `ScheduledWorkflow` + `digikoppeling-bcf` source. Shillinq declares the workflow in its repair step and sets the cron schedule. No HTTP client in shillinq.

---

### REQ-BCF-006: Approval Workflow Gate on Submission

#### Scenario: Claim Requires Approval Before Submission

**GIVEN** a `BcfClaim` in `draft` state with `totalCompensableAmount: €120,000`

**WHEN** the operator clicks "Submit claim"

**THEN** the system SHALL:
1. Create an approval workflow task assigned to role `bcf-administrator`
2. Task SHALL include: claim quarter, total amount, breakdown, operator name, timestamp
3. Display message: "Claim submitted for approval. Awaiting bcf-administrator review."

**AND** the `BcfClaim` state SHALL remain `draft` until approval task is completed

**WHEN** the bcf-administrator approves the task

**THEN** the system SHALL:
1. Transition claim to `submitted` state
2. Record approval timestamp, approver name, and approval comment in audit trail
3. Queue the claim for the next scheduled DigiKoppeling submission

**IF** the bcf-administrator rejects the task

**THEN** the system SHALL:
1. Keep claim in `draft` state
2. Notify operator of rejection reason
3. Allow operator to revise and re-submit

---

### REQ-BCF-007: Settlement Webhook Handling

#### Scenario: Automatic Settlement on Belastingdienst Confirmation

**GIVEN** a `BcfClaim` in `accepted` state for Q1 2026 for €120,000

**AND** Belastingdienst processes the payment and sends a settlement webhook to OpenConnector

**WHEN** the webhook is received (POST event)

**THEN** the system SHALL:
1. Receive CloudEvents-formatted event from OpenConnector:
   ```json
   {
     "type": "nl.conduction.bcf-claim-settled",
     "objectId": "bcf-claim-uuid",
     "data": {
       "state": "settled",
       "settledAmount": 120000,
       "settledDate": "2026-02-15"
     }
   }
   ```
2. OpenRegister's generic webhook handler SHALL route the event to `BcfClaim` schema
3. Automatically update `BcfClaim.state = settled` and `BcfClaim.settledOn = settledDate`
4. Record the state change in audit trail (source: webhook, timestamp, data snapshot)

**AND** if the webhook is lost (network failure):
- The claim remains in `accepted` state
- Operator can manually transition to `settled` via the detail page (fallback)
- Fallback transition requires authorization (same approval gate) and timestamp entry

---

### REQ-BCF-008: User Interface & Navigation

#### Scenario: Operator Accesses BCF Claims from Main Menu

**GIVEN** a user with role `bcf-operator` or `bcf-administrator` in a municipal administration

**WHEN** the user opens Shillinq and navigates the left sidebar

**THEN** the user SHALL see a menu item:
- **Label:** "Overheid > BCF-claims"
- **Icon:** (government badge + document)
- **Visibility:** Only for users in municipal administrations (visibility predicate in manifest)
- **Leads to:** BCF-claims index page (list view)

#### Scenario: Index Page — List All Claims

**GIVEN** the user opens the BCF-claims index page

**THEN** the page SHALL display:
- **Columns:** Quarter (sortable), Total Compensable Amount (sortable), State (filtered), Submitted Date, Settled Date
- **Filters:** State (draft/submitted/accepted/settled), Quarter (range), Administration (if multi-admin user)
- **Actions:** Create new claim, bulk export, view details
- **Pagination:** 20 per page

**AND** clicking a row SHALL navigate to the detail page for that claim

#### Scenario: Detail Page — View & Edit Claim

**GIVEN** the user opens a `BcfClaim` detail page

**IF** the claim is in `draft` state:
- **Editable fields:** Notes, attachment, compensable percentage overrides (per account)
- **Actions:** Save draft, Submit for approval, Delete
- **Display:** Breakdown table (account, GL balance, compensable VAT), total

**IF** the claim is in `submitted` state:
- **Fields:** Read-only (submission locked)
- **Display:** Submitted timestamp, submission status (queued/sent/accepted)
- **Actions:** View approval details (who approved, when)

**IF** the claim is in `accepted` or `settled` state:
- **Fields:** All read-only
- **Display:** Submission + settlement timestamps, settlement amount (from webhook)
- **Actions:** Export PDF, view audit trail

---

### REQ-BCF-009: Audit Trail & Compliance

#### Scenario: Full Audit Trail on Every State Change

**GIVEN** a `BcfClaim` is created, drafted, submitted, and settled over time

**WHEN** the user opens the audit trail tab in the claim detail page

**THEN** the system SHALL display all state changes in reverse chronological order:

| Timestamp | Actor | Action | Before | After | Details |
|---|---|---|---|---|---|
| 2026-02-15 10:30 | webhook (digikoppeling-bcf) | State change | accepted | settled | Settled amount: €120,000 |
| 2026-02-01 14:00 | bcf-administrator (Maria) | Approved submission | draft | submitted | Approval comment: "Verified against GL fixture" |
| 2026-01-31 09:15 | bcf-operator (Johan) | Created | — | draft | Quarter: 2026-Q1, Amount: €120,000 |

**AND** the audit trail SHALL NOT be editable or deletable (immutable per ADR-022)

**AND** export of audit trail SHALL include:
- All changes with timestamps, actors, before/after values
- Suitable for court / auditor review

---

### REQ-BCF-010: Data Model Preconditions

#### Scenario: Preconditions for Claim Creation

**GIVEN** a new `BcfClaim` is being drafted

**THEN** the system SHALL enforce:
- `administrationId` must reference an existing `Administration` with `businessType: gemeente` (municipality) or similar public body
- `claimQuarter` must be ≥ the system's install date (forward-only, no historical claims)
- `claimQuarter` must reference a closed fiscal period (T2 period-close must be complete for the quarter)

**IF** any precondition fails:
- Display user-facing error: "Cannot create claim: [reason]"
- Log the validation failure for audit

---

### REQ-BCF-011: RBAC — Role-Based Access Control

#### Scenario: Role-Based Permission Matrix

| Role | Can Create | Can Draft | Can Submit | Can Approve Submit | Can Settle | Can View | Can Export |
|---|---|---|---|---|---|---|---|
| `bcf-administrator` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `bcf-operator` | ✓ | ✓ | ✓ | ✗ | ✗ | ✓ | ✓ |
| `bcf-viewer` | ✗ | ✗ | ✗ | ✗ | ✗ | ✓ | ✓ |
| Administrator (global) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

**Implementation:** Declared via `PropertyRbacHandler` in schema metadata. Field-level rules:
- `state` field: visible to all roles, editable only by system (state machine) and approval workflow
- `compensablePercentage` field: editable by `bcf-administrator` and `bcf-operator` (draft only)
- `notes` field: editable by `bcf-operator` (draft only), visible by all roles

---

### REQ-BCF-012: Integration with Other T3 Specs

#### Scenario: Dependency on VAT Filing & BBV Compliance

**GIVEN** T3 `bookkeeping-vat-btw-filing` spec defines VAT rates on GL postings

**AND** T3 `bookkeeping-bbv-compliance` spec defines BBV account mappings with `bcfCompensable` flag

**WHEN** a `BcfClaim` is created for a quarter

**THEN** the system SHALL:
1. Query `GLLine` records filtered by: period ID + VAT rate (from VAT filing) + `BbvAccountMapping.bcfCompensable = true`
2. Aggregate using `BbvAccountMapping.compensablePercentage` weight
3. Populate `BcfClaim.breakdown` and `BcfClaim.totalCompensableAmount`

**AND** if either dependency is missing (VAT rates not tagged, BBV mappings incomplete):
- Display warning: "Cannot calculate compensable VAT: [reason]. Please ensure VAT filing and BBV compliance are configured for this administration."

---

## Test Scenarios

### Browser Test: End-to-End BCF Claim Lifecycle

**Using persona:** Municipal accountant (BCF operator)

```gherkin
Feature: BCF Claim Lifecycle
  Scenario: Create, draft, submit, and settle a quarterly claim
    Given I am logged in as a bcf-operator for gemeente Amsterdam
    And the GL contains Q1 2026 postings:
      | Account | Amount | Type |
      | 3610    | €100k  | Personnel, public |
      | 4100    | €40k   | Utilities, mixed-use |
    And BbvAccountMapping is configured:
      | Account | bcfCompensable | Percentage |
      | 3610    | true           | 100 |
      | 4100    | true           | 50 |

    When I navigate to "Overheid > BCF-claims"
    Then I see the BCF-claims list (empty)

    When I click "+ Create new claim"
    Then I see a form with:
      - Quarter selector (default: last closed quarter)
      - Breakdown table (calculating...) → shows €120k total

    When I enter notes "Verified against auditor fixture"
    And I click "Submit for approval"
    Then I see "Claim submitted for approval. Awaiting bcf-administrator review."
    And an approval task is created

    When I log out and log in as bcf-administrator
    And I open the approval task
    And I review the claim (amount €120k, breakdown €100k + €20k)
    And I click "Approve"
    Then the claim state changes to "submitted"
    And the timestamp "submittedOn" is set

    When the quarterly scheduler runs
    Then the claim is submitted to DigiKoppeling via OpenConnector
    And the submission status is logged

    When Belastingdienst sends a settlement webhook
    Then the claim state automatically changes to "settled"
    And the timestamp "settledOn" is set from the webhook

    When I view the audit trail
    Then I see all state changes: created → submitted → settled
    With timestamps, actors, and approval comment
```

### Unit Test Scenarios (to be implemented in opsx-apply cycle)

1. **Aggregation filter** — GL postings for different quarters do not cross-contaminate
2. **Compensable percentage weight** — 50% mixed-use account produces half the VAT
3. **Submit precondition** — Claim with €0 fails submit; claim with €1 succeeds
4. **Period lock** — Cannot submit claim for open quarter (period not closed)
5. **Webhook routing** — Settlement webhook updates `state` + `settledOn` atomically
6. **Approval workflow** — Submit action creates approval task; completion transitions state

---

## Acceptance Criteria

### Spec-Level AC

- ✓ All requirements link to test scenarios (browser or unit)
- ✓ No contradictions between REQ-BCF-* statements
- ✓ RBAC matrix covers all roles (administrator, operator, viewer, global admin)
- ✓ Error messages are user-facing (not stack traces)
- ✓ Audit trail is immutable (all changes logged)

### Implementation-Level AC (opsx-apply cycle)

- ✓ `BcfClaim` schema registered in `lib/Settings/shillinq_register.json`
- ✓ `BbvAccountMapping` extended with `bcfCompensable` + `compensablePercentage`
- ✓ Aggregation computes correctly on test fixtures (unit test)
- ✓ Lifecycle state machine enforces all transitions + guards (unit test)
- ✓ Approval workflow gate creates task on submit (integration test)
- ✓ Webhook event routes to claim and updates state (integration test)
- ✓ UI index/detail pages render without errors (browser test)
- ✓ RBAC roles enforce permission matrix (browser test with role switching)
- ✓ Audit trail logs all changes immutably (browser test)
- ✓ `composer test` passes (unit + integration tests)
- ✓ Playwright `npm test` passes (browser tests)
- ✓ Dutch + English translations for all user-visible strings

---

## Not in Scope

The following are explicitly NOT covered by this spec (may be added post-release):

1. **Email notifications** — When claim is submitted/approved/settled, send email to operator
2. **Historical claim recovery** — Claims prior to install date (manual import process out of band)
3. **Multi-quarter batch claims** — Filing multiple quarters in one submission (one quarter per claim)
4. **Custom rejection reasons** — Admin can add predefined rejection categories (workflow feature)
5. **Claim amendments** — Belastingdienst requests change to submitted claim (manual revert + redraft)

---

## Success Metrics

Upon implementation completion:

- ✓ A municipal accountant can create, draft, and submit a BCF claim in &lt;5 minutes
- ✓ Claim calculation accuracy: ≥99.99% (validated on €3M+ fixture)
- ✓ Approval workflow latency: &lt;2 seconds (approval task creation)
- ✓ Quarterly submission uptime: ≥99.9% (retry logic)
- ✓ Audit trail completeness: 100% of state changes logged (immutable)
- ✓ RBAC enforcement: 100% of permission checks pass (role-based tests)
