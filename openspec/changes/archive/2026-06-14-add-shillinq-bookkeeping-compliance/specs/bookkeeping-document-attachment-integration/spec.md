# Spec: bookkeeping-document-attachment-integration

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (compliance + operations)
**Depends on:** none (defines a cross-app contract consumed by other T1 + T2 specs)

This capability defines the foreign-key contract for attaching source
documents (invoices, receipts, bank statements, contracts) to
bookkeeping objects via docudesk. Per ADR-022, shillinq MUST NOT
implement its own file-storage layer; every attachment is a docudesk
reference. This spec is consumed by the AP, AR, journal-entry, and
bank-statement specs.

The Belastingdienst mandates 7-year retention of source documents.
The contract defined here satisfies that requirement by delegating
storage and retention to docudesk; shillinq carries the FK only.

## ADDED Requirements

### Requirement: REQ-DA-001 — Source document attachments SHALL be stored in docudesk and referenced by FK URI from bookkeeping objects

Per ADR-022, shillinq MUST NOT store or serve attachment files.
Every source-document attachment MUST be stored in docudesk and
referenced from the bookkeeping object via a
`sourceDocumentUri` field of the form:

```
docudesk://attachments/<uuid>/<filename>
```

The URI pattern MUST be validated on save (format check: scheme
`docudesk://`, path has at least 2 segments). No base64 file
embedding, no shillinq-local file upload endpoint, no app-local
file table shall be created (per ADR-022 anti-pattern list).

The following bookkeeping objects carry `sourceDocumentUri`:
- `JournalEntry.sourceDocumentUri` (T1 spec additive field)
- `APInvoice.sourceDocumentUri` (REQ-AP-003)
- `ARInvoice.sourceDocumentUri` (REQ-AR-003)
- `BankStatement.sourceDocumentUri` (REQ-BR-002)

#### Scenario: Schema validator accepts a valid docudesk URI

- **GIVEN** the `APInvoice` schema
- **WHEN** an object with `sourceDocumentUri: "docudesk://attachments/a1b2c3/factuur-2026-0042.pdf"` is validated
- **THEN** validation MUST pass.

#### Scenario: Schema validator rejects an invalid URI scheme

- **GIVEN** the `APInvoice` schema
- **WHEN** an object with `sourceDocumentUri: "https://example.com/factuur.pdf"` is validated
- **THEN** validation MUST fail with a URI-scheme-violation error.

#### Scenario: Reviewer confirms no shillinq file upload endpoint

- **GIVEN** `appinfo/routes.php`
- **WHEN** scanned for route paths matching `/attachment/upload`,
  `/document/store`, or similar
- **THEN** no such routes SHALL exist.

### Requirement: REQ-DA-002 — The system SHALL declare expected mime types per attachment role

The system SHALL declare an expected set of MIME types per attachment role as schema-level metadata. Each `sourceDocumentUri` context has an expected set of MIME types.
These MUST be declared as schema-level metadata (e.g.
`x-openregister-attachment-roles`) so that the OR object-interactions
UI can validate and display the attachment type correctly:

| Attachment role | Expected MIME types |
|---|---|
| `invoice` (AP and AR) | `application/pdf`, `application/vnd.oasis.opendocument.text`, `image/jpeg`, `image/png` |
| `receipt` (JournalEntry supporting document) | `application/pdf`, `image/jpeg`, `image/png`, `image/heic` |
| `statement` (BankStatement) | `application/pdf`, `application/xml` (CAMT.053), `text/plain` (MT940), `text/csv` |
| `contract` (optional, any bookkeeping object) | `application/pdf`, `application/msword`, `application/vnd.openxmlformats-officedocument.wordprocessingml.document` |

The mime-type check MUST be a schema-level declaration, not a PHP
file-type validation service.

#### Scenario: Attaching a PDF invoice is accepted

- **GIVEN** an `APInvoice` object
- **WHEN** a docudesk attachment with MIME type `application/pdf`
  is linked as the `invoice` role
- **THEN** the attachment MUST be accepted without a mime-type
  violation error.

#### Scenario: Attaching an unsupported file type as invoice is rejected

- **GIVEN** an `APInvoice` object
- **WHEN** a docudesk attachment with MIME type `video/mp4` is linked
  as the `invoice` role
- **THEN** the attachment MUST be rejected with a mime-type violation
  error surfacing the expected types.

### Requirement: REQ-DA-003 — The bookkeeping flow SHALL NOT block on docudesk transient downtime

The bookkeeping flow MUST NOT block on docudesk transient downtime; saves and lifecycle transitions proceed and the gap is recorded in the audit trail. When a bookkeeping object with a `sourceDocumentUri` is saved and
docudesk is temporarily unreachable:

1. The save MUST succeed — the `sourceDocumentUri` is persisted
   as-is (the URI is a reference, not a file store operation).
2. The audit trail MUST record the docudesk unavailability via OR's
   audit mechanism (a warning-level event, not an error).
3. The detail page MUST render a warning banner: "Brondocument
   tijdelijk niet beschikbaar — controleer de verbinding met docudesk."
4. The bookkeeping lifecycle (AP approval, AR issuance, etc.) MUST
   NOT be blocked solely because docudesk is unreachable.

#### Scenario: APInvoice save succeeds when docudesk is unreachable

- **GIVEN** docudesk is unreachable (simulated network timeout)
- **AND** an `APInvoice` object is saved with
  `sourceDocumentUri: "docudesk://attachments/abc123/factuur.pdf"`
- **WHEN** the save is submitted
- **THEN** the save MUST succeed; the URI MUST be persisted; the
  OR audit trail MUST record a docudesk-unavailability warning.

#### Scenario: APInvoice detail page shows warning when docudesk is unavailable

- **GIVEN** `APInvoice` with a `sourceDocumentUri` and docudesk
  currently unreachable
- **WHEN** the operator opens the AP invoice detail page
- **THEN** a warning banner MUST be displayed explaining docudesk
  unavailability; the remaining invoice fields MUST render normally.

### Requirement: REQ-DA-004 — The `auditor` role SHALL be able to access source documents via docudesk

The RBAC configuration MUST grant `auditor`-role users read access
to the `sourceDocumentUri` field and to the linked docudesk object
(via the docudesk RBAC contract). The access grant is declared on
the bookkeeping schema's RBAC block in `lib/Settings/shillinq_register.json`.
Shillinq does not control docudesk's own RBAC — the auditor must
also hold a `docudesk:reader` role in OR's authorization scheme.

#### Scenario: Auditor can read the sourceDocumentUri on an APInvoice

- **GIVEN** a user with `auditor` role reads `APInvoice` `INK-2026-0001`
- **WHEN** the object is retrieved from OR
- **THEN** the `sourceDocumentUri` field MUST be present in the response
  (not masked or omitted by RBAC).

### Requirement: REQ-DA-005 — The document-attachment contract SHALL be consumed by T1 and T2 specs

The following specs MUST reference this spec's FK URI contract
(i.e. the `sourceDocumentUri` field with the `docudesk://` URI scheme
and the mime-type metadata) rather than defining their own attachment
mechanism:
- `bookkeeping-journal-entries` (T1) — JournalEntry source document
- `bookkeeping-accounts-payable-core` (T2) — APInvoice original invoice
- `bookkeeping-accounts-receivable-core` (T2) — ARInvoice issued invoice
- `bookkeeping-bank-reconciliation` (T2) — BankStatement uploaded file

This spec is the single source of truth for the attachment contract.
No other spec may define a different `sourceDocumentUri` format.

#### Scenario: All consuming specs use the same URI format

- **GIVEN** the schema definitions of `JournalEntry`, `APInvoice`,
  `ARInvoice`, and `BankStatement`
- **WHEN** their `sourceDocumentUri` field patterns are inspected
- **THEN** all MUST use the `docudesk://attachments/<uuid>/<filename>`
  URI scheme defined in REQ-DA-001.
