# Spec: administration-import-migration

**Status:** proposed
**Scope:** shillinq
**Tier:** T2 (operations)
**Depends on:**
- `bookkeeping-multi-administratie` (administratie model; XAF export symmetry — REQ-MA-007)
- `bookkeeping-chart-of-accounts` (target account model, RGS codes)
- `bookkeeping-general-ledger` / `bookkeeping-journal-entries` (opening journal posting surface)
- `bookkeeping-accounts-receivable-core` / `bookkeeping-accounts-payable-core` (open-item target schemas + `CustomerMaster`)
- `bookkeeping-period-close` (open-period guard)
- `shillinq-notifications` (x-openregister-notifications rule conventions)

## ADDED Requirements

### Requirement: REQ-AIM-001 — The system SHALL track every migration as an OpenRegister-managed ImportBatch with the source file living in Nextcloud Files

The `ImportBatch` schema MUST be declared in the ADR-037 register fragment
`lib/Settings/register.d/administration-import-migration.json` (NOT the
monolith) with `x-openregister-audit: true`. CRUD goes through
OpenRegister's generic object surface (ADR-022); no parallel PHP model.

| Property | Type | Required | Purpose |
|---|---|---|---|
| `administration` | FK | Yes | Target administratie (per `bookkeeping-multi-administratie`) |
| `sourceSystem` | enum | Yes | xaf-generic, e-boekhouden, exact-online, moneybird, snelstart |
| `sourceFiles` | array | Yes | NC Files references (XAF + optional CSVs); link, don't store |
| `migrationDate` | date | Yes | Opening-balance date (period boundary) |
| `scope` | object | Yes | `{ chartOfAccounts, openingBalance, openItems, relations }` booleans |
| `status` | enum | Yes | Lifecycle state (see REQ-AIM-002) |
| `stagedCounts` | object | Computed | Accounts / journal lines / AR items / AP items / relations staged |
| `stagingPayload` | object | No | Parsed staged data (between parse and post; cleared after post) |
| `validationReport` | object | No | Structured findings (errors block, warnings inform) |
| `dryRunReport` | object | No | Would-be journal, open items, contacts (REQ-AIM-008) |
| `postingRefs` | object | No | Opening journal id, created object ids (for reversal) |
| `idempotencyKey` | string | Computed | Hash of source files + scope + administration |
| `mappingProfile` | string | No | Saved mapping-profile name for reuse across batches |

#### Scenario: Operator creates a batch referencing files in NC Files

- **GIVEN** an operator has uploaded `auditfile-2025.xaf` to Nextcloud Files
- **WHEN** an `ImportBatch` is created via OpenRegister's standard object API with `sourceSystem = e-boekhouden` and that file referenced
- **THEN** the batch MUST be persisted in `draft` with `sourceFiles` holding the NC Files reference, and the file bytes MUST NOT be copied into the register

#### Scenario: Reviewer confirms no parallel import storage

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for app-local import tables, upload endpoints storing blobs, or a parallel PHP entity/Mapper for batches
- **THEN** none MUST exist; batch state lives only in the `ImportBatch` register

### Requirement: REQ-AIM-002 — Import SHALL progress through a declarative, fail-closed lifecycle with dry-run as a mandatory state before posting

The lifecycle MUST be declared via `x-openregister-lifecycle` on
`ImportBatch` (ADR-031):

`draft → parsing → staged → mapping → validated | validation_failed →
dry_run_complete → posting → posted | posting_failed → reversed`

- `draft → parsing`: requires `sourceFiles`, `administration`,
  `migrationDate`, at least one scope flag.
- `staged → mapping → validated`: validation MUST hard-fail to
  `validation_failed` on any error-severity finding (REQ-AIM-007).
- `validated → dry_run_complete`: dry-run report generated; **posting MUST
  be unreachable without a dry-run** (no validated → posting edge).
- `dry_run_complete → posting → posted`: posting only via the pipeline
  (REQ-AIM-009), idempotent.
- `posted → reversed`: only while the target period is open.
- Parse and post MUST execute under OpenRegister's background machinery —
  never synchronously inside the initiating HTTP request — with the
  lifecycle state observable throughout.

#### Scenario: Posting is unreachable without a dry-run

- **GIVEN** a batch in `validated`
- **WHEN** a transition directly to `posting` is attempted
- **THEN** the transition MUST be rejected; only `validated → dry_run_complete → posting` is a legal path

#### Scenario: Validation failure is a terminal review state, not a silent skip

- **GIVEN** a staged batch whose opening journal does not balance
- **WHEN** validation runs
- **THEN** the batch MUST transition to `validation_failed` with the finding in `validationReport`, and no journal, open item, or contact MUST have been written

#### Scenario: Large file parses in the background

- **GIVEN** a 250 MB auditfile referenced by a batch
- **WHEN** parsing is started
- **THEN** the initiating request MUST return immediately with the batch in `parsing`, the parse MUST run via OR's background machinery using stream parsing, and the batch MUST reach `staged` with `stagedCounts` populated

### Requirement: REQ-AIM-003 — The system SHALL parse standard XML Auditfile Financieel (XAF 3.2) and the four package dialects through import profiles

`lib/Service/Import/AuditfileParser.php` MUST stream-parse (XMLReader, no
DOM load, XXE-safe) standard XAF 3.2, extracting: company data, the ledger
account list (with RGS codes where present), relations
(customersSuppliers), and opening-balance data. Package specifics MUST live
in one `ImportProfileInterface` implementation per source system
(e-Boekhouden, Exact Online, Moneybird, SnelStart, xaf-generic), each
encapsulating dialect quirks plus CSV column maps for artifacts that
package does not include in its XAF (notably open items). Unknown or
malformed constructs MUST produce error-severity validation findings —
never silently skipped rows. Each profile MUST ship with a realistic
fixture file exercised in unit tests.

#### Scenario: Standard XAF parses into staged artifacts

- **GIVEN** a valid XAF 3.2 file with 240 ledger accounts and 310 relations
- **WHEN** the batch is parsed with the `xaf-generic` profile
- **THEN** `stagedCounts` MUST report 240 accounts and 310 relations, and no books MUST have been touched

#### Scenario: Package profile supplies the open items its XAF lacks

- **GIVEN** a Moneybird XAF plus the Moneybird open-posten CSV referenced on the same batch
- **WHEN** parsed with the `moneybird` profile
- **THEN** the staged open items MUST come from the CSV per the profile's column map, joined to the staged relations

#### Scenario: Malformed construct fails loudly

- **GIVEN** an auditfile with a transaction line missing its amount
- **WHEN** parsed
- **THEN** an error-severity finding identifying the element and line MUST be recorded, and the row MUST NOT be silently dropped

#### Scenario: Shillinq's own XAF export round-trips

- **GIVEN** an XAF produced by the `bookkeeping-multi-administratie` export (REQ-MA-007) for an administration
- **WHEN** imported into an empty administration via the `xaf-generic` profile
- **THEN** the resulting trial balance at the migration date MUST equal the source administration's trial balance

### Requirement: REQ-AIM-004 — Source accounts SHALL be mapped to the Shillinq chart via ImportMapping rows with RGS-based auto-suggestion, and unmapped accounts MUST block posting

The `ImportMapping` schema MUST be declared in the same fragment:

| Property | Type | Required | Purpose |
|---|---|---|---|
| `batch` | FK ImportBatch | Yes | Owning batch |
| `sourceCode` | string | Yes | Source ledger account code |
| `sourceName` | string | No | Source account name |
| `sourceRgsCode` | string | No | RGS code from the source file, if present |
| `targetAccount` | FK | No | Shillinq GL account (per `bookkeeping-chart-of-accounts`) |
| `mappingSource` | enum | Yes | rgs-auto, profile-default, suggestion, manual, unmapped |
| `confirmed` | boolean | Yes | Operator confirmation state |

Resolution order: (1) source RGS code matches a target account's RGS code →
`rgs-auto`, pre-confirmed; (2) saved mapping profile hit →
`profile-default`; (3) code/name similarity → `suggestion`, requires
confirmation; (4) `unmapped`. Any `unmapped` or unconfirmed row referenced
by staged data MUST block the `mapping → validated` transition
(fail-closed). Confirmed mappings MUST be savable as a named profile
reusable on later batches (the accountant-with-50-clients case).

#### Scenario: RGS codes auto-map

- **GIVEN** a staged source account `1300 Debiteuren` with RGS code `BVorDebHad` and a Shillinq account carrying the same RGS code
- **WHEN** mapping resolution runs
- **THEN** an `ImportMapping` row MUST link them with `mappingSource = rgs-auto` and `confirmed = true`

#### Scenario: Unmapped account blocks validation

- **GIVEN** a batch with one staged account that resolved to `unmapped`
- **WHEN** the transition to `validated` is attempted
- **THEN** it MUST be rejected with a finding naming the source account code

#### Scenario: Saved profile pre-fills the next client's migration

- **GIVEN** a confirmed mapping saved as profile `kantoor-standaard`
- **WHEN** a new batch for another administration selects that profile
- **THEN** matching source codes MUST resolve as `profile-default`, leaving only new accounts for review

### Requirement: REQ-AIM-005 — Posting SHALL create exactly one balanced opening journal per batch through the existing journal-entry surface

The opening balance MUST be posted as a single journal entry of type
`opening-balance` at `migrationDate`, composed by the pipeline but written
through the existing `bookkeeping-journal-entries` surface (no direct GL
row writes, no importer-local posting rules). The journal MUST balance to
zero, land in an open period (per `bookkeeping-period-close`), and carry
the batch reference as its source for the audit trail. P&L history is NOT
imported; equity arrives as part of the balance positions.

#### Scenario: Balanced opening journal is posted once

- **GIVEN** a `dry_run_complete` batch whose staged balance sums debits = credits = EUR 187,400
- **WHEN** posting runs
- **THEN** exactly one opening journal MUST exist with 0.00 imbalance, dated `migrationDate`, referencing the batch, and `postingRefs.openingJournalId` MUST be set

#### Scenario: Closed period blocks posting

- **GIVEN** a batch whose `migrationDate` falls in a closed period
- **WHEN** validation runs
- **THEN** an error-severity finding MUST block posting until the operator changes the migration date or the period is open

### Requirement: REQ-AIM-006 — Open AR/AP items SHALL be imported as real ARInvoice / AP invoice records whose totals exactly equal the control-account opening amounts

Imported open items MUST be created through the existing AR/AP object
surfaces with: original source invoice number, invoice date, due date,
outstanding amount, and relation link. They MUST enter the existing
lifecycles in the correct state (`issued`, or `overdue` when the due date
has passed) so dunning (`bookkeeping-credit-control-dunning`) and payment
matching work on them unchanged. They MUST be flagged `importedOpenItem`
and MUST NOT consume the no-gap invoice number sequence
(`bookkeeping-quote-order-invoice` REQ-QOI-007) — original numbers are
preserved verbatim. Validation MUST hard-fail unless the sum of imported AR
open items equals the AR control account's opening amount in the staged
balance, and likewise for AP (the double-count/missing-item guard).

#### Scenario: Open AR items reconcile to the control account

- **GIVEN** a staged opening balance with AR control account at EUR 24,200 and staged open AR items summing EUR 24,200
- **WHEN** validation runs
- **THEN** the AR open-items check MUST pass; posting creates the items with their original numbers and the correct lifecycle states

#### Scenario: Mismatch between open items and control account blocks posting

- **GIVEN** staged open AR items summing EUR 23,000 against an AR control opening of EUR 24,200
- **WHEN** validation runs
- **THEN** an error-severity finding MUST report the EUR 1,200 difference and the batch MUST transition to `validation_failed`

#### Scenario: Imported overdue item enters dunning untouched

- **GIVEN** an imported AR item with a due date 40 days in the past
- **WHEN** posting completes
- **THEN** the item MUST exist in the `overdue` state and be picked up by the existing dunning cadence with no importer-specific code path

#### Scenario: Original numbers never collide with the invoice sequence

- **GIVEN** an imported open item with source number `2025-0871`
- **WHEN** the administration later issues its first native invoice
- **THEN** the native invoice MUST receive the next number of Shillinq's own sequence, unaffected by imported numbers

### Requirement: REQ-AIM-007 — Relations SHALL be imported as Nextcloud addressbook contacts with financial masters referencing the contact, never an invented party schema

Per the fleet convention (a counterparty is a Nextcloud entity):

- Identity fields (name, address, email, phone, KvK number, BTW number)
  MUST be written to an NC addressbook contact via `OCP\Contacts\IManager`.
- Financial fields (payment terms, credit limit, default accounts) MUST be
  written to `CustomerMaster` / the AP supplier master, referencing the
  contact.
- Dedupe key order MUST be: KvK number, BTW number, email; an existing
  match links instead of creating, and never overwrites existing contact
  data. No-key relations are created, with a "possible duplicates" section
  (exact-name matches) in the import report.
- The fragment MUST NOT declare any Party/Customer/Supplier identity
  schema.

#### Scenario: Relation becomes a contact plus a customer master

- **GIVEN** a staged relation "Acme B.V." with KvK 12345678, an email address, and 30-day payment terms
- **WHEN** posting runs
- **THEN** an NC addressbook contact MUST exist with the identity fields, and a `CustomerMaster` row MUST reference that contact carrying the 30-day terms

#### Scenario: Existing contact is linked, not duplicated or overwritten

- **GIVEN** an addressbook contact already exists with KvK 12345678 and a manually maintained phone number
- **WHEN** the relation with the same KvK is posted
- **THEN** the master row MUST link the existing contact and the contact's phone number MUST remain unchanged

### Requirement: REQ-AIM-008 — A persisted dry-run report SHALL show the exact would-be result before anything is posted

The `dry_run_complete` state MUST persist a `dryRunReport` on the batch
containing: the full would-be opening journal (per mapped account), the
would-be AR/AP open-item lists, the would-be contact/master list with
dedupe outcomes, and all warning-severity findings. The report MUST be
renderable in the UI and exportable, serving as the migration verification
document for the accountant dossier. Posting MUST write exactly what the
dry-run reported (same staged payload, same mappings) or fail to
`posting_failed` — never post a silently different result.

#### Scenario: Dry-run shows the journal before posting

- **GIVEN** a validated batch
- **WHEN** the dry-run completes
- **THEN** `dryRunReport` MUST contain every opening-journal line with source account, mapped target account, and amount — and no journal MUST exist yet

#### Scenario: Posting matches the dry-run exactly

- **GIVEN** a `dry_run_complete` batch whose mappings are then changed
- **WHEN** posting is attempted
- **THEN** the pipeline MUST detect the staged-state change, refuse to post, and require a fresh validation + dry-run

### Requirement: REQ-AIM-009 — Posting SHALL be idempotent and reversible in one action while the period is open

- Every batch MUST carry a computed `idempotencyKey` (source files + scope
  + administration); posting a key that already posted MUST be a no-op
  returning the existing `postingRefs`.
- The `posted → reversed` action MUST: post the reversing journal for the
  opening journal, soft-delete the imported open items and master rows
  (per OR soft-delete conventions), mark the batch `reversed`, and list NC
  contacts that were created (contacts are reported, never deleted — they
  may have been enriched since).
- Reversal MUST be blocked once the target period is closed; correction
  then follows normal bookkeeping practice.
- The pipeline MUST use only the real OpenRegister ObjectService API
  (find/findAll/saveObject/createObject/updateObject/deleteObject) and
  existing app surfaces; it MUST NOT contain bookkeeping rules of its own.

#### Scenario: Re-posting the same batch is a no-op

- **GIVEN** a `posted` batch
- **WHEN** posting is triggered again (retry, double-click, replayed job)
- **THEN** no new journal, open item, contact, or master MUST be created, and the existing `postingRefs` MUST be returned

#### Scenario: Reversal cleanly unwinds the books but keeps contacts

- **GIVEN** a posted batch in a still-open period
- **WHEN** the operator reverses it
- **THEN** a reversing journal MUST zero the opening journal, the imported open items and masters MUST be soft-deleted, the batch MUST be `reversed`, and the created contacts MUST remain with their ids listed in the reversal report

#### Scenario: Reversal blocked after period close

- **GIVEN** a posted batch whose period has since been closed
- **WHEN** reversal is attempted
- **THEN** it MUST be rejected with a message pointing to correction-journal practice

### Requirement: REQ-AIM-010 — Import progress SHALL be notified via the x-openregister-notifications dialect, the wizard SHALL ship as ADR-037 manifest pages, and all strings SHALL use ENGLISH source keys

Per ADR-031 and the `shillinq-notifications` conventions, the fragment MUST
declare `updated`-trigger rules with field-change conditions on
`ImportBatch.status` for: `validation_failed`, `dry_run_complete`,
`posted`, `posting_failed`, and `reversed`. Recipients: the batch creator
(`{"kind":"field","field":"owner"}`) plus
`{"kind":"object-acl","permission":"manage"}`. Subjects in `nl` + `en`,
metadata-only (batch id, administration, state — no amounts). No imperative
dispatch, listeners, or reminder jobs (gate-18).

The frontend MUST ship as the ADR-037 manifest fragment
`src/manifest.d/administration-import-migration.json`: an "Import &
migration" entry with the wizard (upload → profile → mapping review →
validation → dry-run → post), batch list, batch detail (reports, reversal
action), and the mapping grid. Modals/dialogs in their own files under
`src/modals/` / `src/dialogs/`; every `NcSelect` carries `inputLabel`
(ADR-004 gates). All new strings use ENGLISH source keys with `nl`
translations in the same change (e.g.
`t('shillinq', 'Opening balance is not balanced')` → nl
`'Openingsbalans is niet in evenwicht'`).

#### Scenario: Failed validation notifies the operator

- **GIVEN** a batch created by alice transitions to `validation_failed`
- **WHEN** the OR notification engine evaluates the rules
- **THEN** alice MUST receive a notification with a metadata-only subject available in both `nl` and `en`

#### Scenario: No imperative dispatch code exists

- **GIVEN** the shillinq codebase after this change
- **WHEN** scanned for app-local notification dispatch or reminder background jobs introduced by this change
- **THEN** none MUST exist; all rules live in the `x-openregister-notifications` declarations (gate-18)

#### Scenario: Dutch UI renders translated strings from English keys

- **GIVEN** a user with locale `nl`
- **WHEN** the import wizard is rendered
- **THEN** labels MUST appear in Dutch, resolved from English source keys present in `l10n/en.json` and `l10n/nl.json`, and no Dutch source keys MUST exist in `t('shillinq', …)` calls
