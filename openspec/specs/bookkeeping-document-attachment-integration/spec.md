---
status: done
---

# Spec: bookkeeping-document-attachment-integration

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** none (defines a cross-app contract; consumed by T1 + other T2 specs)

## Purpose

This specification defines the requirements for bookkeeping document attachment integration in the Shillinq Nextcloud accounting application, establishing the data model, behaviour and acceptance scenarios for this capability.

## Requirements

@e2e exclude unbuilt UI: attachment detail page not yet implemented


### REQ-DA-001: Source-document attachment for every bookkeeping object SHALL consume docudesk via a foreign-key URI; no file storage in shillinq

The system SHALL satisfy this requirement: Source-document attachment for every bookkeeping object SHALL consume docudesk via a foreign-key URI; no file storage in shillinq.

Per ADR-022, source documents (PDF invoices, scanned receipts,
bank statements, contracts) MUST be stored in docudesk and
referenced from bookkeeping registers via a foreign-key URI. The
implementation MUST NOT introduce file-storage code, attachment
upload endpoints, blob columns, or a parallel attachment register
in shillinq. This is the ADR-022 anti-pattern explicitly enumerated
under "App-local 'linked bookmarks/files/notes/...' that mirror an
OR / sibling-app integration".

The contract applies to every bookkeeping register that needs to
reference a source document:

| Register | FK field | FK app | Role |
|---|---|---|---|
| `JournalEntry` (T1) | `sourceDocumentUri` + `sourceDocumentApp` | docudesk | Memo / receipt / supporting document |
| `APInvoice` (T2) | `sourceDocumentUri` | docudesk | Vendor's PDF / scanned invoice |
| `ARInvoice` (T2) | `sourceDocumentUri` | docudesk | Issued PDF invoice |
| `BankStatement` (T2) | `sourceDocumentUri` | docudesk | Original CAMT.053 / MT940 file or scanned statement |
| `PaymentRun` (T2) | `sourceDocumentUri` | docudesk | Generated SEPA pain.001 XML artefact (archival) |

T1's `JournalEntry.sourceDocumentApp` enum (`docudesk`, `external`)
already establishes the pattern; T2 extends it to the new
registers above.

#### Scenario: Reviewer confirms no file storage in shillinq

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `base64` / `binary` / `byte[]` field types
  in any schema, for `multipart/form-data` upload endpoints in
  any controller, or for `lib/Db/Attachment*` Mapper classes
- **THEN** no such fields, endpoints, or classes SHALL exist.

#### Scenario: Each bookkeeping register declares the FK field

- **GIVEN** `lib/Settings/shillinq_register.json`
- **WHEN** the schemas listed above are inspected
- **THEN** each MUST carry `sourceDocumentUri` (string, URI shape);
  T1's `JournalEntry` MUST also carry `sourceDocumentApp` (enum);
  T2 registers MUST also accept `sourceDocumentApp` per REQ-DA-003.

### REQ-DA-002: The FK URI SHALL conform to the `docudesk://attachments/<uuid>/<filename>` scheme; external sources use a stable URI

The `sourceDocumentUri` field MUST hold either:

- A `docudesk://attachments/<uuid>/<filename>` URI pointing at a
  docudesk attachment record (the canonical case); OR
- An external system URI (e.g. `https://`, `s3://`, `nextcloud://`)
  when `sourceDocumentApp = external` — the bookkeeping flow does
  not resolve external URIs; resolution is the operator's concern.

URI shape MUST be validated by JSON Schema `format: uri` on every
`sourceDocumentUri` field. The docudesk URI scheme MUST also be
validated by a pattern matcher (e.g.
`^docudesk://attachments/[a-f0-9-]{36}/.+$`) when
`sourceDocumentApp = docudesk`.

#### Scenario: Valid docudesk URI is accepted

- **GIVEN** an `APInvoice` is saved with
  `sourceDocumentUri: "docudesk://attachments/0d4e9c7f-1234-4567-89ab-cdef01234567/invoice.pdf"`
  and `sourceDocumentApp: "docudesk"`
- **WHEN** the schema validates
- **THEN** validation MUST pass.

#### Scenario: Malformed docudesk URI is rejected

- **GIVEN** an `APInvoice` is saved with
  `sourceDocumentUri: "docudesk://not-a-uuid"` and
  `sourceDocumentApp: "docudesk"`
- **WHEN** the schema validates
- **THEN** validation MUST fail with a "malformed docudesk URI"
  error per the pattern matcher.

### REQ-DA-003: Expected mime-types SHALL be declared per attachment role; non-conforming uploads are flagged but not blocked

Each register's `sourceDocumentUri` field declaration MUST carry
metadata (in an `x-shillinq-attachment-role` or similar
schema-extension block) identifying the expected mime-types per
role:

| Role | Register | Expected mime-types |
|---|---|---|
| `invoice` | `APInvoice`, `ARInvoice` | `application/pdf`, `image/png`, `image/jpeg`, `application/xml` (for inbound UBL) |
| `receipt` | `JournalEntry` | `application/pdf`, `image/png`, `image/jpeg`, `image/heic` |
| `statement` | `BankStatement` | `application/xml` (CAMT.053), `text/plain` (MT940), `application/pdf` (scanned) |
| `archive-xml` | `PaymentRun` | `application/xml` |
| `contract` | (future) | `application/pdf` |

The bookkeeping side does NOT enforce mime-type — enforcement is
docudesk's responsibility. The declaration is metadata so:

1. The detail page's "attach document" affordance can surface the
   expected mime-types to the operator.
2. A future review can audit attachments whose mime-type doesn't
   match expectation (e.g. a `.docx` attached as an invoice).

#### Scenario: Detail page surfaces expected mime-types

- **GIVEN** the manifest for the AP Invoice detail page
- **WHEN** the "attach document" action is inspected
- **THEN** the action MUST display the expected mime-types (PDF /
  PNG / JPEG / UBL XML) per the schema's
  `x-shillinq-attachment-role: invoice` block.

#### Scenario: Off-mime-type upload is allowed but flagged

- **GIVEN** an operator attaches a `.docx` file to an AP invoice
- **WHEN** the save proceeds
- **THEN** the save MUST succeed (docudesk accepts the file);
  **AND** a non-blocking warning MUST surface on the detail page
  ("attached file mime-type application/vnd.openxmlformats-officedocument…
  does not match expected role 'invoice'").

### REQ-DA-004: Bookkeeping flow SHALL NOT block on docudesk transient unavailability; the URI persists, audit records the gap

The system SHALL satisfy this requirement: Bookkeeping flow SHALL NOT block on docudesk transient unavailability; the URI persists, audit records the gap.

When docudesk is unreachable at the moment a bookkeeping object is
saved with a `sourceDocumentUri`, the save MUST succeed (the URI
is stored as-is) and the OR audit trail MUST record the
unavailability as a non-fatal event. The detail page MUST render
a warning banner ("Source document unreachable — docudesk may be
unavailable; retry later") and provide a retry action.

The implementing rationale: a 7-year retention obligation
(Belastingdienst) means the URI must persist even when the
remote system is briefly down; losing the reference because of
transient docudesk downtime would silently break the audit chain.

This contract symmetrically applies on read: a detail page
loading an unavailable URI renders the banner + retry, not a
hard error.

#### Scenario: Save succeeds when docudesk is unavailable

- **GIVEN** docudesk is unreachable (network partition or
  shutdown)
- **WHEN** an operator saves an `APInvoice` with a
  `sourceDocumentUri: "docudesk://attachments/<uuid>/invoice.pdf"`
- **THEN** the save MUST succeed; **AND** the URI MUST persist;
  **AND** an OR audit event MUST record the unavailability with
  `action: docudesk-unreachable`.

#### Scenario: Detail page renders warning + retry on unreachable URI

- **GIVEN** an `APInvoice` exists with a `sourceDocumentUri`
  pointing at docudesk and docudesk is unreachable
- **WHEN** the operator opens the detail page
- **THEN** a warning banner MUST render ("Source document
  unreachable …"); **AND** a "Retry" action MUST be visible;
  **AND** the rest of the detail page MUST render normally
  (lifecycle actions, line breakdown, etc. MUST NOT be blocked).

#### Scenario: Retry resolves the URI once docudesk returns

- **GIVEN** the warning banner is visible on the detail page
  because docudesk was unreachable
- **WHEN** docudesk recovers and the operator clicks "Retry"
- **THEN** the source document MUST render normally; **AND** the
  warning banner MUST clear; **AND** an OR audit event MUST
  record the successful retrieval.

### REQ-DA-005: Reviewer + auditor roles SHALL see source-document attachments without elevated permissions on docudesk

The docudesk integration MUST honour the bookkeeping `auditor`
role (per T2 audit-trail capability) — an actor with the
`auditor` role on a bookkeeping object MUST be able to view its
attached source documents in docudesk without additional
docudesk-side role grants.

The integration MUST be expressed declaratively — typically by
the manifest's docudesk-side panel honouring an OR
role-mapping (per ADR-022's RBAC abstraction) — and MUST NOT
require a shillinq-side proxy controller forwarding attachment
requests on the operator's behalf.

If OR's cross-app RBAC abstraction cannot express
"auditor-on-shillinq-object granted view on linked docudesk
attachment", the shape-neutral fallback per ADR-031 exception
is a single-method PHP RBAC-mapping guard; the spec is
shape-neutral on which.

#### Scenario: Auditor opens AR invoice and sees PDF

- **GIVEN** an actor in role `auditor` on `ARInvoice INV-C-2026-0001`
  which has a `sourceDocumentUri` pointing at docudesk
- **WHEN** the actor opens the detail page
- **THEN** the PDF MUST render in the docudesk side panel
  without prompting for additional permissions.

#### Scenario: Reviewer confirms no proxy controller

- **GIVEN** the shillinq codebase
- **WHEN** scanned for `lib/Controller/*Attachment*.php` or
  `lib/Controller/*Document*Proxy*.php`
- **THEN** no such files SHALL exist; attachment serving is
  docudesk's responsibility.
