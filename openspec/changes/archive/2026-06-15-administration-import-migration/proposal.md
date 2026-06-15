# Proposal: administration-import-migration

`kind: config + integration` per ADR-032/ADR-037 — two new register schemas
(`ImportBatch`, `ImportMapping`) with lifecycle and notification rules in an
ADR-037 register fragment, plus one ADR-031 exception-path PHP unit: a
deterministic, fail-closed XAF/CSV import pipeline (parse → stage → validate
→ dry-run → post). No app-local bookkeeping logic — posting goes through the
existing journal/GL/AR/AP surfaces.

## Summary

Introduce **administration onboarding by import**: a guided migration that
brings an existing Dutch administration into Shillinq from the standard
**XML Auditfile Financieel (XAF 3.2)** and from the package-specific exports
of the four dominant NL packages (**e-Boekhouden, Exact Online, Moneybird,
SnelStart**). The import covers the four artifacts every switcher needs on
day one:

1. **Chart of accounts** — source ledger accounts mapped onto the Shillinq
   chart of accounts with RGS-based auto-suggestions.
2. **Opening balances** — one balanced opening journal per administration.
3. **Open items** — outstanding AR and AP invoices with original numbers,
   dates, and due dates, so dunning and payment matching keep working.
4. **Relations** — customers/suppliers into the **NC addressbook** (identity)
   plus the existing `CustomerMaster` / supplier financial masters (terms,
   limits) — no new party schema.

This closes EXPECTED-GAP 1 of the 2026-06-11 feature re-evaluation: XAF
**export** is specified (`bookkeeping-multi-administratie` REQ-MA-007), but
import is not, anywhere — and "can I get my administration in?" is the first
question every prospective user asks. Without it, adoption friction is
maximal: re-keying a chart of accounts, opening balance, and open-item
ledgers by hand is days of error-prone work that no ZZP'er or SMB will do.

**Depends on:**
- `bookkeeping-multi-administratie` (administratie model + XAF format
  knowledge already owned there for export)
- `bookkeeping-chart-of-accounts` (target account model, RGS codes)
- `bookkeeping-general-ledger` / `bookkeeping-journal-entries` (opening
  journal posting surface)
- `bookkeeping-accounts-receivable-core` / `bookkeeping-accounts-payable-core`
  (open-item target schemas `ARInvoice` / AP invoice + `CustomerMaster`)
- `bookkeeping-period-close` (imports land in an open period; prior periods
  stay closed)
- `shillinq-notifications` (ADR-031 notification rule conventions)

## Motivation

Every Dutch business that adopts Shillinq is migrating *from* something —
e-Boekhouden (~200k administrations), Moneybird, Exact Online, or SnelStart.
All four can produce an XAF auditfile (it is a Belastingdienst-standardized
format precisely so administrations are portable), and all four export
relation/open-item CSVs. A bookkeeping package without an import path is a
bookkeeping package for new enterprises only.

The asymmetry is also strategically embarrassing: Shillinq promises XAF
*export* ("no lock-in, your accountant gets an auditfile") while offering no
XAF *import* ("but we won't read the one your current package gives you").
Closing the loop makes XAF the symmetric on/off ramp.

## Affected Projects

- [x] Project: shillinq — two new register schemas in an ADR-037 fragment,
  import lifecycle, notification rules, the import pipeline service (ADR-031
  exception path), manifest wizard pages.
- [ ] Project: openregister — consumer only (object surface, lifecycle,
  notifications, scheduled machinery); no OR changes required.
- [ ] Project: openconnector — not involved; the import reads files from NC
  Files, no external API calls.

## Scope

### In Scope

- **`ImportBatch` schema** in the ADR-037 fragment
  `lib/Settings/register.d/administration-import-migration.json`: target
  administration FK, `sourceSystem` (xaf-generic, e-boekhouden,
  exact-online, moneybird, snelstart), `sourceFile` (NC Files reference —
  link, don't store), import scope flags (chartOfAccounts, openingBalance,
  openItems, relations), staged counts, validation report, dry-run report,
  posting references, idempotency key, lifecycle status.
- **`ImportMapping` schema**: per-batch account-mapping rows (source account
  code/name → target Shillinq GL account), `mappingSource`
  (rgs-auto / profile-default / manual / unmapped), reusable as a saved
  mapping profile for repeat imports.
- **XAF 3.2 parser** (ADR-031 exception path, deterministic, fail-closed):
  extracts company data, ledger accounts, relations, journals, and
  open-item information from the auditfile into staged rows. Stream-parsed
  (XMLReader) for large files; XXE-safe.
- **Package import profiles** for e-Boekhouden, Exact Online, Moneybird,
  SnelStart: each profile = the package's XAF dialect quirks + CSV column
  mappings for the artifacts that package does not put in its XAF (notably
  open items and relation details).
- **Account mapping with RGS auto-suggestion**: source accounts carrying an
  RGS code (Referentie Grootboekschema) auto-map to the RGS-coded Shillinq
  chart; everything else is suggested by code/name similarity and confirmed
  by the operator. Unmapped accounts block posting (fail-closed).
- **Opening balance posting**: one balanced opening journal per
  administration at the migration date, via the existing journal-entry
  surface; AR/AP control account totals must equal the imported open items.
- **Open-items import**: outstanding `ARInvoice` / AP invoice records with
  original invoice numbers, dates, due dates, and outstanding amounts,
  entering their existing lifecycles in the correct state (issued/overdue by
  due date), explicitly excluded from invoice-number sequence generation.
- **Relations import**: identity → **NC addressbook contacts**
  (name, email, phone, address, KvK/BTW number); financial master data →
  `CustomerMaster` / supplier master rows referencing the contact; dedupe by
  KvK number / BTW number / email.
- **Validation, dry-run, report, reversal**: structural + referential +
  balance validation; a dry-run that produces the full would-be result
  without posting; an import report persisted on the batch; one-action
  reversal of a posted batch while the period is still open.
- **Lifecycle + notifications** (ADR-031 dialect): scheduled-free,
  state-driven rules — import validated / failed / posted / reversed.
- **Frontend** (ADR-037 manifest fragment): import wizard (upload → profile
  → mapping review → validation → dry-run → post), batch list, batch detail
  with report, mapping review grid.
- **i18n**: ENGLISH source keys, `nl` + `en` catalogs.

### Out of Scope

- **Full historical journal migration** (multi-year transaction history) —
  v2. Dutch practice for package switches is opening balance + open items at
  a period boundary; history stays available in the XAF archive of the old
  package (and can be attached to the administration as an NC Files
  document).
- **Live API connections to the source packages** (e.g., Exact Online
  OAuth) — would be an OpenConnector adapter; file-based import first.
- **Bank statement import** — owned by `bookkeeping-bank-reconciliation`
  (CSV/OFX) and `bookkeeping-bank-connectors`.
- **Inventory, fixed-asset, payroll, and time-entry migration** — owned by
  their own capabilities; out of the day-one migration path.
- **XAF export** — already owned by `bookkeeping-multi-administratie`
  REQ-MA-007; this change must not duplicate it.

## Approach

Staged, fail-closed pipeline expressed as the `ImportBatch` lifecycle:

`draft → parsing → staged → mapping → validated | validation_failed →
dry_run_complete → posting → posted | posting_failed → reversed`

1. Operator uploads the export file(s) to **NC Files** and creates an
   `ImportBatch` referencing them (link, don't store).
2. The parser stages everything into the batch (counts + staged payloads);
   nothing touches the books.
3. The operator reviews `ImportMapping` rows (RGS auto-mapped rows
   pre-confirmed, the rest suggested); unmapped rows block progress.
4. Validation proves: balanced opening balance, open-item totals equal to
   control-account balances, valid VAT codes, no duplicate relations, target
   period open.
5. Dry-run renders the exact would-be journal, open-item list, and contact
   list as a report.
6. Posting executes through the existing surfaces (journal entries, AR/AP
   objects, NC contacts API) under the batch's idempotency key; re-running a
   posted batch is a no-op.
7. Reversal (while the period is open) reverses the opening journal,
   soft-deletes the imported open items/masters, and leaves NC contacts in
   place (flagged in the report — contacts may have been enriched since).

Specs: one spec file `administration-import-migration` with REQ-AIM-001 …
REQ-AIM-010.

## New Dependencies

None. XAF parsing uses PHP's XMLReader (core, XXE-safe by default on
PHP 8 + libxml ≥ 2.9); CSV parsing uses league/csv only if it is already a
dependency, else native. NC Files and NC contacts are core surfaces.

## Impact

- `lib/Settings/register.d/administration-import-migration.json` — NEW
  ADR-037 register fragment: `ImportBatch` + `ImportMapping` schemas,
  lifecycle, notification rules.
- `lib/Service/Import/AuditfileParser.php` — NEW XAF 3.2 stream parser
  (deterministic transform, no posting).
- `lib/Service/Import/ImportProfile/*.php` — NEW per-package profiles
  (e-Boekhouden, Exact Online, Moneybird, SnelStart) implementing one
  profile interface.
- `lib/Service/Import/ImportPipelineService.php` — NEW staged pipeline
  (validate / dry-run / post / reverse), posting only through existing
  journal / AR / AP / contacts surfaces, idempotent, fail-closed.
- `src/manifest.d/administration-import-migration.json` — NEW ADR-037
  manifest fragment: import wizard + batch list/detail + mapping grid.
- `l10n/en.json`, `l10n/nl.json` — new keys (ENGLISH source strings).
- `tests/Unit/Service/Import/*` — parser fixtures (one XAF fixture per
  package), pipeline state machine, balance validation, idempotency.
- `tests/e2e/` — wizard UI specs (gate-19); Newman collection for the batch
  object surface.

## Cross-Project Dependencies

- **bookkeeping-multi-administratie** — format symmetry: the XAF written by
  REQ-MA-007 MUST round-trip through this importer (export → import →
  identical trial balance). Shared XAF field knowledge should live in one
  place.
- **bookkeeping-accounts-receivable-core / -payable-core** — target schemas
  for open items and masters; imported open items enter the existing
  lifecycles and dunning works on them unchanged.
- **bookkeeping-quote-order-invoice** — soft. Imported AR open items keep
  their ORIGINAL source-package numbers and MUST NOT consume or collide with
  the no-gap invoice number sequence (REQ-QOI-007).
- **bookkeeping-period-close** — posting requires the target period open;
  reversal is blocked once the period closes.
- **bookkeeping-vat-btw-filing** — imported opening BTW positions land on
  the BTW control accounts so the first VAT return after migration is
  correct.

## Risks

### Risk 1: Source-package XAF dialects diverge from the standard

**Severity**: High
**Mitigation**: per-package import profiles encapsulate dialect quirks
behind one interface; the generic `xaf-generic` profile handles standard
XAF 3.2; each profile ships with a real-world fixture file in the test
suite. Unknown constructs fail validation loudly (fail-closed), never
silently skip rows.

### Risk 2: Unbalanced or double-counted opening position

**Severity**: High
**Mitigation**: validation hard-blocks posting unless (a) the opening
journal balances to zero and (b) the AR/AP control-account opening amounts
exactly equal the sum of imported open items (the classic double-count trap
when both the balance and the open items are imported). Both checks are
spec-level scenarios.

### Risk 3: Re-running an import duplicates data

**Severity**: Medium
**Mitigation**: every batch carries an idempotency key; posting is a no-op
if the key was already posted; relation dedupe by KvK/BTW/email; the
reversal action is the only way to undo, then re-import cleanly.

### Risk 4: Large auditfiles (multi-100MB) exhaust memory or request time

**Severity**: Medium
**Mitigation**: stream parsing (XMLReader, never DOM-load), staged
processing in chunks, and parse/post executed via OR's background machinery
rather than inside one HTTP request; the batch lifecycle makes progress
observable.

## Rollback Strategy

**During implementation (before merge):** revert the implementing PR.

**Post-merge, before adoption:** the register fragment, manifest fragment,
and `lib/Service/Import/` namespace are self-contained; removing them
removes the capability without touching any bookkeeping register.

**Production, per batch:** the batch reversal action (REQ-AIM-009) reverses
the opening journal and soft-deletes imported open items/masters while the
period is open. After period close, correction follows normal bookkeeping
practice (correction journal), as with any posted journal.

## Open Questions

1. **Historical journals in v1.5** — importing the full transaction history
   from the XAF is technically the same parser; do we gate it behind a
   feature flag once opening-balance migration is proven? Leaning yes,
   deferred.
2. **Saved mapping profiles** — share account-mapping profiles across
   administrations of the same accountant (one accountant migrates 50
   clients)? The `ImportMapping` schema supports it; UI exposure decided at
   design review.
3. **Relations without KvK/BTW/email** — dedupe falls back to exact name
   match or always-create? Spec assumes always-create with a post-import
   "possible duplicates" report section; confirm with ops.
