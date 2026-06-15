# Spec: contract-lifecycle-management

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (operations)
**Depends on:**
- `bookkeeping-document-attachment-integration` (link-don't-store document FK contract)
- `bookkeeping-cost-centers-dimensions` (spend dashboard dimension reuse)
- `shillinq-notifications` (x-openregister-notifications rule conventions; lands the contract renewal rule deferred there)
- `bookkeeping-purchase-order-3way`, `add-shillinq-accounts-payable-core`, `add-shillinq-accounts-receivable-core` (CHAINED: spend rollup sources)
- `bookkeeping-lease-contracts` (specialization link; no regression)

## ADDED Requirements

### Requirement: REQ-CLM-001 — The system SHALL store contracts as an OpenRegister-managed `Contract` schema with the counterparty referencing a Nextcloud addressbook contact

The `Contract` schema MUST be declared in the ADR-037 register fragment
`lib/Settings/register.d/contract-lifecycle-management.json` (NOT the
`shillinq_register.json` monolith) with `x-openregister-audit: true`. No
custom PHP model, no parallel table; CRUD goes through OpenRegister's
generic object surface (ADR-022).

| Property | Type | Required | Purpose |
|---|---|---|---|
| `contractNumber` | string | Yes | Sequential identifier per administration |
| `title` | string | Yes | Human-readable contract title |
| `description` | string | No | Plain-language summary |
| `contractType` | enum | Yes | purchase, sales, service, subscription, lease, employment, other |
| `direction` | enum | Yes | cost (we pay) or revenue (we are paid) — drives which spend leg applies |
| `counterpartyReference` | string (contact URI/UID) | Yes | NC addressbook contact; a counterparty is a Nextcloud entity — no Party/Customer schema is declared |
| `contractOwner` | string (uid) | Yes | Responsible user; notification recipient |
| `startDate` | date | Yes | Contract commencement |
| `endDate` | date | No | Scheduled end (null = indefinite) |
| `renewalTerms` | object | No | `{ renewalType: none\|manual\|auto-renew, renewalTermMonths, noticePeriodDays, priceIndexation }` — embedded, not a separate schema |
| `renewalDecisionDate` | date | Computed | `endDate − renewalTerms.noticePeriodDays`, declared via `x-openregister-calculations` |
| `totalContractValue` | decimal | No | Contracted value over the term |
| `currency` | string (ISO 4217) | Yes | Defaults to EUR |
| `costCenter` | FK | No | Cost center per `bookkeeping-cost-centers-dimensions` |
| `dimensions` | object | No | Additional dimension FKs (project, department) per the existing dimension model |
| `documents` | array | No | NC Files references (see REQ-CLM-005); link, don't store |
| `status` | enum | Yes | Lifecycle state (see REQ-CLM-002) |
| `terminationReason` | string | Conditional | Mandatory when status = terminated |
| `predecessorContract` | FK Contract | No | Renewal chain (self-FK) |
| `successorContract` | FK Contract | No | Renewal chain (self-FK) |
| `specializationReference` | FK | No | Link to a specialized register record, e.g. `lease-contract` (see REQ-CLM-007) |
| `tags` | array | No | Free-form repository facets |

#### Scenario: Operator lists contracts via the OpenRegister API

- **GIVEN** shillinq is installed with the contract-lifecycle-management fragment
- **WHEN** an authenticated operator with access lists objects of the `Contract` schema through OpenRegister's standard object API
- **THEN** the response MUST list contract records paginated per OR's standard list contract, with no shillinq-local CRUD endpoint involved

#### Scenario: Counterparty is a Nextcloud contact, not an invented schema

- **GIVEN** a contract for supplier "Acme B.V."
- **WHEN** the contract is saved
- **THEN** `counterpartyReference` MUST hold the NC addressbook contact reference, and the register fragment MUST NOT declare any Party / Customer / Supplier schema

#### Scenario: Renewal decision date is computed, not hand-maintained

- **GIVEN** a contract with `endDate = 2026-12-31` and `renewalTerms.noticePeriodDays = 90`
- **WHEN** the record is read
- **THEN** `renewalDecisionDate` MUST equal `2026-10-02` and MUST be declared as an `x-openregister-calculations` field (no PHP recomputation service)

### Requirement: REQ-CLM-002 — Contracts SHALL transition through a declarative lifecycle: draft → active → expiring → expired, with renewed and terminated exits

The lifecycle MUST be declared via `x-openregister-lifecycle` on the
`Contract` schema (ADR-031 — no `ContractStateService`):

- `draft` → `active`: requires `startDate`, `counterpartyReference`, `contractOwner` present.
- `active` → `expiring`: time-based, when today ≥ `renewalDecisionDate` (evaluated by OR's scheduled machinery).
- `expiring` → `expired`: time-based, when today > `endDate` and no renewal was executed.
- `active` / `expiring` → `terminated`: requires `terminationReason` (fail-closed guard; ADR-031 exception path only if not expressible declaratively).
- `expiring` / `expired` → `renewed`: executes the renewal action — a successor `Contract` is created in `draft` carrying the renewal terms forward, with `predecessorContract` / `successorContract` set on both records.

#### Scenario: Activation requires the mandatory fields

- **GIVEN** a `draft` contract missing `contractOwner`
- **WHEN** a transition to `active` is attempted
- **THEN** the transition MUST be rejected fail-closed with a field-level validation message

#### Scenario: Renewal creates a linked successor draft

- **GIVEN** an `expiring` contract with `renewalTerms.renewalTermMonths = 12`
- **WHEN** the renew transition is executed
- **THEN** the contract's status MUST become `renewed`, a successor contract MUST exist in `draft` with `startDate` = predecessor `endDate + 1 day` and a 12-month term, and the two records MUST reference each other via `predecessorContract` / `successorContract`

#### Scenario: Termination without a reason is rejected

- **GIVEN** an `active` contract
- **WHEN** a transition to `terminated` is attempted with empty `terminationReason`
- **THEN** the transition MUST be rejected and no state change MUST be persisted

### Requirement: REQ-CLM-003 — Obligations SHALL be stored as `ContractObligation` records and surfaced as Nextcloud Tasks / Deck cards via a thin, fail-closed bridge

The `ContractObligation` schema MUST be declared in the same ADR-037
fragment with `x-openregister-audit: true`:

| Property | Type | Required | Purpose |
|---|---|---|---|
| `contract` | FK Contract | Yes | Owning contract |
| `title` | string | Yes | Obligation title |
| `clauseReference` | string | No | Contract clause / article |
| `obligationType` | enum | Yes | deliverable, payment, compliance, review, notice |
| `dueDate` | date | Yes | Deadline |
| `recurrence` | enum | No | none, monthly, quarterly, annually |
| `responsible` | string (uid) | Yes | Defaults to the contract's `contractOwner` |
| `status` | enum | Yes | open, in-progress, done, waived, overdue |
| `evidence` | array | No | NC Files references (certificates, reports); link, don't store |
| `taskUri` | string | No | Link to the NC Tasks VTODO / Deck card created by the bridge |
| `taskLinkStatus` | enum | No | linked, failed, none |

Surfacing rules (per ADR-022 / "content types belong in leaves"):

- On obligation creation, `ObligationTaskBridge` MUST create one NC Tasks
  VTODO (or a Deck card where Deck is enabled and selected) with the title,
  due date, and assignee, and store the resulting `taskUri`.
- The register row is the source of truth for the deadline and compliance
  status; the NC task is a surface. Task completion MAY be read back to
  suggest a status update but MUST NOT silently change `status`.
- A bridge failure MUST set `taskLinkStatus = failed` and MUST NOT block
  obligation create/update (fail-closed glue, never domain logic).
- Shillinq MUST NOT declare its own task/todo schema or task list UI beyond
  the obligation views.

#### Scenario: Creating an obligation creates a linked Nextcloud task

- **GIVEN** a contract with an obligation "Provide annual insurance certificate", `dueDate = 2026-09-01`, `responsible = bob`
- **WHEN** the obligation is created
- **THEN** an NC Tasks VTODO MUST exist with that title, due date, and assignee, and the obligation's `taskUri` MUST reference it with `taskLinkStatus = linked`

#### Scenario: Bridge failure does not block obligation CRUD

- **GIVEN** the Tasks bridge is unavailable
- **WHEN** an obligation is created
- **THEN** the obligation record MUST be persisted with `taskLinkStatus = failed`, and the failure MUST be visible on the obligation row

#### Scenario: Deleting the Nextcloud task does not silence the deadline

- **GIVEN** an obligation whose linked task has been deleted in NC Tasks
- **WHEN** the obligation deadline notification rule is evaluated (REQ-CLM-004)
- **THEN** the deadline notification MUST still be delivered, because the rule reads the register row's `dueDate` and `status`, not the task

### Requirement: REQ-CLM-004 — Renewal and obligation deadlines SHALL be notified through the x-openregister-notifications dialect — never imperative dispatch

Per ADR-031 and the `shillinq-notifications` conventions, the fragment MUST
declare `x-openregister-notifications` rules consumed by the OpenRegister
notification engine. Shillinq MUST NOT author app-local notification
service code, event listeners that dispatch notifications, or background
jobs for reminders. All subjects MUST be provided in both `nl` and `en` and
MUST be metadata-only (contract number, title, dates — no contract economic
terms in the subject). This lands the contract renewal rule that
`shillinq-notifications` explicitly deferred until a Contract schema exists.

Rules:

1. **Renewal decision window** — `scheduled` trigger (intervalSec ≥ 86400)
   on `Contract`, filtering `status` in `{active, expiring}` and
   `renewalDecisionDate` within the next 30 days or past; recipients
   `{"kind":"field","field":"contractOwner"}` plus
   `{"kind":"object-acl","permission":"manage"}`.
2. **Contract expired without renewal** — `scheduled` trigger on `Contract`,
   filtering `status = expiring` and `endDate` in the past; recipients as
   rule 1 plus the `shillinq-finance` group.
3. **Obligation deadline** — `scheduled` trigger on `ContractObligation`,
   filtering `status` in `{open, in-progress}` and `dueDate` within the
   next 14 days or past; recipients `{"kind":"field","field":"responsible"}`.
4. **Contract terminated** — `updated` trigger on `Contract` with
   `condition` `{"field":"status","operator":"equals","value":"terminated"}`;
   recipients `{"kind":"field","field":"contractOwner"}` plus the
   `shillinq-finance` group.
5. **Obligation overdue** — `updated` trigger on `ContractObligation` with
   `condition` `{"field":"status","operator":"equals","value":"overdue"}`;
   recipients `{"kind":"field","field":"responsible"}` plus
   `{"kind":"object-acl","permission":"manage"}`.

#### Scenario: Contract owner is notified inside the renewal decision window

- **GIVEN** an `active` contract with `renewalDecisionDate` 10 days from now and `contractOwner = alice`
- **WHEN** the scheduled rule is evaluated by the OR notification engine
- **THEN** alice MUST receive the renewal-decision notification, with the subject available in both `nl` and `en` and containing only metadata (contract number, title, decision date)

#### Scenario: Obligation deadline notification fires from the register row

- **GIVEN** an open obligation with `dueDate` 7 days from now and `responsible = bob`
- **WHEN** the scheduled obligation rule is evaluated
- **THEN** bob MUST receive the deadline notification, regardless of the state of the linked NC task

#### Scenario: No imperative dispatch code exists

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for app-local notification dispatch (`INotificationManager` dispatch in services/listeners, reminder background jobs, legacy notification dialect in `lib/Settings/*register*.json`)
- **THEN** no such code or legacy dialect MUST exist; all rules live in the `x-openregister-notifications` declarations (gate-18)

### Requirement: REQ-CLM-005 — Contract documents SHALL live in Nextcloud Files and be referenced from the register (link, don't store)

Per ADR-022 and the `bookkeeping-document-attachment-integration` contract:

- `Contract.documents` and `ContractObligation.evidence` MUST hold NC Files
  references (file id + display path); the signed PDF, amendments, and
  evidence files are stored, versioned, and shared by Nextcloud Files.
- Shillinq MUST NOT introduce file-storage code, upload endpoints, blob
  columns, or a parallel attachment register.
- Document **content** full-text search is delegated to Nextcloud full-text
  search over the linked files; the contract repository search (REQ-CLM-008)
  covers register metadata via OR `_search`. No app-local indexer.

#### Scenario: Attaching a contract document links an existing NC Files file

- **GIVEN** a signed contract PDF stored in Nextcloud Files
- **WHEN** the user attaches it to a contract
- **THEN** the contract's `documents` array MUST gain a reference to that file, the file MUST remain in NC Files (bytes are never copied into the register), and opening the attachment MUST route to the NC Files viewer

#### Scenario: Reviewer confirms no file storage in shillinq

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for upload endpoints, blob columns, or an attachment register introduced by this change
- **THEN** none MUST exist; only file references are stored

### Requirement: REQ-CLM-006 — The spend dashboard SHALL report committed vs invoiced vs contracted value per contract through declarative aggregations over existing bookkeeping registers (CHAINED)

Spend figures MUST be declared as `x-openregister-aggregations` — no PHP
report service (ADR-031):

- `committedAmount` — sum of `PurchaseOrder` totals where
  `contractReference` = the contract (source: `bookkeeping-purchase-order-3way`).
- `invoicedAmount` — for `direction = cost`: sum of `APInvoice` totals with
  `contractReference` = the contract; for `direction = revenue`: sum of
  `ARInvoice` totals (sources: AP/AR core changes).
- Both MUST be sliceable by `costCenter` / dimension, reusing
  `bookkeeping-cost-centers-dimensions` — no new dimension model.
- The dashboard MUST surface over-commitment (`committedAmount >
  totalContractValue`) and over-invoicing (`invoicedAmount >
  committedAmount`) as visual flags.

**CHAINED**: the `contractReference` FK additions and these aggregation
rules MUST NOT be attached before the owning changes
(`bookkeeping-purchase-order-3way`, `add-shillinq-accounts-payable-core`,
`add-shillinq-accounts-receivable-core`) have landed their schemas — no
schema is invented here. Until then the spend dashboard MUST render an
honest empty state ("no linked spend data"), never stub figures.

#### Scenario: Committed vs invoiced is aggregated per contract

- **GIVEN** the PO and AP schemas exist, with two purchase orders (EUR 40,000 + EUR 20,000) and one AP invoice (EUR 35,000) referencing contract C-2026-007 with `totalContractValue = 100,000`
- **WHEN** the contract's spend rollup is read
- **THEN** `committedAmount` MUST be 60,000 and `invoicedAmount` MUST be 35,000, produced by the declared aggregations (no PHP report service in the call path)

#### Scenario: Spend rollup is honestly empty before the chained schemas land

- **GIVEN** the `PurchaseOrder` / `APInvoice` schemas are not yet present in the register
- **WHEN** the spend dashboard is rendered
- **THEN** it MUST show an explicit "no linked spend data" state, and no aggregation rule targeting a non-existent schema MUST be declared

#### Scenario: Over-commitment is flagged

- **GIVEN** a contract with `totalContractValue = 50,000` and `committedAmount = 62,000`
- **WHEN** the contract detail spend panel is rendered
- **THEN** an over-commitment flag MUST be shown with the delta (12,000)

### Requirement: REQ-CLM-007 — Lease contracts MUST become a specialization by link so the lease-contract register stays canonical with zero regression

- A generic `Contract` of `contractType = lease` MAY carry
  `specializationReference` → a `bookkeeping-lease-contracts`
  `lease-contract` record, so leases appear in the unified repository.
- The generic record MUST NOT duplicate any lease field (payment terms, IBR,
  classification, RoU figures); the contract detail page renders a lease
  panel that deep-links to the lease record.
- The `bookkeeping-lease-contracts` schema, lifecycle, and IFRS 16 pipeline
  MUST remain unchanged by this change; all existing lease specs and tests
  MUST keep passing (no regression).
- Backfilling generic wrappers for existing lease records is OPTIONAL and
  idempotent; an unwrapped lease keeps working exactly as today.

#### Scenario: A lease appears in the unified contract repository via the link

- **GIVEN** an existing `lease-contract` record and a generic `Contract` with `contractType = lease` and `specializationReference` pointing at it
- **WHEN** the contract repository is listed
- **THEN** the lease appears as a contract row, and its detail page MUST deep-link to the lease record rather than re-rendering lease accounting fields

#### Scenario: Lease suite is untouched

- **GIVEN** the shillinq codebase after this change
- **WHEN** the `bookkeeping-lease-contracts` schema declaration and its tests are inspected
- **THEN** they MUST be byte-identical to before this change, and an unwrapped `lease-contract` record MUST remain fully functional

### Requirement: REQ-CLM-008 — The contract repository UI SHALL provide search, filtering, and the lifecycle views as ADR-037 manifest pages

The frontend MUST ship as the ADR-037 manifest fragment
`src/manifest.d/contract-lifecycle-management.json` (no bespoke router
work): a "Contracts" navigation group with

- **Repository index** — searchable (OR `_search` over contractNumber,
  title, tags), filterable by `contractType`, `status`, `contractOwner`,
  counterparty, and cost center; sortable by `endDate` /
  `renewalDecisionDate`; an "expiring soon" default smart filter.
- **Contract detail** — metadata, lifecycle actions (per REQ-CLM-002),
  documents (REQ-CLM-005), obligations list (REQ-CLM-003), spend panel
  (REQ-CLM-006), renewal chain navigation, lease panel where specialized.
- **Obligations overview** — cross-contract deadline list grouped by due
  window (overdue / 14 days / 30 days), filterable by responsible.
- **Spend dashboard** — committed vs invoiced vs contracted value across
  contracts, sliceable by cost center / dimension and contract type.

Modals/dialogs MUST live in their own files under `src/modals/` /
`src/dialogs/`; `NcSelect` usages MUST carry `inputLabel` (ADR-004 gates).

#### Scenario: Repository search finds a contract by title

- **GIVEN** a contract titled "Office cleaning services 2026"
- **WHEN** the user types "cleaning" in the repository search box
- **THEN** the contract MUST appear in the filtered list via OR `_search` (no app-local search endpoint)

#### Scenario: Expiring-soon filter surfaces contracts in the decision window

- **GIVEN** one contract with `renewalDecisionDate` in 10 days and one with `renewalDecisionDate` in 200 days
- **WHEN** the "expiring soon" filter is applied
- **THEN** only the first contract MUST be listed

### Requirement: REQ-CLM-009 — All new UI strings SHALL use ENGLISH source keys with `nl` and `en` catalogs

Per the fleet i18n convention, every new translatable string MUST use the
English source string as the i18n key (e.g.
`t('shillinq', 'Renewal decision due')`, never a Dutch key), with the Dutch
translation added to `l10n/nl.json` in the same change. Notification
subjects (REQ-CLM-004) MUST declare both `nl` and `en`. Manifest navigation
and page labels MUST be translated through the same catalogs.

#### Scenario: Dutch UI renders translated strings from English keys

- **GIVEN** a user with locale `nl`
- **WHEN** the contract repository is rendered
- **THEN** labels MUST appear in Dutch (e.g. 'Verlengingsbeslissing vereist'), resolved from English source keys present in `l10n/en.json` and `l10n/nl.json`

#### Scenario: No Dutch source keys are introduced

- **GIVEN** the diff of this change
- **WHEN** scanned for `t('shillinq', '…')` calls with Dutch source strings
- **THEN** none MUST exist
