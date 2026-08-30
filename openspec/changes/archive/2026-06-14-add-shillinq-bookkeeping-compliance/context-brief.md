# Proposal: add-shillinq-bookkeeping-compliance

## Summary

Introduce **Tier 2 of the Shillinq bookkeeping rollout** — the
compliance + operational surface that sits on top of the T1 foundation
(`add-shillinq-bookkeeping-foundation` — see
`../add-shillinq-bookkeeping-foundation/specs/bookkeeping-chart-of-accounts/spec.md`,
`../add-shillinq-bookkeeping-foundation/specs/bookkeeping-general-ledger/spec.md`,
`../add-shillinq-bookkeeping-foundation/specs/bookkeeping-journal-entries/spec.md`).
T2 adds **eight new declarative capabilities** — trial balance, period
close, accounts payable, accounts receivable, financial statements,
audit trail integration, document-attachment integration, and bank
reconciliation — all declared as OpenRegister registers + schemas
with `x-openregister-lifecycle` / `x-openregister-aggregations` rules
(per ADR-031), wired into `src/manifest.json` (per ADR-024), and
consuming OpenRegister's audit, RBAC, approval-workflow, and
dunning-workflow abstractions instead of reimplementing them (per
ADR-022). No PHP report-builder, no PHP state-machine code, no
parallel audit log, no app-local approval tables, no app-local
attachment storage — the entire compliance surface lands as register
metadata + manifest entries + thin sub-ledger registers.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

T1 delivered a balanced general ledger but is, by design, unusable
without the surface that bookkeepers actually operate against:

- A **trial balance** is the first thing any accountant opens to
  verify the books. Without it T1's GL is invisible.
- A **period close** turns a running ledger into auditable financial
  history; without it postings can be backdated and audits fail.
- **Accounts payable and receivable** are how 99% of postings land in
  practice; manual journals are the exception. Shillinq's original
  scope (customer invoicing) is the AR half; AP completes it.
- **Financial statements** (Balance Sheet, P&L, Cash Flow) are the
  legally-required output for SMB administrations under RJ 270 and
  the IFRS for SMEs (NL/EU) — and for government administrations
  under BBV (deferred to a dedicated T3-bbv-compliance spec).
- A **wired-through audit trail** is required for AVG/Woo/Archiefwet
  compliance — and per ADR-022 it MUST come from OpenRegister, not
  from an app-local table.
- **Source-document attachment** (the original invoice PDF, the bank
  statement, the receipt) is required for the
  Belastingdienst-mandated 7-year retention. Per ADR-022 this MUST
  consume docudesk; shillinq carries the FK only.
- **Bank reconciliation** is the daily-operations loop that connects
  external reality (the bank statement) to the internal ledger. The
  legacy AP/AR draft cluster from intelligence-db (`competitor_features`
  with `app_slug=shillinq`) calls it out as a top-3 customer-asked
  capability.

T2 is the **compliance + operations envelope**: with T2 merged, a
Dutch SMB bookkeeper can perform an end-to-end month in shillinq.
Without it, T1 is shelfware.

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown. This change delivers **Tier 2**:
`add-shillinq-bookkeeping-compliance`.

The eight T2 capabilities are intentionally each their own spec
(per ADR-032 spec sizing — each capability is `kind: config`
declarative and fits cleanly in a single config envelope). They
chain via `depends_on` to keep the merge sequence safe.

## Affected Projects

- [x] Project: shillinq — adds 8 capability specs, declares ~10
  additional registers/schemas in
  `lib/Settings/shillinq_register.json` (vendor master, customer
  master, AP invoice, AR invoice, payment run, dunning record, bank
  statement, bank statement line, fiscal period, reconciliation
  match), declares 8 additional manifest navigation entries in
  `src/manifest.json`, ships nothing into `lib/Settings/seeds/`
  (operational data accumulates through use).
- [ ] Project: openregister — no source changes; T2 consumes
  existing OR abstractions (audit, RBAC, approval-workflow,
  dunning-workflow, aggregations, lifecycle, attachments). If a
  needed extension is missing (e.g. dunning workflow is still draft
  in OR) the gap is filed as an OR issue and the relevant spec
  annotates it under "Declarative-vs-imperative decision".
- [ ] Project: docudesk — no source changes; AP and AR invoices,
  bank statements, and journal source documents reference docudesk
  attachments by foreign-key URI per ADR-022. The
  `bookkeeping-document-attachment-integration` capability defines
  the contract.

## Scope

### In Scope

- Eight new capability specs (one folder each under `specs/`):
  - `bookkeeping-trial-balance` — period trial balance via
    `x-openregister-aggregations`, drill-through to GL.
  - `bookkeeping-period-close` — monthly/quarterly close via OR
    lifecycle state machine on a new `FiscalPeriod` register;
    prevents backdating + audit-locks once approved.
  - `bookkeeping-accounts-payable-core` — vendor invoice intake, 3-way match
    (PO / GR / invoice), approval routing via OR's approval-workflow
    (ADR-022, NOT reimplemented), SEPA pain.001 + iDEAL payment
    runs, aging report, vendor master register.
  - `bookkeeping-accounts-receivable-core` — customer invoicing (carries forward
    the original shillinq invoicing scope), dunning workflow via OR
    (ADR-022), AR aging, payment matching against bank lines from
    `bookkeeping-bank-reconciliation`, customer master register.
  - `bookkeeping-financial-statements` — Balance Sheet, P&L, Cash
    Flow assembled via OR aggregation + a presentation manifest.
    Year-over-year comparatives, drill-through. XBRL/PDF export. For
    SMB administrations only; BBV (government) is T3.
  - `bookkeeping-audit-trail` — wires OR's audit-trail-immutable
    abstraction into all bookkeeping registers per ADR-022; UI
    surface in shillinq to query the OR audit log filtered to
    bookkeeping objects.
  - `bookkeeping-document-attachment-integration` — defines the FK
    contract for attaching source documents via docudesk; documents
    mime-type expectations and the failure mode when docudesk is
    unavailable.
  - `bookkeeping-bank-reconciliation` — bank statement import
    (CAMT.053 + MT940 + manual upload), rule-based matching against
    AR/AP, suspense account handling, reconciliation lifecycle.
- Eight manifest navigation entries (Trial Balance, Period Close,
  AP, AR, Financial Statements, Audit Trail, Bank Reconciliation;
  document-attachment-integration ships as a side panel on existing
  pages, no top-level nav).
- Sub-ledger registers (vendor master, customer master, AP invoice,
  AR invoice, payment run, dunning record, bank statement, bank
  statement line, reconciliation match, fiscal period) declared per
  capability spec.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle on the
  spec.
- **Year-end close** — only monthly/quarterly close is in T2.
  Year-end is a separate concern (opening-balance journal generation,
  retained-earnings rollover) and ships in T3.
- **UBL 2.1 / Peppol BIS 3.0 e-invoicing outbound** — referenced
  from the AR spec as a dependency, but the actual outbound is T4.
- **PSD2 live-feed bank connectors** — bank reconciliation in T2
  supports CAMT.053 + MT940 + manual upload; live-feed connectors
  are T4.
- **VAT/BTW posting automation, KOR, ICP, OSS** — T3.
- **BBV (government) financial statements** — T3.
- **Multi-currency translation, FX revaluation, CTA postings** — T5.
- **Intercompany, group consolidation, segment reporting** — T5.
- **Bespoke Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` / `CnReportPage` from `@conduction/nextcloud-vue`
  already render generically. The bookkeeping-financial-statements capability
  may require a thin presentation manifest in v1 (documented
  per-spec); if so, the spec calls it out under
  "Declarative-vs-imperative decision".

## Approach

Eight ADDED-Requirements deltas, one per capability:

1. **`bookkeeping-trial-balance`** (depends: T1
   `bookkeeping-general-ledger`) — declares the trial-balance output
   as an `x-openregister-aggregations` query grouping `GLLine` by
   `(period_id, account_number, side)` with opening / movement /
   closing buckets. No PHP report builder. The
   debit-credit-balance-verifies invariant is declared as a schema
   invariant per ADR-031.
2. **`bookkeeping-period-close`** (depends: T2 trial-balance) —
   declares the `FiscalPeriod` register (the FK that T1's GL lines
   were stubbing as string) with an
   `open → closing → closed → audit-locked` lifecycle. Postings
   against a closed period are rejected by an OR lifecycle
   precondition.
3. **`bookkeeping-accounts-payable-core`** (depends: T1 GL) — declares
   `VendorMaster`, `APInvoice`, `PaymentRun` registers, the 3-way
   match lifecycle on `APInvoice`, and the SEPA pain.001 +
   iDEAL payment run. Approval routing consumed from OR per ADR-022.
4. **`bookkeeping-accounts-receivable-core`** (depends: T1 GL) — declares
   `CustomerMaster`, `ARInvoice`, `DunningRecord` registers. The
   dunning workflow is consumed from OR. AR aging is an
   `x-openregister-aggregations` query. Payment matching depends on
   T2 bank-reconciliation.
5. **`bookkeeping-financial-statements`** (depends: T1 GL + T2
   trial-balance) — declares Balance Sheet, P&L, and Cash Flow
   Statement outputs as compositions of trial-balance aggregations
   keyed against a presentation manifest (RJ 270 / IFRS for SMEs
   for v1). XBRL/PDF export.
6. **`bookkeeping-audit-trail`** (no code dependency) — confirms
   every bookkeeping register declares `x-openregister-audit: true`,
   adds a manifest entry pointing at OR's audit-log surface
   pre-filtered to bookkeeping objects. ~5 REQs.
7. **`bookkeeping-document-attachment-integration`** (consumes
   docudesk) — defines the FK contract for source-document linkage,
   the expected mime-types per attachment role (invoice / receipt /
   statement / contract), and the failure mode when docudesk is
   unavailable. ~5 REQs.
8. **`bookkeeping-bank-reconciliation`** (depends: T1 GL) —
   declares `BankStatement`, `BankStatementLine`, `ReconciliationMatch`
   registers; supports CAMT.053 + MT940 + manual import; matching
   rules declared per ADR-031 as schema metadata.

All eight specs follow the conduction-schema format (RFC 2119,
`### REQ-{Abbrev}-NNN: <name>`, `#### Scenario:` with exactly four
hashtags, GIVEN/WHEN/THEN). Each requirement is prefixed per
capability for traceability (`REQ-TB-*` trial-balance, `REQ-PC-*`
period-close, `REQ-AP-*`, `REQ-AR-*`, `REQ-FS-*`, `REQ-AT-*`
audit-trail, `REQ-DA-*` document-attachment, `REQ-BR-*`
bank-reconciliation).

## New Dependencies

None. T2 consumes existing OpenRegister abstractions plus the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35` (from
`shillinq-manifest-tier4`). The dunning-workflow extension on OR is
referenced; if not yet stable, the AR spec annotates the gap and
the implementing cycle either waits or carries a single-method PHP
guard per ADR-031 §"PHP guards remain a legitimate seam".

## Impact

- `lib/Settings/shillinq_register.json` — adds ~10 schemas:
  `FiscalPeriod`, `VendorMaster`, `APInvoice`, `PaymentRun`,
  `CustomerMaster`, `ARInvoice`, `DunningRecord`, `BankStatement`,
  `BankStatementLine`, `ReconciliationMatch`. Declares
  `x-openregister-lifecycle` on each that needs a state machine;
  declares `x-openregister-aggregations` on Trial Balance, AR
  aging, AP aging, Cash Flow.
- `src/manifest.json` — adds ~7 navigation entries + their `type:
  index` + `type: detail` pairs. The bookkeeping-financial-statements capability
  may add a `type: report` page if its presentation manifest needs
  a dedicated renderer.
- Repair step (`lib/Migration/Version*.php` or repair class) —
  extends the existing register-import pattern; no new seeds in T2.
- No new PHP services (subject to ADR-031 exception path — at most
  3 thin lifecycle guards across all 8 specs, each single-method,
  ~20 LOC; flagged per-spec under "Declarative-vs-imperative
  decision" in each design block).
- No new bespoke Vue components.

## Cross-Project Dependencies

- **OpenRegister** — depends on the following abstractions being
  stable: `x-openregister-lifecycle` (ADR-031),
  `x-openregister-aggregations` with multi-group + opening/closing
  buckets (ADR-031), audit-trail-immutable (ADR-022),
  approval-workflow (ADR-022), dunning-workflow (referenced — if
  draft, gap captured per spec). If a needed shape is missing, the
  gap is filed as an OR issue and the affected spec annotates
  under "Declarative-vs-imperative decision".
- **docudesk** — every source-document attachment is referenced by
  foreign-key URI per ADR-022. The
  `bookkeeping-document-attachment-integration` capability defines
  the contract; AP / AR / journal-entry / bank-statement specs
  consume it.
- **T1 foundation** — every T2 capability that touches the ledger
  references the T1 specs by file path (see each spec's "Depends
  on" header). T1 MUST be merged before T2 implementation; the
  spec-only change can land first because no runtime artefact is
  produced.

## Risks

### Risk 1: OR's aggregation engine cannot express opening/closing buckets in one query

**Severity**: Medium
**Mitigation**: The trial-balance and the bookkeeping-financial-statements
capabilities both need opening / movement / closing bucket
aggregations grouped by `(period_id, account_number)`. If the
engine cannot express the three buckets in one declarative pass,
each bucket is its own aggregation query and the presentation layer
composes them (still declarative, no PHP report builder). Document
the choice in `bookkeeping-trial-balance/spec.md` and
`bookkeeping-financial-statements/spec.md` under
"Declarative-vs-imperative decision" during `opsx-ff` discovery.

### Risk 2: Dunning workflow not yet stable on OR

**Severity**: Medium
**Mitigation**: If the dunning-workflow extension is still draft,
the AR spec captures the gap, files an OR issue, and the
implementing cycle MAY ship a single-method PHP guard
(`OCA\Shillinq\Lifecycle\DunningGuard`) per ADR-031 §"PHP guards
remain a legitimate seam". The guard is removed once OR's
extension lands. Spec is shape-neutral.

### Risk 3: Three-way match (PO / GR / invoice) requires schemas this T2 doesn't declare

**Severity**: Medium
**Mitigation**: The Purchase Order and Goods Receipt registers
belong to a future procurement capability (currently planned for
T4 alongside e-invoicing). T2's `bookkeeping-accounts-payable-core` spec
declares the 3-way match as REQUIRED-IF-PRESENT — if no PO/GR
register is available, the AP invoice posts with a 2-way match
(invoice + manual operator approval). The FK fields are declared
in T2 so a future PO/GR capability attaches without a destructive
migration.

### Risk 4: SEPA pain.001 + iDEAL payment initiation requires bank credentials

**Severity**: Low
**Mitigation**: T2 declares the payment-run register and the
pain.001 XML output as a downloadable artefact (operator uploads
to their bank portal). Direct bank initiation via PSD2 lives in T4
once live-feed connectors land. The spec is shape-neutral.

### Risk 5: Financial-statement presentation manifest may need bespoke renderer

**Severity**: Low-Medium
**Mitigation**: The Balance Sheet / P&L / Cash Flow layouts are
hierarchical templates (RJ 270 line items mapped to account ranges)
that the generic `CnIndexPage` can't render. Two paths: (a) a
dedicated `CnReportPage` component in `@conduction/nextcloud-vue`
takes a presentation manifest and renders any statement; (b) a
short bespoke Vue file per statement type. Path (a) preserves
ADR-024 Tier-4 and is preferred — option (b) is the fallback if
the renderer lands later than T2 implementation. Decision lives
in `bookkeeping-financial-statements/design.md` discovery.

### Risk 6: Backdating prevention vs operator correction workflow

**Severity**: Medium
**Mitigation**: Once a period is closed, ALL postings dated within
that period are rejected. Operators must reopen the period
(requires elevated role + audit-trailed reason) to post a
correction. This is industry-standard and matches Exact / AFAS /
Twinfield behaviour. The `bookkeeping-period-close` spec
declares the reopen workflow + role gate.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec.

After implementation (separate cycle), rollback follows the standard
pattern: revert the implementing PR, run the repair step in
down-direction. Registers are non-destructive — unused schemas
remain queryable but unreferenced; operational data (vendor
invoices, customer invoices, bank statements, fiscal periods)
remains in storage. No data migration risk at the spec stage. The
T2 capabilities chain via `depends_on`; partial rollback (e.g.
keeping AP but reverting bank-reconciliation) is supported because
the AP spec does not depend on bank-reconciliation.

## Open Questions

1. **Opening/closing bucket aggregation** — see Risk 1.
   `opsx-ff` discovery resolves whether one aggregation query or
   three composed queries is the right shape.
2. **Dunning-workflow stability on OR** — see Risk 2. The AR spec
   tracks this; the discovery step files the OR issue if needed.
3. **3-way match: PO/GR availability at T2 implementation time** —
   see Risk 3. The AP spec is shape-neutral.
4. **Financial-statement renderer path** — see Risk 5. Resolved in
   `bookkeeping-financial-statements/design.md` discovery.
5. **Audit-trail UI placement** — the
   `bookkeeping-audit-trail` capability surfaces a UI to query the
   OR audit log filtered to bookkeeping objects. Should that live
   under a dedicated `Bookkeeping > Audit` nav entry, or as a side
   panel on every bookkeeping detail page? Confirmed during the
   implementing cycle's UX review; the spec declares the
   capability and leaves placement to the manifest patch.



## Design

# Design — Bookkeeping Compliance + Operations (T2)

## Context

T1 (`add-shillinq-bookkeeping-foundation`) delivered the balanced
double-entry GL surface: chart of accounts (`Account`), GL
transactions + lines (`GLTransaction` + `GLLine`), and human-authored
journals (`JournalEntry`). What it explicitly deferred to T2 is the
compliance + operations envelope a bookkeeper actually uses every day.

T2 closes that gap with eight capabilities:

- **Compliance**: trial balance, period close, financial statements,
  audit trail, document attachment integration.
- **Operations**: accounts payable, accounts receivable, bank
  reconciliation.

Every T2 capability is declarative — schemas, lifecycle blocks,
aggregations, and manifest entries; no PHP report builders, no PHP
state-machine code outside the at-most-3 single-method lifecycle
guards permitted by ADR-031 §"PHP guards remain a legitimate seam".

The change is **spec-only**. Implementation lands later through
`opsx-apply` and the standard Hydra pipeline; this doc explains
*why* the shape is what it is.

## Goals

- Express the entire T2 surface as **declarative metadata** —
  schemas + `x-openregister-lifecycle` rules +
  `x-openregister-aggregations` queries + manifest entries — per
  ADR-031. No new PHP service classes for report assembly, state
  machines, approval routing, or dunning workflow.
- Consume every OpenRegister abstraction that already exists for
  audit trail, RBAC, approval workflow, dunning workflow,
  attachments — per ADR-022. No reimplementation in shillinq.
- Make the spec a **competent-bookkeeper readable contract** —
  a Dutch SMB accountant should recognise the model as a faithful
  monthly close + AP/AR + bank-rec + trial-balance flow,
  RJ 270 / IFRS-for-SMEs conformant for statements, with no
  surprises.
- Keep each T2 capability narrow enough to stand alone as a
  single-spec change; the eight specs chain via `depends_on` but
  none is mixed (per ADR-032 — all are `kind: config`).

## Non-Goals

- No VAT/BTW posting automation, KOR, ICP, OSS — T3.
- No BBV (government) financial statements — T3.
- No year-end close (opening-balance journal generation,
  retained-earnings rollover) — T3.
- No UBL 2.1 / Peppol BIS 3.0 e-invoicing outbound — T4.
- No PSD2 live-feed bank connectors — T4. T2 carries CAMT.053 +
  MT940 + manual upload import.
- No multi-currency translation, FX revaluation, CTA postings — T5.
- No intercompany eliminations, consolidation, segment reporting —
  T5.
- No PHP services for report assembly, dunning, approval routing,
  state machines, or attachment storage. (At most 3 single-method
  lifecycle guards across all 8 specs — each gated by ADR-031
  exception clause and explicitly cited.)
- No frontend Vue components beyond the generic
  `CnIndexPage` / `CnDetailPage` / `CnReportPage` from
  `@conduction/nextcloud-vue` bound through `src/manifest.json`.
  The bookkeeping-financial-statements capability MAY require a new
  `CnReportPage` in nextcloud-vue (an `add-cn-report-page` change
  on the library would land in parallel); shillinq itself authors
  no bespoke Vue.

## Decisions

### D1 — Trial balance is an aggregation, not a report builder

Per ADR-031, the trial balance is declared as one or three
`x-openregister-aggregations` queries grouping `GLLine` by
`(period_id, account_number, side)` with opening / movement /
closing buckets. Resolution of "one query vs three" is
`bookkeeping-trial-balance` design discovery. The PHP-side
`TrialBalanceReportService` that shillinq could otherwise grow into
is the ADR-031 anti-pattern — explicitly rejected.

Drill-through is a manifest-side affordance: the trial-balance row
links to a filtered GL index page (`/general-ledger?period=…&account=…`).

The **debit-credit-balance-verifies invariant** (sum of period
debits = sum of period credits across all accounts) is declared as
a schema invariant on the aggregation output, not as a PHP service
check (per ADR-031 — invariants on aggregations are themselves
declarative).

### D2 — Period close is a lifecycle on a new `FiscalPeriod` register

T1's `GLLine.periodId` is a stub string. T2 promotes
`FiscalPeriod` to a full register with an
`open → closing → closed → audit-locked` lifecycle. Postings
against a closed period are rejected by an OR lifecycle
precondition on `GLTransaction.post` (added to T1's existing
balance + active-account precondition list).

The `closed` state is reversible (operator with elevated role +
audit-trailed reason). The `audit-locked` state is irreversible —
once an auditor signs off, the period freezes. Industry-standard
shape; matches Exact / AFAS / Twinfield.

**Year-end close is explicitly T3** — opening-balance journal
generation and retained-earnings rollover are separate concerns.
T2 declares only the monthly/quarterly close lifecycle.

### D3 — AP and AR are sub-ledgers, not duplicates of GL

`APInvoice` and `ARInvoice` are sub-ledger registers carrying the
vendor/customer-facing fields (vendor ref, due date, payment terms,
dunning history, etc.). Posting an AP invoice **materialises** a
balanced `GLTransaction` per the same lifecycle pattern T1 used
for `JournalEntry`. AR is symmetric.

`GLLine.subLedgerType` + `subLedgerRef` (stubbed in T1 REQ-GL-009)
now resolve: `subLedgerType: "ap"` points at an `APInvoice` UUID;
`subLedgerType: "ar"` at an `ARInvoice`. The FK validation T1
deferred lands in T2.

**Alternative considered**: One unified `Invoice` register
distinguished by a `direction` field. Rejected — vendor master vs
customer master are genuinely different domain models (vendor
carries bank IBAN + payment terms + tax registration; customer
carries dunning policy + credit limit + invoice templates), and
the lifecycles diverge (AP has approval + payment-run; AR has
issuance + dunning + payment-match). Two registers, two lifecycles.

### D4 — Three-way match is a lifecycle precondition on `APInvoice.post`

When PO + Goods Receipt registers are present (future procurement
capability — T4), `APInvoice.post` requires matched quantities and
amounts. When they are not present, the precondition reduces to a
2-way match (invoice + manual approval). T2 declares the FK fields
+ the conditional precondition; the precondition body uses
`x-openregister-lifecycle.requires` with conditional clauses (per
the OR engine's documented support) or falls back to a
single-method `OCA\Shillinq\Lifecycle\ThreeWayMatchGuard` per
ADR-031 exception.

### D5 — Payment run is a register, not a service

A `PaymentRun` is a register carrying N selected `APInvoice` UUIDs
+ the SEPA pain.001 XML output (generated by an
`x-openregister-calculations` field that composes the XML from the
selected invoices' bank details). The operator downloads the XML
and uploads it to their bank portal. iDEAL payment-link generation
is a similar calculation per invoice.

**Alternative considered**: A `PaymentService` that orchestrates
selection + XML generation. Rejected per ADR-031 — the calculation
extension covers XML composition (string output from object data),
and the selection is operator-driven through the standard register
UI.

Live PSD2 bank initiation is T4.

### D6 — AR dunning is consumed from OR's dunning-workflow abstraction

`ARInvoice` declares the dunning policy by FK to a dunning-policy
record managed in OR. The dunning workflow (reminder 1 at +14
days, reminder 2 at +30 days, formal notice at +45 days, debt
collection escalation at +60 days — all customisable per
administration) runs in OR's engine; shillinq carries no app-local
dunning service.

If OR's dunning-workflow extension is not yet stable at T2
implementation time, the AR spec annotates the gap and the
implementing cycle either waits or carries a single-method
`OCA\Shillinq\Lifecycle\DunningGuard` per ADR-031 exception. Spec
is shape-neutral.

### D7 — Financial statements are aggregations + a presentation manifest

Balance Sheet, P&L, Cash Flow are compositions of trial-balance
aggregations grouped by a presentation manifest (RJ 270 / IFRS for
SMEs line items mapped to account ranges). The manifest is shipped
as JSON under `lib/Settings/statements/` (per administration
selection): `rj270-balance-sheet.json`, `rj270-pl.json`,
`rj270-cash-flow.json`.

The renderer is either a new `CnReportPage` component in
`@conduction/nextcloud-vue` (the preferred path — preserves
ADR-024 Tier-4 across the fleet) or a short bespoke Vue per
statement (the fallback if the library doesn't ship in time).
Decision lives in `bookkeeping-financial-statements/design.md`
discovery. The spec is shape-neutral on which renderer.

XBRL/PDF export is also declarative — XBRL is a calculation
producing the SBR-compatible XML; PDF is rendered server-side via
the existing `@conduction/nextcloud-vue` PDF utility (or `wkhtmltopdf`
adapter) bound through a manifest action.

**Year-over-year comparatives** are a manifest-side affordance:
the report manifest declares N comparison periods, and the
aggregation runs once per period. No bespoke logic.

### D8 — Audit trail is consumed, with a UI surface only

Per ADR-022, OR's audit-trail-immutable abstraction is already in
use for T1's three registers and will be on by default for every
T2 register. The `bookkeeping-audit-trail` capability spec exists
to (a) confirm every bookkeeping register declares
`x-openregister-audit: true`, (b) add a manifest entry exposing
OR's audit-log UI pre-filtered to bookkeeping objects.

**Alternative considered**: An app-local audit table mirroring OR's
log. Explicitly rejected per ADR-022 anti-pattern list.

### D9 — Document attachment is a contract, not a storage layer

`bookkeeping-document-attachment-integration` defines the FK
contract (which fields hold the docudesk object ID, what
mime-types are expected per role: invoice/receipt/statement/contract)
and the failure mode when docudesk is unavailable. The capability
ships zero file-storage code in shillinq — every attachment is a
docudesk reference per ADR-022.

The failure mode (docudesk unreachable on attempt to save a
journal/invoice with a `sourceDocumentUri`) is: save succeeds with
the URI persisted; the audit trail records the unavailability; the
detail page renders a warning banner. The bookkeeping flow MUST
NOT block on docudesk transient downtime.

### D10 — Bank reconciliation is two registers + a matching-rule schema

T2 declares `BankStatement` (header per upload) and
`BankStatementLine` (per transaction) registers. Matching against
AP/AR is rule-driven: a `MatchingRule` schema declares predicates
(`exact-amount + exact-reference`, `amount-range + customer-name`,
etc.) consumed by an `x-openregister-aggregations` query that
emits candidate `ReconciliationMatch` records. The operator
confirms / rejects matches through the standard register UI.

Unmatched lines route to a designated suspense account
(operator-configured in the bank-rec settings). The reconciliation
workflow is an OR lifecycle on `BankStatement` —
`imported → in-progress → reconciled → audit-locked`.

Live PSD2 connectors (auto-import on bank webhook) are T4.

## Reuse Analysis

| Capability needed | What already exists | T2 reuse strategy |
|---|---|---|
| Period stamping on GL lines | T1 REQ-GL-006 declares `GLLine.periodId` as a stub string | T2 promotes `FiscalPeriod` to a full register; the stub becomes a real FK with `x-openregister-relations` validation |
| Trial balance aggregation | OR `x-openregister-aggregations` | Single aggregation query grouping T1's `GLLine` — no PHP report builder |
| Period close state machine | OR `x-openregister-lifecycle` | Lifecycle on the new `FiscalPeriod` register; precondition added to `GLTransaction.post` to reject closed-period postings |
| AP invoice lifecycle | OR `x-openregister-lifecycle` | Lifecycle on `APInvoice` with 3-way match precondition; materialises a balanced `GLTransaction` on post per T1's REQ-JE-007 pattern |
| AP approval routing | OR approval-workflow (ADR-022) | Consumed via `x-openregister-lifecycle.requires`; no shillinq approval table |
| AR invoice lifecycle | OR `x-openregister-lifecycle` | Lifecycle on `ARInvoice` (`draft → issued → paid` / `overdue` / `written-off`); materialises GL on issue |
| AR dunning workflow | OR dunning-workflow (if stable; else gap) | Consumed via lifecycle reference; PHP guard fallback per ADR-031 exception if needed |
| Payment run SEPA pain.001 | OR `x-openregister-calculations` | XML composition is a calculation field on `PaymentRun`; no PHP service |
| Financial statement assembly | T2 trial-balance aggregations + presentation manifest | Manifest under `lib/Settings/statements/`; renderer is either `CnReportPage` (preferred) or a short bespoke Vue (fallback) |
| Balance Sheet / P&L / Cash Flow templates | RJ 270 / IFRS for SMEs (NL/EU) public standard | Ship JSON manifests for each statement type; per-administration override allowed |
| XBRL export | OR `x-openregister-calculations` | SBR-compatible XML is a calculation on the statement output; no PHP exporter |
| PDF export | `@conduction/nextcloud-vue` PDF utility (or wkhtmltopdf adapter) | Manifest-driven action button; no shillinq-side PDF code |
| Audit trail per register | OR audit-trail-immutable (ADR-022) | Every T2 register declares `x-openregister-audit: true`; no app-local audit |
| Audit UI surface | OR audit-log UI | Pre-filtered manifest entry in `src/manifest.json` — no shillinq code |
| Source-document attachment | docudesk (ADR-022) | FK by URI per `bookkeeping-document-attachment-integration` spec; mime-type contract per role; no shillinq file storage |
| Bank statement parsing | OR `x-openregister-calculations` for CAMT.053 + MT940 (if extension supports XML/structured-text parsing); else a single-method `OCA\Shillinq\Lifecycle\StatementParser` per ADR-031 exception | Parser path resolved during `opsx-ff` discovery; spec is shape-neutral |
| Bank matching rules | OR `x-openregister-aggregations` consuming declarative match predicates | Rule predicates declared as schema metadata on `MatchingRule`; aggregation emits candidate matches; operator confirms |
| Suspense account routing | T2 references T1's `Account` register | A designated account flagged `isSuspenseAccount: true` (added to T1's Account schema as an additive field — or carried as an administration setting); unmatched lines post against it |
| Vendor/customer master | New T2 registers | `VendorMaster` + `CustomerMaster` — domain models. Cross-app linkage to OR's `contact` abstraction if it exists per ADR-022 (else app-local for now with a migration plan once OR ships contacts) |
| Manifest navigation | T1's already-adopted Tier-4 manifest | T2 adds 7 navigation entries (trial-balance, period-close, AP, AR, bookkeeping-financial-statements, audit-trail, bank-reconciliation); document-attachment ships as a side panel, no top-level nav |
| Lifecycle engine | `x-openregister-lifecycle` (ADR-031) | All T2 lifecycle-bearing schemas declare it |
| Aggregation engine | `x-openregister-aggregations` (ADR-031) | Trial balance, AR aging, AP aging, Cash Flow all aggregations |
| RBAC | OR authorization | Per-schema role definitions: `bookkeeper`, `ap-approver`, `ar-controller`, `treasurer`, `auditor` |

**Net new code in T2 implementation**: ~10 schema declarations + ~7
manifest entries + ~3 statement presentation manifests (RJ 270
balance sheet / P&L / cash flow). At most 3 single-method PHP
lifecycle guards (`ThreeWayMatchGuard`, `DunningGuard`,
`StatementParser`) gated by ADR-031 exception, each ~20 LOC.

## Declarative-vs-imperative decision (per ADR-031)

Per ADR-031 enforcement, each behaviour was classified before the
specs were finalised:

| Behaviour | Decision | Why |
|---|---|---|
| Trial balance assembly | Declarative (`x-openregister-aggregations`) | Pure GROUP BY + SUM over T1's `GLLine` |
| Period-close lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Closed-period posting rejection | Declarative — adds to T1's existing `GLTransaction.post` precondition list | Engine already handles preconditions |
| AP invoice lifecycle | Declarative | Pure state machine |
| AP 3-way match precondition | Declarative if engine supports conditional clauses; else single-method PHP guard (`ThreeWayMatchGuard`) per ADR-031 exception | Resolution in `bookkeeping-accounts-payable-core` discovery; spec shape-neutral |
| AP approval routing | Consumed from OR approval-workflow | ADR-022 |
| AR invoice lifecycle | Declarative | Pure state machine |
| AR dunning workflow | Consumed from OR dunning-workflow if stable; else single-method `DunningGuard` per ADR-031 exception | Resolution in `bookkeeping-accounts-receivable-core` discovery |
| Payment run XML composition | Declarative (`x-openregister-calculations`) | Pure data → string transformation |
| iDEAL link generation | Declarative (`x-openregister-calculations`) | Pure data → string transformation |
| AR / AP aging | Declarative (`x-openregister-aggregations`) | Pure GROUP BY + SUM with date bucket calculations |
| Cash flow statement | Declarative (`x-openregister-aggregations`) on T1's `GLLine` filtered to liquidity accounts | Spec defers indirect vs direct method choice to the implementing manifest |
| Balance Sheet / P&L assembly | Declarative — composition of trial-balance aggregations with a presentation manifest | No report-builder service |
| XBRL export | Declarative (`x-openregister-calculations`) | Pure data → XML transformation |
| Bank statement import (CAMT.053 / MT940 parsing) | Declarative if engine supports structured-text parsing extension; else single-method `StatementParser` per ADR-031 exception | Resolution in `bookkeeping-bank-reconciliation` discovery |
| Bank matching rule evaluation | Declarative (`x-openregister-aggregations` with predicate schema) | Pure data join |
| Bank reconciliation lifecycle | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| Audit trail per register | Consumed from OR audit-trail-immutable | ADR-022 |
| Audit UI | Manifest entry into OR's audit-log UI | No shillinq code |
| Document attachment | FK to docudesk by URI | ADR-022 |
| Suspense account routing | Account flag + lifecycle action on unmatched bank line | Declarative |

No service class authored in this T2 envelope for any of the
above. At most 3 single-method lifecycle guards (`ThreeWayMatchGuard`,
`DunningGuard`, `StatementParser`), each explicitly cited as an
ADR-031 exception in the implementing cycle's per-spec design doc.

## Seed Data

T2 ships statement-presentation manifests under
`lib/Settings/statements/`:

| File | Purpose | Approximate row count |
|---|---|---|
| `rj270-balance-sheet.json` | RJ 270 / IFRS-for-SMEs Balance Sheet presentation (assets / liabilities / equity hierarchy mapped to RGS 3.5 SMB account ranges). | ~40 |
| `rj270-pl.json` | RJ 270 / IFRS-for-SMEs Profit & Loss presentation (revenue / cost of sales / operating expenses / financial result / tax / net result mapped to RGS account ranges). | ~30 |
| `rj270-cash-flow.json` | RJ 270 / IFRS-for-SMEs Cash Flow Statement (indirect method default; direct-method variant on roadmap). | ~25 |

No matching-rule seeds, no dunning-policy seeds, no vendor/customer
seeds — those are operator-authored on first use. The repair step
imports the statement manifests via the same
`ConfigurationService::importFromApp()` pattern T1 uses for RGS
account templates.

Each statement-manifest file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- An `_meta` block (`{ "_meta": { "source": "RJ 270 (2026)",
  "variant": "smb", "imported": "<iso-timestamp>" } }`) so a future
  migration to a different presentation standard (BBV — T3; IFRS
  full — T5) can identify which records were template-sourced
  versus operator-authored.

BBV-conformant statement manifests are explicitly T3 (`rgs-bbv.json`
seed lands in T1, but the BBV-specific balance sheet / programme /
exploitation manifests are deferred to T3-bbv-compliance).

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR aggregation engine cannot express opening/closing buckets in one query | Per-spec design discovery resolves "one query vs three composed"; both paths are declarative and acceptable |
| OR dunning-workflow not yet stable at T2 implementation time | Spec shape-neutral; PHP guard fallback (`DunningGuard`, single-method, ~20 LOC) per ADR-031 exception; remove when OR extension lands |
| PO / GR registers not available for 3-way match | AP spec is conditional: 2-way match when PO/GR absent; FKs declared so future T4 procurement capability attaches additively |
| Reopening a closed period is destructive | Reopen requires elevated role + audit-trailed reason; original close timestamp + actor preserved in audit; matches industry-standard behaviour |
| Financial-statement renderer (`CnReportPage`) not yet in nextcloud-vue | Spec shape-neutral; falls back to a short bespoke Vue per statement type if library doesn't ship in time; library path is preferred and tracked in nextcloud-vue roadmap |
| CAMT.053 / MT940 format drift across Dutch banks | Use battle-tested OR parsing extension if available; per-bank quirks handled through matching-rule customisation rather than parser changes |
| Vendor / customer master overlaps with OR's `contact` abstraction (if it exists) | Per ADR-022, prefer the OR abstraction; T2 declares the bookkeeping-side fields as a thin view onto contacts if the OR abstraction is stable; otherwise app-local with documented migration plan |
| Audit trail UI placement (dedicated nav vs side panel) | Spec declares the capability; placement resolved during implementing cycle's UX review |
| BBV financial statements arrive late from T3 and need the same renderer | The presentation-manifest pattern is format-agnostic; T3 ships BBV-conformant JSON manifests that the same renderer consumes; no T2 rework needed |
| Three single-method PHP guards may grow into services if reviewers don't enforce ADR-031 exception scope | Reviewer guidance + explicit "single-method, ~20 LOC, ADR-031 §exception" annotation in each guard's file header; spec gate-32 (when promoted from soft to BLOCKING) will catch growth |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with ~10 new
   schemas (additive — no existing schema changes; T1 schemas
   remain untouched).
2. `src/manifest.json` is patched with 7 new top-level navigation
   entries + their `type: index` / `type: detail` (and possibly
   `type: report`) pages (additive).
3. `lib/Settings/statements/rj270-{balance-sheet,pl,cash-flow}.json`
   ship as new files. Imported via
   `ConfigurationService::importFromApp()` in the repair step.
4. T1's `GLLine.periodId` stub-string is upgraded to a real FK once
   `FiscalPeriod` is declared — additive change to T1's existing
   schema (an `x-openregister-relations` block added to the
   `periodId` field). No data migration: existing string values
   resolve against the new `FiscalPeriod` records by exact match.
5. T1's `Account` schema gains an additive `isSuspenseAccount`
   boolean (optional, default `false`) per Decision D10. Or the
   suspense designation is carried as an administration setting —
   resolved in `bookkeeping-bank-reconciliation` discovery.

Down-direction: registers are non-destructive — disabling the
repair step's statement-manifest import + reverting the manifest +
register additions leaves stranded but queryable records. The
chain `depends_on` graph means partial rollback (e.g. revert AR
but keep AP) is supported.

## Open Questions

1. **Opening/closing bucket aggregation shape** — resolved in
   `bookkeeping-trial-balance/spec.md` discovery before implementing
   cycle.
2. **Dunning-workflow stability on OR** — resolved in
   `bookkeeping-accounts-receivable-core/spec.md` discovery; OR issue filed if
   needed.
3. **PO/GR availability at T2 implementation time** — `bookkeeping-accounts-payable-core`
   is conditional; current assumption is PO/GR ships T4 alongside
   procurement.
4. **`CnReportPage` library availability** — resolved in
   `bookkeeping-financial-statements/spec.md` discovery; nextcloud-vue
   roadmap item.
5. **Vendor/customer master vs OR contact abstraction** — resolved
   per ADR-022 review during the implementing cycle; if OR's contact
   abstraction is stable, AP/AR spec adapts to consume it.
6. **Audit-trail UI placement** — confirmed during implementing
   cycle's UX review.
7. **Suspense account designation: schema flag vs administration
   setting** — resolved in `bookkeeping-bank-reconciliation/spec.md`
   discovery.
8. **CAMT.053 / MT940 parser path** — declarative extension or
   `StatementParser` guard, resolved in
   `bookkeeping-bank-reconciliation` discovery.



## Tasks

# Tasks — Bookkeeping Compliance + Operations (T2)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work an
> `opsx-apply` cycle will execute against the eight spec deltas — they
> are recorded now so the spec-review gate, dependency planning, and
> tier-cascade impact are all visible at proposal time. No source files
> are edited by this change itself.

## 0. Deduplication Check

### Task 0.1: Confirm no T2 schema or capability already exists

- **spec_ref**: all eight specs (folder scan)
- **files**: `lib/Settings/shillinq_register.json`,
  `openspec/specs/**`, `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the shillinq repo at the head of `feat/bookkeeping-engine`
    WHEN `lib/Settings/shillinq_register.json` is inspected
    THEN no `FiscalPeriod`, `VendorMaster`, `APInvoice`, `PaymentRun`,
    `CustomerMaster`, `ARInvoice`, `DunningRecord`, `BankStatement`,
    `BankStatementLine`, `MatchingRule`, or `ReconciliationMatch`
    schema is already declared (T1 schemas — `Account`,
    `GLTransaction`, `GLLine`, `JournalEntry` — are permitted and
    expected).
  - GIVEN `openspec/specs/` WHEN scanned THEN no
    `bookkeeping-trial-balance`, `bookkeeping-period-close`,
    `bookkeeping-accounts-payable-core`, `bookkeeping-accounts-receivable-core`,
    `bookkeeping-financial-statements`, `bookkeeping-audit-trail`,
    `bookkeeping-document-attachment-integration`, or
    `bookkeeping-bank-reconciliation` capability spec already exists.
  - GIVEN `adr-000-data-model.md` WHEN read THEN any existing entries
    for `FiscalPeriod`, `Vendor*`, `Customer*`, `Invoice*`,
    `BankStatement*`, or `Reconciliation*` are catalogued and the
    reconciliation note from `design.md` is appended (similar to T1's
    GeneralLedgerEntry → GLLine supersession note).
- [ ] Implement
- [ ] Test

### Task 0.2: Confirm no parallel storage / service classes already exist

- **spec_ref**: every T2 spec's "Reviewer confirms no parallel storage"
  scenario
- **files**: `lib/Db/`, `lib/Service/`, `lib/Controller/`,
  `appinfo/info.xml`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `lib/Db/` WHEN scanned THEN no Mapper classes naming any
    of: `ap_invoice`, `vendor_master`, `payment_run`,
    `ar_invoice`, `customer_master`, `dunning_*`,
    `fiscal_period`, `bank_statement`, `bank_line`,
    `reconciliation_*`, `match_rule`, `audit_*`, `event_log_*`
    SHALL exist.
  - GIVEN `lib/Service/` WHEN scanned THEN no service classes named
    `TrialBalance*`, `*ReportBuilder*`, `BalanceSheet*`,
    `ProfitAndLoss*`, `CashFlow*`, `Statement*`, `Aging*`,
    `Dunning*`, `Reconcil*`, `Match*`, `Payment*`, `Sepa*`,
    `Ideal*`, `Xbrl*`, `Sbr*`, or `Audit*` SHALL exist (other than
    the at-most-3 conditional lifecycle guards permitted by
    ADR-031 exception, each cited per-spec).
  - GIVEN `appinfo/routes.php` WHEN scanned THEN no routes
    matching `/psd2/webhook`, `/attachment/upload`, or
    `/audit/purge` SHALL exist.
- [ ] Implement
- [ ] Test

## 1. Spec foundation (this change)

### Task 1.1: Author bookkeeping-trial-balance spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-trial-balance/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: shillinq` /
    `Tier: T2 (compliance + operations)` /
    `Depends on: T1 bookkeeping-general-ledger` header.
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-TB-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4
    hashtags on the scenario header per conduction-schema rule).
  - GIVEN the spec WHEN scanned for ADR citations THEN ADR-022
    (no parallel storage) and ADR-031 (declarative aggregation
    over service) MUST be cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.2: Author bookkeeping-period-close spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-period-close/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: T2 bookkeeping-trial-balance, T1 bookkeeping-general-ledger`.
  - GIVEN the spec WHEN scanned THEN it declares the
    `FiscalPeriod` register, the `open → closing → closed →
    audit-locked` lifecycle, the closed-period posting precondition
    added to T1's `GLTransaction.post`, the reopen workflow with
    elevated-role + audit-trailed reason, and explicitly defers
    year-end close to T3.
  - GIVEN the spec WHEN scanned for ADR citations THEN ADR-022 +
    ADR-031 MUST be cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.3: Author bookkeeping-accounts-payable-core spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-accounts-payable-core/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-document-attachment-integration`.
  - GIVEN the spec WHEN scanned THEN it declares `VendorMaster`,
    `APInvoice`, `PaymentRun` registers; the AP lifecycle
    consuming OR approval-workflow per ADR-022; the conditional
    3-way match (2-way fallback when PO/GR absent); SEPA pain.001
    + iDEAL as `x-openregister-calculations` per ADR-031.
  - GIVEN the spec WHEN scanned for legacy intelligence-db cluster
    references THEN it explicitly addresses the legacy AP/AR
    draft cluster (per proposal Motivation).
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.4: Author bookkeeping-accounts-receivable-core spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-accounts-receivable-core/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-document-attachment-integration, T2 bookkeeping-bank-reconciliation`.
  - GIVEN the spec WHEN scanned THEN it declares
    `CustomerMaster`, `ARInvoice`, `DunningRecord` registers; the
    AR lifecycle consuming OR dunning-workflow per ADR-022 (with
    shape-neutral PHP-guard fallback per ADR-031 exception); the
    write-off path; the UBL 2.1 / Peppol BIS 3.0 field shape
    declared for T4 attachment but NOT computed in T2.
  - GIVEN the spec WHEN scanned THEN it explicitly notes the
    capability "carries forward the original Shillinq invoicing
    scope" per proposal Motivation.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.5: Author bookkeeping-financial-statements spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-financial-statements/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-trial-balance`.
  - GIVEN the spec WHEN scanned THEN it declares the Balance
    Sheet / P&L / Cash Flow as compositions of trial-balance
    aggregations + a presentation manifest under
    `lib/Settings/statements/`; XBRL/PDF export as declarative
    calculations; the `CnReportPage` renderer path (preferred)
    or a per-statement bespoke Vue fallback (with sunset note);
    BBV scope explicitly deferred to T3.
  - GIVEN the spec WHEN scanned for ADR citations THEN ADR-024
    (Tier-4 manifest) and ADR-031 (declarative report assembly)
    MUST be cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.6: Author bookkeeping-audit-trail spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-audit-trail/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries `Depends on: none`
    (the capability is a wiring + UI surface, not a code
    dependency).
  - GIVEN the spec WHEN scanned THEN it declares (a) every
    bookkeeping register must carry `x-openregister-audit: true`,
    (b) the manifest entry into OR's audit-log UI pre-filtered
    to bookkeeping object types, (c) the audit side panel on
    every bookkeeping detail page, (d) retention governed by OR
    per ADR-022.
  - GIVEN the spec WHEN scanned THEN it explicitly forbids
    `lib/Db/Audit*`, `lib/Service/Audit*` per ADR-022
    anti-pattern.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.7: Author bookkeeping-document-attachment-integration spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-document-attachment-integration/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries `Depends on: none`
    (defines a cross-app contract; consumed by other T1 + T2
    specs).
  - GIVEN the spec WHEN scanned THEN it declares the FK URI
    contract (`docudesk://attachments/<uuid>/<filename>`), the
    mime-type-per-role metadata, the non-blocking failure mode
    when docudesk is unavailable (URI persists, audit records
    the gap, warning banner on detail), the auditor-role
    pass-through.
  - GIVEN the spec WHEN scanned for ADR citations THEN ADR-022
    (consume docudesk, no parallel attachment storage) MUST be
    cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.8: Author bookkeeping-bank-reconciliation spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-compliance/specs/bookkeeping-bank-reconciliation/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec WHEN opened THEN it carries
    `Depends on: T1 bookkeeping-general-ledger, T2 bookkeeping-document-attachment-integration`.
  - GIVEN the spec WHEN scanned THEN it declares `BankStatement`,
    `BankStatementLine`, `MatchingRule`, `ReconciliationMatch`
    registers; CAMT.053 + MT940 + manual CSV import; matching
    rules declared as schema metadata per ADR-031; the suspense-
    account routing; the bank-statement lifecycle including
    audit-lock; PSD2 live-feed explicitly deferred to T4.
  - GIVEN the spec WHEN scanned for ADR citations THEN ADR-031
    (declarative rule evaluation over service) and ADR-022
    (consume audit + docudesk) MUST be cited inline.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.9: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it references the
    shared `nextcloud-app` spec per shillinq config.yaml
    `rules.proposal` and includes Affected Projects / Scope /
    Risks / Rollback / Open Questions; it explicitly cites the
    T1 foundation change by file path under "Cross-Project
    Dependencies".
  - GIVEN `design.md` WHEN inspected THEN it includes a
    Reuse Analysis table, a Seed Data section (statement
    presentation manifests), a Decisions section (D1..D10), and a
    Declarative-vs-imperative decision table per ADR-031
    enforcement.
- [x] Implement
- [ ] Test (peer review — bookkeeper persona reads the eight
  specs end-to-end and confirms RJ 270 / IFRS-for-SMEs +
  Belastingdienst conformance)

---

## (The following tasks are recorded for the downstream `opsx-apply` cycle, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

### Task 2.1: Declare the `FiscalPeriod` schema + promote T1's `GLLine.periodId` to FK

- **spec_ref**: `bookkeeping-period-close/spec.md` (REQ-PC-001 .. REQ-PC-006)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-PC-002 are
    present (`periodId`, `name`, `startDate`, `endDate`,
    `fiscalYear`, `administrationId`, `state`, `closedAt`,
    `closedBy`, `auditLockedAt`, `auditLockedBy`, `closeReason`,
    `reopenedHistory`).
  - GIVEN the schema's lifecycle WHEN scanned THEN it declares
    `open → closing → closed → audit-locked` with the close +
    reopen + audit-lock transitions per REQ-PC-003.
  - GIVEN T1's `GLLine.periodId` field WHEN inspected THEN it
    carries an additive `x-openregister-relations` block resolving
    against `FiscalPeriod` (per REQ-PC-001 additive migration
    note).
  - GIVEN T1's `GLTransaction.post` precondition WHEN inspected
    THEN it carries an additive closed-period rejection clause
    per REQ-PC-004.
- [ ] Implement
- [ ] Test (PHPUnit: backdating rejected; reopen requires
  elevated role; audit-lock irreversible)

### Task 2.2: Declare the `VendorMaster` + `APInvoice` + `PaymentRun` schemas

- **spec_ref**: `bookkeeping-accounts-payable-core/spec.md` (REQ-AP-001 .. REQ-AP-008)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the three schemas WHEN loaded THEN every field from
    REQ-AP-002 / REQ-AP-003 / REQ-AP-007 is present with the
    typing the spec mandates.
  - GIVEN `APInvoice`'s lifecycle WHEN scanned THEN it declares
    every transition in REQ-AP-004 with the approval-workflow
    consumed from OR per REQ-AP-005 (no app-local approval table).
  - GIVEN `PaymentRun`'s `sepaXml` field WHEN inspected THEN it
    is declared as an `x-openregister-calculations` output per
    REQ-AP-007 (no PHP service).
  - GIVEN the 3-way match precondition on `APInvoice.post` WHEN
    inspected THEN it conditionally activates if PO + GR
    registers are present (per REQ-AP-006).
- [ ] Implement
- [ ] Test (PHPUnit: AP lifecycle; SEPA XML schema validation
  against pain.001.001.03; 2-way and 3-way match)

### Task 2.3: Declare the `CustomerMaster` + `ARInvoice` + `DunningRecord` schemas

- **spec_ref**: `bookkeeping-accounts-receivable-core/spec.md` (REQ-AR-001 .. REQ-AR-008)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the three schemas WHEN loaded THEN every field from
    REQ-AR-002 / REQ-AR-003 / REQ-AR-005 is present.
  - GIVEN `ARInvoice`'s lifecycle WHEN scanned THEN it declares
    every transition in REQ-AR-004 with dunning consumed from
    OR per REQ-AR-005 (or DunningGuard fallback per ADR-031
    exception, documented).
  - GIVEN credit-limit check WHEN inspected THEN it is an
    `x-openregister-aggregations` query per REQ-AR-006, not a
    service.
  - GIVEN AR aging WHEN inspected THEN it is an
    `x-openregister-aggregations` query per REQ-AR-008.
- [ ] Implement
- [ ] Test (PHPUnit: AR lifecycle; overdue auto-transition;
  dunning timeline; write-off compensating posting)

### Task 2.4: Declare the bank-reconciliation registers (`BankStatement`, `BankStatementLine`, `MatchingRule`, `ReconciliationMatch`)

- **spec_ref**: `bookkeeping-bank-reconciliation/spec.md`
  (REQ-BR-001 .. REQ-BR-010)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the four schemas WHEN loaded THEN every field from the
    relevant REQs is present with the typing the spec mandates.
  - GIVEN `BankStatement`'s lifecycle WHEN scanned THEN it
    declares `imported → in-progress → reconciled → audit-locked`
    per REQ-BR-008.
  - GIVEN `MatchingRule.predicates` WHEN scanned THEN it accepts
    every predicate shape in REQ-BR-005.
  - GIVEN the parser declaration WHEN inspected THEN it is
    EITHER `x-openregister-calculations` OR a single-method
    `StatementParser` (ADR-031 exception cited in file header),
    per REQ-BR-003.
  - GIVEN the duplicate-import constraint WHEN scanned THEN it
    is declarative (uniqueness on file checksum + period
    overlap) per REQ-BR-009.
- [ ] Implement
- [ ] Test (PHPUnit: CAMT.053 + MT940 parsing; match emission;
  suspense routing; duplicate import rejection)

### Task 2.5: Declare `x-openregister-audit: true` on every T1 + T2 register

- **spec_ref**: `bookkeeping-audit-trail/spec.md` (REQ-AT-001)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN every register declared by T1 + T2 WHEN inspected
    THEN each carries `x-openregister-audit: true` (or the
    OR-canonical equivalent).
  - GIVEN T1 schemas (`Account`, `GLTransaction`, `GLLine`,
    `JournalEntry`) WHEN re-inspected post-T2 THEN the audit flag
    is preserved (T1 already declared it; T2 confirms).
- [ ] Implement
- [ ] Test (PHPUnit: audit event emission on every register's
  create/update/lifecycle transition)

### Task 2.6: Declare the trial-balance + aging + cash-flow + AR-aging aggregations

- **spec_ref**: `bookkeeping-trial-balance/spec.md` (REQ-TB-001 .. REQ-TB-003),
  `bookkeeping-accounts-payable-core/spec.md` (REQ-AP-009),
  `bookkeeping-accounts-receivable-core/spec.md` (REQ-AR-008),
  `bookkeeping-financial-statements/spec.md` (REQ-FS-001)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the trial-balance aggregation WHEN inspected THEN it
    groups `GLLine` by `(period_id, account_number, side)` with
    opening / movement / closing buckets per REQ-TB-002, excludes
    `state: reversed` parents, and declares the balance
    invariant per REQ-TB-003.
  - GIVEN AP aging WHEN inspected THEN it groups `APInvoice` by
    `(vendorId, agingBucket)` per REQ-AP-009, excluding `paid` /
    `voided`.
  - GIVEN AR aging WHEN inspected THEN it groups `ARInvoice`
    similarly per REQ-AR-008.
  - GIVEN cash-flow aggregation WHEN inspected THEN it operates
    on `GLLine` filtered to liquidity accounts (indirect method
    default per REQ-FS-003).
- [ ] Implement
- [ ] Test (PHPUnit: aggregation correctness vs hand-computed
  trial balance; aging buckets; invariant detection on tampered
  state)

## 3. Statement presentation manifests — `lib/Settings/statements/`

### Task 3.1: Ship RJ 270 Balance Sheet presentation manifest

- **spec_ref**: `bookkeeping-financial-statements/spec.md`
  (REQ-FS-002)
- **files**: `lib/Settings/statements/rj270-balance-sheet.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN it parses as JSON and
    matches the shape declared in REQ-FS-002.
  - GIVEN the top SPDX header WHEN inspected THEN it carries
    EUPL-1.2 + Copyright Conduction B.V. per
    `feedback_spdx-in-docblock.md`.
  - GIVEN the sections WHEN counted THEN ~40 line items are
    present covering fixed assets / current assets / equity /
    provisions / long-term + short-term debt per RJ 270 SMB.
- [ ] Implement
- [ ] Test (peer review by bookkeeper persona; assembled output
  matches a known-good RJ 270 reference balance sheet)

### Task 3.2: Ship RJ 270 Profit & Loss presentation manifest

- **spec_ref**: `bookkeeping-financial-statements/spec.md`
  (REQ-FS-002)
- **files**: `lib/Settings/statements/rj270-pl.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN ~30 line items covering
    revenue / cost of sales / operating expenses / financial
    result / tax / net result per RJ 270.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.3: Ship RJ 270 Cash Flow Statement presentation manifest

- **spec_ref**: `bookkeeping-financial-statements/spec.md`
  (REQ-FS-002 + indirect-method default note in REQ-FS-003)
- **files**: `lib/Settings/statements/rj270-cash-flow.json`
- **acceptance_criteria**:
  - GIVEN the file WHEN loaded THEN ~25 line items covering
    operating / investing / financing activities per the
    indirect method.
- [ ] Implement
- [ ] Test (same shape as 3.1)

### Task 3.4: Extend the repair step to import the statement manifests

- **spec_ref**: `bookkeeping-financial-statements/spec.md`
  (REQ-FS-002 — `_meta` block + import path)
- **files**: existing repair class under `lib/Migration/` or
  `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh shillinq install WHEN the repair step runs
    THEN the three statement manifests are queryable via the
    OR object API.
  - GIVEN per-administration override WHEN a manifest is edited
    after import THEN the operator edit persists across
    subsequent repair runs (idempotent — no re-overwrite).
- [ ] Implement
- [ ] Test (PHPUnit + browser smoke in dev container)

## 4. Manifest navigation — `src/manifest.json`

### Task 4.1: Add Trial Balance navigation + page

- **spec_ref**: `bookkeeping-trial-balance/spec.md` (REQ-TB-005)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Trial Balance` with a `type: report` (or
    `type: index` fallback per REQ-TB-005) page; period query
    parameter defaults to active `FiscalPeriod`.
  - GIVEN `validate-manifest.js` WHEN run THEN it exits 0.
- [ ] Implement
- [ ] Test (validate-manifest + browser smoke)

### Task 4.2: Add Period Close navigation + pages

- **spec_ref**: `bookkeeping-period-close/spec.md` (REQ-PC-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Period Close` with `type: index` +
    `type: detail` pages binding to `FiscalPeriod`; detail page
    surfaces lifecycle action buttons + trial-balance preview
    link.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.3: Add Accounts Payable navigation + pages

- **spec_ref**: `bookkeeping-accounts-payable-core/spec.md` (REQ-AP-010)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Vendors`, `Bookkeeping > Accounts Payable`,
    `Bookkeeping > AP Aging`, `Bookkeeping > Payment Runs`
    entries per REQ-AP-010.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.4: Add Accounts Receivable navigation + pages

- **spec_ref**: `bookkeeping-accounts-receivable-core/spec.md` (REQ-AR-010)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Customers`, `Bookkeeping > Accounts Receivable`,
    `Bookkeeping > AR Aging`, `Bookkeeping > Dunning` entries
    per REQ-AR-010.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.5: Add Financial Statements navigation + pages

- **spec_ref**: `bookkeeping-financial-statements/spec.md`
  (REQ-FS-003 + REQ-FS-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Financial Statements > Balance Sheet`,
    `> Profit & Loss`, `> Cash Flow Statement` entries; renderer
    is `CnReportPage` (preferred) or the per-statement bespoke
    Vue fallback (with sunset note); XBRL + PDF export actions
    are declared on each page.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.6: Add Audit Trail navigation + side-panel declarations

- **spec_ref**: `bookkeeping-audit-trail/spec.md` (REQ-AT-003 +
  REQ-AT-004)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Audit Trail` opening OR's audit-log UI
    pre-filtered to bookkeeping object types.
  - GIVEN every bookkeeping `type: detail` manifest entry WHEN
    inspected THEN each declares the OR audit-log side panel
    filtered to the object's UUID per REQ-AT-004.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.7: Add Bank Reconciliation navigation + pages

- **spec_ref**: `bookkeeping-bank-reconciliation/spec.md`
  (REQ-BR-010)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Bank Reconciliation` + `Bookkeeping >
    Matching Rules` entries per REQ-BR-010.
- [ ] Implement
- [ ] Test (same as 4.1)

## 5. ADR-000 reconciliation note

### Task 5.1: Update adr-000-data-model.md with T2 entities

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the ADR WHEN opened THEN T2's new entities
    (`FiscalPeriod`, `VendorMaster`, `APInvoice`, `PaymentRun`,
    `CustomerMaster`, `ARInvoice`, `DunningRecord`,
    `BankStatement`, `BankStatementLine`, `MatchingRule`,
    `ReconciliationMatch`) are added with their canonical
    Schema.org annotation + Primary spec reference.
  - GIVEN any pre-existing data-model entries that overlap
    (e.g. `FiscalYear`, `Vendor`, `Customer`, `Invoice`,
    `BankStatement`) WHEN present THEN a reconciliation
    paragraph is appended matching design.md's Reuse Analysis.
- [ ] Implement
- [ ] Test (peer review by bookkeeper persona)

## 6. Conditional lifecycle guards (only if ADR-031 exception triggers per-spec)

### Task 6.1 (conditional): Author ThreeWayMatchGuard

- **spec_ref**: `bookkeeping-accounts-payable-core/spec.md` REQ-AP-006
- **files**: `lib/Lifecycle/ThreeWayMatchGuard.php` (new, single
  method)
- **acceptance_criteria**:
  - GIVEN OR's lifecycle engine cannot express conditional
    preconditions declaratively WHEN the guard is implemented
    THEN it has exactly one method
    `matches(string $invoiceId, ?string $poRef, ?string $grRef): bool`
    and is referenced from
    `x-openregister-lifecycle.requires` on `APInvoice.post`.
  - GIVEN the guard WHEN code-reviewed THEN it carries the
    ADR-031 exception annotation linking back to design.md's
    Declarative-vs-imperative decision table.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: 2-way pass; 3-way pass; 3-way reject on
  quantity mismatch)

### Task 6.2 (conditional): Author DunningGuard

- **spec_ref**: `bookkeeping-accounts-receivable-core/spec.md` REQ-AR-005
- **files**: `lib/Lifecycle/DunningGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN OR's dunning-workflow extension is NOT yet stable
    WHEN the guard is implemented THEN it has exactly one
    method evaluating dunning cadence + escalation;
    `DunningRecord` writes remain declarative.
  - GIVEN the guard WHEN code-reviewed THEN it carries the
    ADR-031 exception annotation with the OR-issue link for
    the dunning-workflow extension.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: reminder 1 at +14 days; collection
  escalation at +60 days)

### Task 6.3 (conditional): Author StatementParser

- **spec_ref**: `bookkeeping-bank-reconciliation/spec.md`
  REQ-BR-003
- **files**: `lib/Lifecycle/StatementParser.php` (new, single
  method)
- **acceptance_criteria**:
  - GIVEN OR's calculation extension does NOT yet support
    CAMT.053 / MT940 parsing primitives WHEN the parser is
    implemented THEN it has exactly one method
    `parse(string $contents, string $format): array`, ~50 LOC,
    no state, no orchestration.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: CAMT.053 25-line file; MT940 25-line file;
  CSV import)

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] Manual peer review by a competent Dutch bookkeeper persona
      (e.g. `/test-persona-janwillem` for SMB, or a domain-expert
      review) confirms the eight specs end-to-end match a real
      RJ 270 / IFRS-for-SMEs SMB bookkeeping flow + Belastingdienst
      retention obligations
- [ ] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031
      compliance (no app-local audit; no app-local approval table;
      no app-local dunning table; no service-class state machines;
      no PHP report builders; no PHP rule-engine; no file storage;
      manifest carries the navigation; calculations carry SEPA XML
      + XBRL composition)
- [ ] No source code changes outside `openspec/changes/add-shillinq-bookkeeping-compliance/`

## Tests (company-wide ADR-009)

<!-- T2 spec-only change. Implementation-cycle tests are pre-declared on tasks 2-6 above for completeness. -->

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — declared on tasks 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 3.4, 6.1, 6.2, 6.3; lands with implementation cycle
- [ ] Newman/Postman tests for new/changed API endpoints — no new endpoints in T2 (OR exposes register CRUD generically; tests cover the register HTTP surface)
- [ ] Browser tests (Playwright MCP) for UI changes — declared on tasks 4.1 through 4.7; lands with implementation cycle
- [ ] All tests pass (`composer test`) — enforced at implementing PR's CI gate

## Documentation (company-wide ADR-010)

<!-- User-facing tutorial pages land with the implementation cycle, not the spec. -->

- [ ] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/` — `docs/user-guide/bookkeeping/` subpages for trial-balance, period-close, accounts-payable, accounts-receivable, bookkeeping-financial-statements, audit-trail, document-attachment, bank-reconciliation authored during implementation cycle per ADR-030 journeydoc convention
- [ ] Screenshots captured and committed to `docs/images/` — authored during implementation cycle (~8 screenshots: trial balance, period-close detail, AP invoice + payment run, AR invoice + dunning timeline, balance sheet, audit-log side panel, bank-rec detail)

## i18n (company-wide ADR-005)

<!-- No user-facing strings in the spec; translation work lands with the implementation cycle. -->

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added during implementation cycle — required terms: `Trial Balance`, `Period Close`, `Open Period`, `Closing`, `Closed`, `Audit Locked`, `Reopen`, `Accounts Payable`, `Vendor`, `Vendors`, `AP Invoice`, `Payment Run`, `Aging`, `Accounts Receivable`, `Customer`, `Customers`, `AR Invoice`, `Dunning`, `Reminder`, `Formal Notice`, `Collection`, `Write-off`, `Disputed`, `Credit Limit`, `Balance Sheet`, `Profit & Loss`, `Cash Flow Statement`, `Comparative`, `XBRL Export`, `PDF Export`, `Audit Trail`, `Source Document`, `Attachment`, `Bank Reconciliation`, `Bank Statement`, `Matching Rule`, `Suspense Account`, `Confirm Match`, `Route to Suspense`, `Auto-confirm`, `Imported`, `Reconciled`