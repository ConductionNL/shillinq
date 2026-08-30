---
status: done
---

# Spec: bookkeeping-lease-contracts

**Status:** proposed
**Scope:** shillinq
**Tier:** T4-specialized (advanced / specialized lease accounting)
**Depends on:** bookkeeping-general-ledger (T1)

## Purpose

The lease-contract register is the master record for every lease under IFRS 16 that a shillinq customer owns. It captures the contractual terms (lessor, commencement date, payment terms, extension/termination options, IBR, classification), integrates with docudesk for source contract PDFs, and routes complex contracts through an optional classification wizard.

## Requirements

@e2e exclude unbuilt UI: lease register detail via OR API, page not yet implemented


### REQ-LC-001: The system SHALL store lease contracts as an OpenRegister-managed `lease-contract` register

Lease contracts MUST be declared as a register in `lib/Settings/shillinq_register.json` per ADR-024, with the `lease-contract` schema as the canonical entity. No custom PHP model, no parallel table. The register is exposed through OpenRegister's generic CRUD HTTP surface; shillinq adds no per-app endpoint.

#### Scenario: Operator views the lease register via the OpenRegister API

- **GIVEN** shillinq is installed
- **WHEN** an authenticated operator calls `GET /index.php/apps/openregister/api/objects/shillinq/lease-contract`
- **THEN** the response MUST list all lease-contract records, paginated per OR's standard list contract

### REQ-LC-002: The `lease-contract` schema SHALL declare core lease attributes

The schema MUST declare:

| Field | Type | Required | Purpose |
|---|---|---|---|
| `lease-number` | string | Yes | Sequential identifier per organisation |
| `counterparty` | FK | Yes | Lessor organisation (FK organisations.organisation-id) |
| `description` | string | Yes | Lease description (e.g., "Mercedes E-class, 3yr + 2yr extension") |
| `asset-class` | enum | Yes | vehicle \| real-estate \| IT-hardware \| machinery \| other |
| `commencement-date` | date | Yes | Lease start date |
| `end-date` | date | Yes | Scheduled non-cancellable term end date |
| `non-cancellable-term-months` | integer | Yes | Non-cancellable term in months (computed from commencement/end) |
| `extension-options` | array | No | Array of { months, exercise-likelihood (reasonably-certain \| possible \| unlikely), exercise-by-date } |
| `payment-frequency` | enum | Yes | monthly \| quarterly \| annual \| irregular |
| `payment-timing` | enum | Yes | in-advance \| in-arrears |
| `base-payment-amount` | decimal | Yes | Contractual payment amount per period (before indexation) |
| `payment-currency` | string (ISO 4217) | Yes | Payment currency (e.g., EUR) |
| `indexation-clause` | enum | No | none \| fixed-percent \| CPI \| sector-index |
| `indexation-rate-or-source` | string | No | If fixed-percent: the % per year; if CPI/sector-index: the reference (e.g., "Dutch CPI") |
| `ibr-percent` | decimal | Yes | Incremental borrowing rate at commencement (%) |
| `ibr-derivation-method` | enum | Yes | group-policy \| yield-curve \| weighted-average \| external-quote |
| `ibr-source-document` | FK | No | Reference to docudesk document (source curve, policy matrix, external quote) |
| `classification` | enum | Yes | IFRS16-capitalised \| short-term-exempt \| low-value-exempt \| operating-pre-IFRS16 |
| `status` | enum | Yes | draft \| active \| modified \| terminated \| expired |

#### Scenario: Schema validator accepts a minimal lease contract

- **GIVEN** the `lease-contract` schema is loaded
- **WHEN** a new lease-contract object is validated with only required fields (lease-number, counterparty, description, asset-class, commencement-date, end-date, non-cancellable-term-months, payment-frequency, payment-timing, base-payment-amount, payment-currency, ibr-percent, ibr-derivation-method, classification, status)
- **THEN** validation MUST pass

### REQ-LC-003: Lease contracts SHALL support a classification wizard workflow

The system SHALL provide a classification wizard workflow for transitioning a lease from draft to active.

A new lease transitions `draft → active` via an optional wizard that walks the operator through the IFRS 16 decision tree:

1. **Is this a lease?** (IFRS 16.9: does the contract convey the right to control the use of an identified asset for a period in exchange for consideration?)
   - If no → classify as `operating-pre-IFRS16` (not a lease under IFRS 16)
   - If yes → continue

2. **Short-term exemption?** (IFRS 16.5: is the non-cancellable term ≤ 12 months?)
   - If yes → classify as `short-term-exempt`, post straight-line expense
   - If no → continue

3. **Low-value exemption?** (IFRS 16.6: is the asset's fair value when new ≤ tenant's elected threshold, typically ~USD 5,000?)
   - If yes → classify as `low-value-exempt`, post straight-line expense
   - If no → classify as `IFRS16-capitalised`, continue to RoU/liability recognition

The classification rationale (checklist + free-text) is stored in the `classification-rationale` field for audit trail.

#### Scenario: Wizard guides operator through short-term classification

- **GIVEN** a new lease-contract with non-cancellable-term-months = 12
- **WHEN** the operator transitions the lease from `draft` to `active` and elects the wizard
- **THEN** the wizard MUST display a screen: "Non-cancellable term is 12 months. Does IFRS 16.5 short-term exemption apply?" with options Yes / No / Unsure
- **AND** if Yes is selected, the lease is classified `short-term-exempt` and the operator is informed it will post as monthly expense, not capitalised asset

### REQ-LC-004: The lease-contract status machine SHALL be declarative

The schema MUST declare an `x-openregister-lifecycle` block with states and transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `active` | operator action (wizard completion or direct approval) | classification must be one of the four enum values |
| `active` | `modified` | operator action (reassessment-event creates a new contract snapshot) | none |
| `active` → `terminated` | operator action | no guard |
| `active` → `expired` | system trigger (current date > end-date of final extension) | automatic |

Once a lease is `active`, the `lease-payment-schedule` is generated (one row per payment period). If a lease transitions to `modified`, the payment schedule for future periods is regenerated from the modification date forward.

#### Scenario: Draft lease transitions to active on wizard completion

- **GIVEN** a lease-contract in `draft` status with classification = null
- **WHEN** the operator completes the classification wizard, setting classification = `IFRS16-capitalised`
- **THEN** the lifecycle transition `draft → active` is allowed; the lease status changes to `active`
- **AND** a `lease-payment-schedule` is generated for the full term

### REQ-LC-005: Lease contracts MUST reference source documents via docudesk FKs

The `ibr-source-document` field MUST be a FK to a docudesk DigitalDocument. The source document is the supporting evidence for the IBR derivation (group IBR policy matrix, external bank quote, yield-curve snapshot, etc.). Auditors can download the source document from docudesk without leaving shillinq.

#### Scenario: Operator links an external IBR quote as source document

- **GIVEN** a lease-contract being created with `ibr-derivation-method = external-quote`
- **WHEN** the operator uploads a PDF quote from the bank to docudesk and links it via `ibr-source-document`
- **THEN** the FK is validated to confirm the docudesk document exists and is readable
- **AND** the document is marked with a tag "IFRS16-IBR-Quote" (or similar) in docudesk for future discovery

### REQ-LC-006: Extension and termination options MUST be traceable to reassessment events

The system MUST keep extension and termination options traceable to their reassessment events.

When a lease is reassessed (e.g., an extension option is marked "reasonably certain"), the system creates a `lease-reassessment-event` record with before/after snapshots. The before-snapshot captures the original extension option (e.g., { months: 24, exercise-likelihood: "possible" }) and the after-snapshot shows the updated option (e.g., { months: 24, exercise-likelihood: "reasonably-certain" }).

Auditors can walk from `lease-contract` → `lease-reassessment-event` (filtered by event-type = extension-option-reassessment) → before/after snapshots → GL postings (on the new schedule) to confirm the reassessment was compliant.

#### Scenario: Auditor traces extension-option reassessment from contract to GL

- **GIVEN** a lease-contract with a 2-year extension option marked "reasonably-certain" in the current period
- **WHEN** the auditor queries all `lease-reassessment-event` records for this lease with event-type = extension-option-reassessment
- **THEN** the auditor sees the old value (exercise-likelihood = "possible") and new value (= "reasonably-certain")
- **AND** the event links to GL postings that adjust the lease liability and RoU asset for the extended term

### REQ-LC-007: Lease contracts SHALL be immutable after activation, changes trigger reassessment events

The system SHALL make a lease contract immutable after activation, with changes triggering reassessment events.

Once a lease transitions to `active`, direct edits to the contract (e.g., changing `end-date` or `ibr-percent`) are NOT allowed. Instead, the operator initiates a reassessment workflow that:

1. Captures the old contract snapshot
2. Prompts for the change (new end-date, new IBR, etc.) and a business reason
3. Generates a new `lease-reassessment-event` record with before/after snapshots
4. Routes the event through approval (optional, or required if RoU impact > EUR 100K)
5. Updates the contract snapshot in `lease-contract`
6. Regenerates `lease-payment-schedule` from the modification date forward

#### Scenario: Operator attempts direct edit of active lease

- **GIVEN** a lease-contract in `active` status
- **WHEN** the operator tries to edit the `end-date` field directly via the OR CRUD interface
- **THEN** the lifecycle guard (x-openregister-lifecycle.requires) MUST reject the edit with a message: "Lease is active. To modify, use the Reassessment workflow (Menu > Lease Reassessment > New Event > Modification)."

### REQ-LC-008: The lease-contract register SHALL support full-text search and filtering

The system SHALL support full-text search and filtering on the lease-contract register.

Operators must be able to filter leases by:
- Asset class (vehicle, real-estate, IT-hardware, machinery, other)
- Classification (IFRS16-capitalised, short-term-exempt, low-value-exempt, operating-pre-IFRS16)
- Status (draft, active, modified, terminated, expired)
- Lessor (counterparty name)
- Lease number (exact or partial match)

Full-text search on `description`, `lease-number`, and `counterparty.name` MUST be supported.

#### Scenario: Operator filters leases by asset class

- **GIVEN** the lease register has 50 leases
- **WHEN** the operator filters by asset-class = "vehicle"
- **THEN** the list shows only vehicle leases (e.g., 12 leases)
- **AND** each row displays lease-number, description, commencement-date, IBR, and status

### REQ-LC-009: Deletion of a lease contract SHALL cascade delete child records

When a lease-contract is deleted (only allowed in `draft` status), all related records MUST be deleted via OR's cascade-delete:
- All `lease-payment-schedule` rows for this lease
- All `lease-reassessment-event` rows for this lease
- All GL postings linked to this lease (via FK in journal-entry)
- The associated `fixed-asset` record (if created)

#### Scenario: Operator deletes a draft lease

- **GIVEN** a lease-contract in `draft` status with no payment schedule or reassessments yet
- **WHEN** the operator deletes the lease-contract record
- **THEN** the delete operation succeeds; all child records are cascade-deleted
- **AND** no GL postings or fixed-asset records remain

