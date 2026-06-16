# Spec: bookkeeping-journal-entries

**Status:** proposed
**Scope:** shillinq
**Tier:** T1 (foundation)
**Depends on:** bookkeeping-general-ledger (which depends on bookkeeping-chart-of-accounts)

## ADDED Requirements

### Requirement: REQ-JE-001 — The system SHALL store journal entries as a `JournalEntry` register, distinct from the GL transactions they materialise

`JournalEntry` MUST be declared as a separate register in
`lib/Settings/shillinq_register.json`. A `JournalEntry` is the
human-authored construct (the *memoriaalboeking* / recurring template
/ reversing accrual); posting one materialises exactly one
`GLTransaction` (with its `GLLine` children) per the lifecycle
declared in REQ-JE-008. The two-layer separation is necessary because
recurring and reversing templates are NOT themselves postings — they
are templates that produce postings on a schedule (per design.md
Decision D3). Both registers consume OR's audit-trail-immutable
abstraction per ADR-022; no app-local audit table.

#### Scenario: Reviewer confirms no audit duplication

- **GIVEN** the shillinq codebase
- **WHEN** scanned for files writing to a `journal_audit_*` table or
  appending to an app-local events log
- **THEN** no such code SHALL exist; the audit trail comes from OR
  per ADR-022.

### Requirement: REQ-JE-002 — The `JournalEntry` schema SHALL declare a fixed minimum field set

The `JournalEntry` schema MUST declare the following fields with the
indicated types and required flags; additional Tier-N fields MAY be
layered on later but the minimum set SHALL NOT shrink.

| Field | Type | Required | Purpose |
|---|---|---|---|
| `journalNumber` | string | Yes | Sequential journal number unique per administration + fiscal year |
| `entryDate` | date | Yes | Operator-authored entry date |
| `description` | string | Yes | Human-readable description |
| `lines` | array of object | Yes | Embedded line preview: `{accountNumber, side, amount, description}` per row (materialised into `GLLine` on post) |
| `sourceDocumentUri` | string (URI) | No | FK to a docudesk attachment per REQ-JE-006 |
| `sourceDocumentApp` | enum | No | One of `docudesk`, `external` (room for non-docudesk URIs in later tiers) |
| `journalType` | enum | Yes | One of `manual`, `recurring`, `reversing` (per REQ-JE-003) |
| `cadence` | object | Conditional | Required if `journalType` is `recurring`; forbidden otherwise (per REQ-JE-005) |
| `reversesOn` | string | Conditional | Required if `journalType` is `reversing`; references the period boundary at which the inverse posts (per REQ-JE-004) |
| `glTransactionId` | string | No | Back-reference to the materialised `GLTransaction` once posted |
| `approvalState` | enum | Yes | One of `not-required`, `pending`, `approved`, `rejected` (per REQ-JE-008) |
| `administrationId` | string | Yes | FK to the administration owning the entry |
| `state` | enum | Yes | One of `draft`, `pending`, `posted`, `voided` (per REQ-JE-008) |

Schema.org annotation: `schema:AccountingTransaction` (per shillinq
config.yaml `rules.specs`).

#### Scenario: Schema validator accepts a minimal manual journal

- **GIVEN** the schema
- **WHEN** an object `{journalNumber:"M-2026-0001", entryDate:"2026-03-01", description:"Bank fees", lines:[{accountNumber:"4500", side:"debit", amount:25}, {accountNumber:"1010", side:"credit", amount:25}], journalType:"manual", approvalState:"not-required", administrationId:"adm-1", state:"draft"}` is saved
- **THEN** validation MUST pass.

#### Scenario: Recurring journal without cadence fails

- **GIVEN** a journal with `journalType: "recurring"` and no
  `cadence`
- **WHEN** saved
- **THEN** validation MUST fail with a "cadence required for
  recurring journals" error per REQ-JE-005.

### Requirement: REQ-JE-003 — The `journalType` field SHALL be a closed enum of `manual`, `recurring`, `reversing`

The schema SHALL constrain `journalType` to exactly these three
string values; any other value MUST be rejected by validation. T1
supports exactly three journal sub-types. Adding a new sub-type
(e.g. `closing` for T3 period close) MUST go through a future
openspec change with explicit enum-extension justification.

- `manual` — operator-authored one-off entry (memoriaalboeking)
- `recurring` — template that materialises postings on a cadence
  (subscriptions, monthly depreciation, periodic accruals)
- `reversing` — entry whose materialised posting is automatically
  inverted at the start of a designated future period (period-end
  accruals, prepaid expenses)

#### Scenario: Unknown journalType is rejected

- **GIVEN** the schema
- **WHEN** a journal with `journalType: "closing"` is saved
- **THEN** validation MUST fail with an enum-violation error.

### Requirement: REQ-JE-004 — Reversing journals SHALL automatically materialise an inverse `GLTransaction` on the designated period boundary

A `JournalEntry` with `journalType: "reversing"` MUST carry a
`reversesOn` field naming the `periodId` whose start triggers the
inverse posting. On the period boundary, OR's lifecycle engine MUST
materialise a new `GLTransaction` with the opposite `side` on every
line, post it, and link it back to the original via
`reversesTransactionId` (per REQ-GL-004's reversal mechanism).

The schedule MUST NOT be implemented as a per-app `*Job` PHP class
walking `findAll()` (per ADR-031 §"Background jobs that walk an
object queue" — paths 1 and 2 are correct; path 3 is the
anti-pattern). T1 RECOMMENDS the OR `ScheduledWorkflow` + n8n
adapter path; the alternative is a lifecycle action driven by T3's
period-close transition.

#### Scenario: Reversing accrual at year-end auto-posts in next period

- **GIVEN** a `reversing` journal posted in `periodId: "2026-12"`
  with `reversesOn: "2027-01"`, debit `4500 Fees expense` €1 000,
  credit `2100 Accrued liabilities` €1 000
- **WHEN** period `2027-01` begins
- **THEN** a new `GLTransaction` MUST be materialised with debit
  `2100 Accrued liabilities` €1 000, credit `4500 Fees expense`
  €1 000, posted, and the original transaction's
  `reversesTransactionId` MUST point at the new one.

### Requirement: REQ-JE-005 — Recurring journals SHALL declare a `cadence` object that the OR scheduled-workflow primitive consumes

A `JournalEntry` with `journalType: "recurring"` MUST carry a
`cadence` object of the shape `{interval: "monthly"|"weekly"|"daily"|"yearly", anchor: "<iso-date>", endsOn: "<iso-date>"|null, count: <integer>|null}`. The cadence MUST be consumed by OR's
`ScheduledWorkflow` primitive (per ADR-031 §"Use OR's
`ScheduledWorkflow` + n8n adapter"); on each scheduled tick, a new
`GLTransaction` MUST be materialised from the template's `lines`,
balanced per REQ-GL-005, and posted. The materialised posting MUST
back-reference the template via `journalEntryId`.

#### Scenario: A monthly subscription posting fires on the first of each month

- **GIVEN** a `recurring` journal with
  `cadence: {interval: "monthly", anchor: "2026-01-01", endsOn: null, count: null}` and a 2-line template
- **WHEN** the OR scheduled-workflow engine ticks on
  2026-02-01, 2026-03-01, 2026-04-01
- **THEN** three new `GLTransaction` rows MUST be materialised, each
  with `journalEntryId` pointing at the template and each posted and
  audit-trailed.

#### Scenario: A bounded recurring journal stops after `count` materialisations

- **GIVEN** a template with `cadence: {..., count: 12, endsOn: null}`
  and 12 already-materialised postings
- **WHEN** the OR scheduled-workflow engine ticks again
- **THEN** no new `GLTransaction` MUST be materialised for this
  template.

### Requirement: REQ-JE-006 — Source-document linkage SHALL consume docudesk via a foreign-key URI; no embedded blob

`JournalEntry.sourceDocumentUri` MUST hold a stable URI pointing at a
docudesk attachment (or, for `sourceDocumentApp: "external"`, an
external system URI). The file content MUST NOT be embedded in the
register (per ADR-022 — consume docudesk's abstraction, do not
duplicate). shillinq MUST NOT add any file-storage code, file-upload
endpoint, or attachment table.

#### Scenario: Attaching a PDF invoice references docudesk by URI

- **GIVEN** a docudesk attachment exists at URI
  `docudesk://attachments/0d4e9c7f-…/invoice-2026-0001.pdf`
- **WHEN** the journal entry is saved with that URI in
  `sourceDocumentUri` and `sourceDocumentApp: "docudesk"`
- **THEN** the save MUST succeed; **AND** rendering the journal in
  the detail page MUST link to the docudesk attachment (resolution
  via the docudesk URI scheme).

#### Scenario: Reviewer confirms no file blob in the register

- **GIVEN** the `JournalEntry` schema
- **WHEN** inspected for `base64`, `binary`, `byte[]` field types or
  `multipart/form-data` upload endpoints in shillinq controllers
- **THEN** no such fields or endpoints SHALL exist.

### Requirement: REQ-JE-007 — Posting a `JournalEntry` SHALL materialise exactly one balanced `GLTransaction`

The `JournalEntry.post` lifecycle action MUST materialise exactly one
`GLTransaction` (with N `GLLine` rows derived 1:1 from
`JournalEntry.lines`) and transition the journal to `state: posted`.
The materialised posting MUST satisfy REQ-GL-005's balance invariant
or the journal post MUST fail without partial-state. The
materialisation MUST be a declarative lifecycle outcome (per
ADR-031 / design.md Decision D3), not an orchestration in a PHP
service.

#### Scenario: Posting a balanced manual journal succeeds

- **GIVEN** a draft manual journal with 2 lines summing to balanced
- **WHEN** the operator posts it
- **THEN** a `GLTransaction` with `state: posted` MUST exist;
  **AND** `JournalEntry.glTransactionId` MUST reference the new
  transaction; **AND** `JournalEntry.state` MUST be `posted`.

#### Scenario: Posting an unbalanced journal fails atomically

- **GIVEN** a draft journal whose `lines` are unbalanced
- **WHEN** the operator tries to post
- **THEN** no `GLTransaction` MUST be created; **AND** the journal
  state MUST remain `draft`; **AND** the operator MUST see a
  "transaction is not balanced" error surfaced from REQ-GL-005.

### Requirement: REQ-JE-008 — Journal posting SHALL gate through OR's approval-workflow abstraction; no app-local approval table

`JournalEntry` MUST declare an `x-openregister-lifecycle` block with
state transitions:

| From | To | Trigger | Guard |
|---|---|---|---|
| `draft` | `pending` | operator submit | none |
| `pending` | `posted` | operator post action | approval-workflow guard per administration policy (per ADR-022 — consumed from OR's approval-workflow extension); REQ-JE-007 materialisation |
| `draft` | `posted` | operator post (when approval not required by policy) | REQ-JE-007 materialisation; `approvalState` MUST be `not-required` |
| `pending` | `draft` | approver rejection | `approvalState` MUST become `rejected` |
| `posted` | `voided` | operator action | materialised `GLTransaction` MUST already be reversed per REQ-GL-004 |

The approval policy (which journals require approval, which approvers
are eligible, single vs dual control) MUST be configured through OR's
approval-workflow extension — NOT through an app-local approver table
or per-shillinq approval service. Per ADR-022 anti-pattern list.

#### Scenario: Below-threshold journal posts without approval

- **GIVEN** an administration policy "journals ≤ €5 000 do not
  require approval"
- **WHEN** an operator posts a balanced €100 manual journal
- **THEN** the journal MUST transition `draft → posted` directly
  with `approvalState: not-required`; the GL transaction MUST be
  materialised.

#### Scenario: Above-threshold journal blocks until approver acts

- **GIVEN** an administration policy "journals > €5 000 require an
  approver in role `bookkeeping-approver`"
- **WHEN** an operator submits a €10 000 journal
- **THEN** the journal MUST transition `draft → pending` with
  `approvalState: pending`; the GL transaction MUST NOT yet exist.
- **AND WHEN** an approver in the required role approves
- **THEN** the journal MUST transition `pending → posted` with
  `approvalState: approved` and the GL transaction MUST materialise.

#### Scenario: Reviewer confirms no parallel approval table

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Db/` Mapper classes or
  `appinfo/info.xml` table declarations naming `journal_approval_*`,
  `approver_*`, or `approval_route_*`
- **THEN** no such classes or declarations SHALL exist; approval
  flow comes from OR.

### Requirement: REQ-JE-009 — Journals SHALL be reachable through the shillinq manifest navigation

`src/manifest.json` MUST declare a navigation entry (`Bookkeeping >
Journals`) with a `type: index` page binding to the `JournalEntry`
register and a `type: detail` page rendering the fields from
REQ-JE-002. The detail page MUST visibly distinguish the three
`journalType` values and surface `approvalState`,
`sourceDocumentUri`, and (when posted) a link to the materialised
`GLTransaction`. Rendering MUST use `@conduction/nextcloud-vue`'s
generic `CnIndexPage` / `CnDetailPage` components — no bespoke Vue
files (per ADR-024 Tier-4).

#### Scenario: Index page lists journals with type and state

- **GIVEN** the manifest declares the Journals pages
- **WHEN** an operator opens `/index.php/apps/shillinq/journals`
- **THEN** `CnIndexPage` MUST render columns including
  `journalNumber`, `entryDate`, `description`, `journalType`,
  `state`, `approvalState`.

#### Scenario: Detail page links to materialised GL posting

- **GIVEN** a posted journal with `glTransactionId` set
- **WHEN** the operator opens the detail page
- **THEN** the page MUST surface a link to the materialised
  `GLTransaction` detail page (rendered by the General Ledger
  capability's detail page per REQ-GL-007).

### Requirement: REQ-JE-010 — Voided journals SHALL leave both the journal and its materialised posting auditable but excluded from balances

Voiding (transitioning `posted → voided`) MUST require the
materialised `GLTransaction` to already be reversed per REQ-GL-004
(i.e. an offsetting `GLTransaction` exists). Once voided, the
`JournalEntry` MUST remain queryable for audit purposes but MUST be
excluded from default journal-listing filters and from any
sub-ledger reconciliation aggregations. The audit trail MUST record
the void operator and timestamp per OR's audit-trail-immutable
abstraction.

#### Scenario: Voiding without reversal fails

- **GIVEN** a posted journal whose materialised GL transaction has
  not been reversed
- **WHEN** an operator attempts to void the journal
- **THEN** the transition MUST fail with a "reverse the GL
  transaction first" error.

#### Scenario: Voided journal stays in audit but drops from default view

- **GIVEN** a voided journal `J1`
- **WHEN** the default journal index loads
- **THEN** `J1` MUST NOT appear; **AND WHEN** the
  "include voided" filter is applied, `J1` MUST appear with its
  void timestamp and the actor who voided it.
