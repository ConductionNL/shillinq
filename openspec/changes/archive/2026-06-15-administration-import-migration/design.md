# Design — Administration Import & Migration

## Context

Shillinq specifies XAF *export* (`bookkeeping-multi-administratie`
REQ-MA-007) but has no import path at all. Every target user is migrating
from e-Boekhouden, Exact Online, Moneybird, or SnelStart — all of which emit
the Belastingdienst-standardized XML Auditfile Financieel (XAF 3.2) plus
package-specific CSVs. The 2026-06-11 feature re-evaluation ranks this as
the top adoption gap: without import, onboarding means re-keying a chart of
accounts, an opening balance, and two open-item ledgers by hand.

The design tension: an import pipeline is genuinely procedural (parse →
stage → validate → post), which sits uneasily with ADR-031's
declarative-first rule. The resolution is the same one `bookings-deposits`
used for its webhook/reconciliation pair: the *state* is declarative (an
`ImportBatch` register with a lifecycle), and the procedural part is a
narrow, deterministic, fail-closed transform that only ever writes through
existing surfaces (journal entries, AR/AP objects, NC contacts). No
bookkeeping rule lives in the importer.

## Goals

- Day-one migration from the four dominant NL packages and from any
  standard XAF 3.2 auditfile.
- Migrate exactly the four artifacts Dutch switching practice requires:
  chart of accounts, opening balance, open AR/AP items, relations.
- Make wrong imports impossible to post: balanced-journal and
  control-account-equals-open-items validation hard-block posting.
- Idempotent posting and one-action reversal while the period is open.
- Relations land where party identity lives: the **NC addressbook**;
  financial masters reference the contact (no invented party schema).
- XAF symmetry: a Shillinq export must round-trip through the importer.

## Non-Goals

- No full historical journal migration in v1 (opening balance + open items
  at a period boundary is NL standard practice; the old package's XAF is
  archived as a document).
- No live API connectors to source packages (that would be an OpenConnector
  adapter, later).
- No bank statement import (owned by `bookkeeping-bank-reconciliation`).
- No re-implementation of posting rules: the importer composes the existing
  journal-entry, AR/AP, and contacts surfaces — it never writes GL rows
  directly.
- No XAF export changes (owned by `bookkeeping-multi-administratie`).

## Reuse Analysis

| Need | Reused surface | What this change adds |
|---|---|---|
| Source file storage | NC Files | `sourceFile` reference on `ImportBatch` (link, don't store) |
| Relation identity | NC addressbook (`OCP\Contacts\IManager`) | Contact create/dedupe during posting; never a Party schema |
| Customer/supplier financial masters | `CustomerMaster` (AR core) / AP supplier master | Imported rows referencing the NC contact |
| Opening journal posting | `bookkeeping-journal-entries` / GL surface | One balanced opening journal composed by the pipeline |
| Open-item records | `ARInvoice` / AP invoice schemas + their lifecycles | Imported rows entering existing states; dunning works unchanged |
| Period guard | `bookkeeping-period-close` | Posting/reversal blocked when the target period is closed |
| Target chart + RGS codes | `bookkeeping-chart-of-accounts` | RGS-based auto-mapping suggestions |
| Batch state, audit, progress | OR lifecycle + audit (`x-openregister-audit`) | `ImportBatch` lifecycle declaration |
| Completion/failure alerts | OR notification engine (ADR-031 dialect) | `updated`-trigger rules on batch status |
| Background execution | OR scheduled/async machinery | Parse + post run outside the HTTP request |

## Decisions

### D1 — Two schemas: `ImportBatch` and `ImportMapping`

The batch is the unit of lifecycle, idempotency, reporting, and reversal.
Account mappings are separate rows (not embedded) because a real chart has
hundreds of accounts and the mapping needs its own review grid, per-row
status, and reuse as a saved profile across an accountant's client
migrations. Staged journal/open-item/relation payloads stay *inside* the
batch (JSON staging payloads), since they have no independent identity or
lifecycle and exist only between parse and post.

### D2 — XAF 3.2 is the canonical input; package profiles wrap the dialects

All four packages emit XAF, so the parser targets standard XAF 3.2
(`<auditfile><company><generalLedger>/<customersSuppliers>/<transactions>`).
A profile per package (`e-boekhouden`, `exact-online`, `moneybird`,
`snelstart`, plus `xaf-generic`) encapsulates: dialect quirks (element
omissions, encoding, date formats), which artifacts the package does NOT
put in XAF, and the CSV column maps for those (e.g., open-item exports).
One `ImportProfileInterface`; each profile ships with a real-world fixture.
Unknown constructs fail validation loudly — never silently skipped rows.

### D3 — Open items are derived from the source's open-items data, not recomputed from history

XAF transaction history could theoretically reconstruct open items, but
packages disagree on settlement representation, and v1 does not import
history. The profile reads the package's open-item export (or the XAF
`openingBalance`/subledger details where present). The cross-check is
absolute: imported AR open items must sum to the AR control account's
opening amount, same for AP (REQ-AIM-006) — this kills both the
double-count and the missing-item failure modes.

### D4 — Opening balance = one balanced journal at the migration date

A single opening journal (`type: opening-balance`, source = batch
reference) posted through the existing journal-entry surface into the first
open period. Control accounts (AR/AP) receive the totals; the open-item
records carry the detail. P&L history is NOT imported (migration at a
period boundary means the source package's closed year holds the P&L);
retained earnings arrives as part of the equity balance.

### D5 — RGS is the mapping backbone

The Referentie Grootboekschema is the NL-standard account taxonomy and all
four packages can emit RGS codes. Mapping resolution order:
(1) source RGS code → target account with same RGS code (auto, confirmed by
default); (2) saved mapping profile hit; (3) code/name similarity
suggestion (operator must confirm); (4) unmapped — blocks posting,
fail-closed. The operator can always override. `mappingSource` records
which path produced each row, for the report.

### D6 — Relations: identity to NC addressbook, money fields to the masters

Per the fleet convention (a counterparty is a Nextcloud entity): name,
address, email, phone, KvK, BTW number → an NC addressbook contact (in the
administration's shared addressbook). Payment terms, credit limit, default
ledger accounts → `CustomerMaster` / supplier master rows referencing the
contact. Dedupe key order: KvK number, BTW number, email; fallback
always-create + "possible duplicates" report section. Reversal never
deletes contacts (they may have been enriched since import) — it reports
them instead.

### D7 — Pipeline is the ADR-031 exception path, and stays out of the books

`AuditfileParser` (stream-based XMLReader, XXE-safe, deterministic) and
`ImportPipelineService` (validate / dry-run / post / reverse) are PHP — but
they contain zero bookkeeping rules. Posting composes existing surfaces:
journal entry create, `ARInvoice`/AP object create (via the real OR
ObjectService API — find/findAll/saveObject/createObject/updateObject/
deleteObject), contacts API. The pipeline runs under OR's background
machinery (a multi-100MB parse never lives inside one HTTP request), and
every step transitions the batch lifecycle so progress is observable and
resumable.

### D8 — Idempotency and reversal are batch-scoped

Each batch carries an `idempotencyKey` (hash of source file + scope +
administration). Posting an already-posted key is a no-op. Reversal (only
while the target period is open) posts the reversing journal, soft-deletes
the imported open items and masters, marks the batch `reversed`, and the
operator can re-import cleanly. After period close, normal correction
practice applies.

### D9 — Dry-run is a first-class lifecycle state, not a flag

`dry_run_complete` sits between validation and posting: the operator sees
the exact would-be opening journal, open-item lists, and contact list as a
persisted report on the batch before anything is written. This is the
accountant-facing trust feature — the report doubles as the migration
verification document for the dossier.

### D10 — i18n with ENGLISH source keys

All wizard and report strings use English source keys —
`t('shillinq', 'Opening balance is not balanced')` → nl
`'Openingsbalans is niet in evenwicht'` — with `nl` translations in the
same commit; notification subjects in both `nl` and `en`, metadata-only.
