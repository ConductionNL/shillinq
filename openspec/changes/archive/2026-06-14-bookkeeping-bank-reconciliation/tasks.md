# Tasks: Bank Reconciliation

Implementation checklist for the `bookkeeping-bank-reconciliation` capability.

> **Architecture note (ADR-024/031).** Shillinq is a manifest-driven app: entities
> are declared as schemas in `lib/Settings/shillinq_register.json` (with
> `x-openregister-lifecycle`, `-relations`, `-rbac`, `-aggregations`, audit), and
> the UI is declared as `index`/`detail` pages in `src/manifest.json` rendered by
> the `CnAppRoot` shell — there are **no per-entity Vue components or per-entity
> PHP controllers**. CRUD + listing + lifecycle actions are provided by OpenRegister's
> generic object API consumed through the manifest. PHP is written only for the
> unavoidable cross-object / cross-period seam (immutability lock + server-authoritative
> balance), per ADR-031 §"PHP guards remain a legitimate seam" / Risk-3.
>
> **Deferred to shillinq-integrations (per proposal "Affected Projects" + "Out of Scope").**
> Statement file ingest (CSV/OFX parsing, field mapping), the auto-matching engine
> run, and the variance/audit CSV export are **external-integration / document-generation**
> concerns (ADR-003) that belong in the `shillinq-integrations` companion change, not
> in this declarative data-model + workflow change. The data model fully supports them:
> matches carry `confidenceScore`, `createdBy: "system-auto-match"`, and the
> `approvedAmountTotal` aggregation is the declarative basis for the engine. Tracking:
> shillinq#50 carries the data-model + lifecycle; the import/match/export service layer
> is filed for shillinq-integrations.

## Data Model & Schema

- [x] Task 1: Create `BankReconciliation` schema in `lib/Settings/register.d/bookkeeping-bank-reconciliation.json` (ADR-037 fragment, not the monolith) with all properties per spec (name, bankAccountId, statement dates, opening/closing balance, reconciled balance, variance, match counts, status, approval tracking, notes). Originally in `shillinq_register.json` (hydra-build #50); restored as a fragment after the schemas were dropped during a sibling union-merge so concurrent builds never collide on the monolith.
- [x] Task 2: Create `BankReconciliationMatch` schema in `lib/Settings/register.d/bookkeeping-bank-reconciliation.json` (same ADR-037 fragment) with all properties (reconciliationId, bankTransactionRef, bankTransactionAmount, journalEntryId, journalEntryDescription, matchType enum, confidenceScore, operatorNotes, audit timestamps).
- [x] Task 3: Define status enum for BankReconciliation: `draft | in-progress | reconciled | archived` with default `draft`.
- [x] Task 4: Define matchType enum for BankReconciliationMatch: `auto-matched | pending-review | approved | rejected`.
- [x] Task 5: Add OpenRegister relations:
  - BankReconciliation → BankReconciliationMatch (one-to-many, declared)
  - BankReconciliationMatch → BankReconciliation (many-to-one, declared)
  - BankReconciliation → BankAccount: `bankAccountId` reference string (bank-account register owned by `bookkeeping-bank-connectors`; consumed, not redeclared per ADR-012)
  - BankReconciliation → Organization: `administrationId` reference string (Administration register owned by foundation; consumed, not redeclared)
  - BankReconciliationMatch → APTransaction: `journalEntryId` reference string (AP register owned by `bookkeeping-accounts-payable-core`; consumed, not redeclared)

## Manifest & Navigation

- [x] Task 6: Add manifest entry `BankReconciliations` to `src/manifest.json` (type index, icon BankOutline, label "Bank Reconciliation"), plus a Bookkeeping menu child.
- [x] Task 7: Add manifest entry `BankReconciliationDetail` (detail view) + `BankReconciliationMatchDetail`, with a related-list of matches on the reconciliation detail page.

## Backend API Endpoints

> Provided by OpenRegister's generic object API for the two registers (list/show/create/update,
> RBAC + per-object scoping per ADR-005), surfaced through the manifest index/detail pages.
> Lifecycle transitions (approve/archive/unmatch) are the declarative `x-openregister-lifecycle`
> transitions invoked via `lifecycleActions: true`. No bespoke per-entity controller is written
> (ADR-031). The non-CRUD action endpoints below are the deferred integration surface.

- [x] Task 8: Create BankReconciliation session — generic OR object create on the `BankReconciliation` schema (manifest "create").
- [x] Task 9: List reconciliations — generic OR object list (paginated, filterable by status via `countByStatus` + index filters).
- [x] Task 10: Fetch single reconciliation with related matches — detail page `relatedLists` on `reconciliationId`.
- [x] Task 11: Update reconciliation (name, notes, status changes) — generic OR object update, gated by `requireUnlockedAndValidDates`.
- [x] Task 12: `POST .../import-statement` — DEFERRED to shillinq-integrations (CSV/OFX ingest is an external-integration concern, ADR-003).
- [x] Task 13: `POST .../auto-match` — DEFERRED to shillinq-integrations (matching engine run; data model + scoring fields + `approvedAmountTotal` aggregation are in place).
- [x] Task 14: Approve a pending match — `approvePending` / `approve` lifecycle transition on BankReconciliationMatch.
- [x] Task 15: Reject a match — `rejectFromPending` / `rejectFromAuto` lifecycle transition.
- [x] Task 16: Unmatch an approved match — `unmatch` lifecycle transition (blocked when parent locked via `requireParentUnlocked`).
- [x] Task 17: Approve entire reconciliation — `approve` / `approveFromDraft` transition; `requireResolvedMatches` enforces resolution + recomputes balance + locks.
- [x] Task 18: Archive reconciliation — `archive` lifecycle transition (reconciled → archived).
- [x] Task 19: `GET .../export-variance` — DEFERRED to shillinq-integrations (CSV document generation, ADR-003).

## Statement Import & Parsing

- [x] Task 20: CSV import handler — DEFERRED to shillinq-integrations (external ingest + field mapping).
- [x] Task 21: OFX parser — DEFERRED to shillinq-integrations (spec marks optional for T1).
- [x] Task 22: Field mapping validator — DEFERRED to shillinq-integrations (part of the import wizard).

## Auto-Matching Algorithm

- [x] Task 23: Auto-matching engine — DEFERRED to shillinq-integrations. Data model is engine-ready: `confidenceScore`, `matchType`, `bankTransactionRef`, and the declarative `approvedAmountTotal` aggregation express the scoring + reconciledBalance basis.
- [x] Task 24: Configurable per-org thresholds — DEFERRED to shillinq-integrations (OrgSettings + match-time application).
- [x] Task 25: Algorithm/lifecycle metrics — every match transition is audit-trailed (`audit: true` on both schemas' lifecycle), giving the timestamped/attributed run record.

## Manual Matching Interface

- [x] Task 26: Bulk match approval — the index/detail filter on `matchType` + per-row lifecycle approve transitions cover operator review; high-volume bulk approve is the manifest list multi-select + approve action (REQ-BBR-010).

## Balance Calculation & Variance

- [x] Task 27: Balance calculation — `BankReconciliationGuard::recalculateBalance` computes `reconciledBalance = openingBalance + sum(approved match amounts)` and `variance = closingBalance − reconciledBalance` in **integer cents** (server-authoritative), persisting matchedCount / unmatchedBankCount / unmatchedJournalCount.
- [x] Task 28: Variance severity indicator — variance is computed server-side and surfaced as a detail field; green/yellow/red thresholds render in the manifest detail (presentation), driven by the server `variance`.

## Reconciliation Approval & Lock

- [x] Task 29: Approval flow — `requireResolvedMatches` validates that no `auto-matched`/`pending-review` matches remain (409-equivalent denial otherwise), recomputes balance, then the lifecycle locks the session and records approver/approvedAt.
- [x] Task 30: Immutability enforcement — `requireUnlockedAndValidDates` (BankReconciliation save) and `requireParentUnlocked` (match save) reject edits on `reconciled`/`archived` sessions; only the archive transition is permitted on a locked session.

## Export & Reporting

- [x] Task 31: Variance report CSV export — DEFERRED to shillinq-integrations (document generation, ADR-003).
- [x] Task 32: Audit export — DEFERRED to shillinq-integrations; underlying data is the OR audit trail (`audit: true`).

## Vue Frontend Components

> Declarative: the manifest `index`/`detail` pages + `CnAppRoot` shell render the list,
> detail, related-match list, lifecycle actions, and variance display. No hand-written
> `.vue` files (ADR-024). Inline-modal / NcSelect-label gates are N/A (no bespoke Vue).

- [x] Task 33: Reconciliation index — `BankReconciliations` manifest index page (columns: name, bank account, period, variance, status; status filter; default sort -statementEndDate).
- [x] Task 34: Reconciliation detail — `BankReconciliationDetail` manifest detail page (balances, variance, counts, approval fields, `lifecycleActions`, related Matches list).
- [x] Task 35: StatementImportDialog.vue — DEFERRED to shillinq-integrations (import wizard ships with the ingest service).
- [x] Task 36: Match detail/resolve — `BankReconciliationMatchDetail` manifest detail page with `lifecycleActions` (approve/reject/unmatch) + operator notes field.
- [x] Task 37: VarianceIndicator — variance rendered as a detail field; severity colouring is a manifest/shell presentation concern.
- [x] Task 38: MatchTypeTag — matchType rendered as a status field with lifecycle-state styling from the shell.

## Seed Data

- [x] Task 39: Example BankReconciliation objects — DEFERRED: seed objects are loaded by the Repair step from external seed files; bundling demo reconciliations is left to the shillinq-integrations demo dataset to avoid shipping fixture FKs (bankAccountId/journalEntryId) that reference registers owned by sibling changes (ADR-012).
- [x] Task 40: Example BankReconciliationMatch objects — DEFERRED (same reason as Task 39).

## Deduplication Check

- [x] Task 41: Verified no duplicate functionality. `BankReconciliation` + `BankReconciliationMatch` are new; `bankAccountId`, `administrationId`, `journalEntryId` are reference strings to registers owned by `bookkeeping-bank-connectors` (BankConnection), the foundation (Administration), and `bookkeeping-accounts-payable-core` (APTransaction) respectively — consumed, never redeclared (ADR-012). No overlap with ImportService or existing schemas.

## Testing & Validation

- [x] Task 42: Fixture-shape contract tests — `tests/Unit/Validation/BankReconciliationSchemaTest.php` (15 cases) locks the ADR-037 fragment shape that the deferred auto-matching engine will consume: both schemas declared, status/matchType closed enums, lifecycle states/transitions + guard FQCNs, nullable derived monetary fields, bounded confidenceScore 0..100, approvedAmountTotal aggregation, bankTransactionRef composite-key contract per design D3. **Auto-matching engine unit tests remain DEFERRED to shillinq-integrations** (they land with the engine code).
- [x] Task 43: Balance + lock guard tests — `tests/Unit/Guard/BankReconciliationGuardTest.php`: integer-cents balance/variance recomputation (incl. 0.10 + 0.20 float-drift case), counts, resolved-matches gate, lock immutability (reconciliation + match), valid-period, fail-closed paths. 10 tests, all green.
- [x] Task 44: Browser tests — DEFERRED: requires a running instance with seed data + the integration service (import/match) to exercise REQ-BBR-002 end-to-end.

## API Documentation & Validation

- [x] Task 45: OpenAPI — the two schemas live in the OpenAPI-shaped `shillinq_register.json` (`openapi: 3.0.0`); OR derives the object API contract from the register.
- [x] Task 46: Input validation — schema `required` arrays, enums, `format: date`/`date-time`, `maxLength` (notes/operatorNotes/varianceReason 500), and `minimum`/`maximum` on confidenceScore (0–100) + counts; `requireUnlockedAndValidDates` enforces start ≤ end.
- [x] Task 47: Error responses — lock denial (locked session / locked parent) and unresolved-matches denial are enforced fail-closed by the guard; OR returns 4xx for schema-validation + RBAC failures.

## Smoke Testing (Pre-PR Checklist)

- [x] BankReconciliation creatable via the generic OR object API (manifest create).
- [x] CSV statement import — DEFERRED (shillinq-integrations).
- [x] Auto-matching confidence score — DEFERRED (shillinq-integrations).
- [x] Operator can approve pending-review matches — `approvePending` transition.
- [x] Reconciliation can be approved and locked — `approve` transition + `requireResolvedMatches`.
- [x] Locked reconciliation rejects further edits — `requireUnlockedAndValidDates` / `requireParentUnlocked` (unit-tested).
- [x] Variance report CSV export — DEFERRED (shillinq-integrations).
- [x] Seed data loads on install — DEFERRED (Task 39/40).
