# Proposal: add-shillinq-bookkeeping-compliance

`kind: config` per ADR-032 — the centre of mass is declarative schema
metadata + manifest entries + statement presentation manifests. At most
3 single-method PHP lifecycle guards are permitted per ADR-031 exception;
no PHP service classes, no custom DB tables, no bespoke Vue components.

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

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown. This change delivers **Tier 2**:
`add-shillinq-bookkeeping-compliance`.

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

The eight T2 capabilities are intentionally each their own spec
(per ADR-032 spec sizing — each capability is `kind: config`
declarative and fits cleanly in a single config envelope). They
chain via `depends_on` to keep the merge sequence safe.

## Affected Projects

- [x] Project: shillinq — adds 8 capability specs, declares ~10
  additional registers/schemas in
  `lib/Settings/shillinq_register.json` (`FiscalPeriod`, `VendorMaster`,
  `APInvoice`, `PaymentRun`, `CustomerMaster`, `ARInvoice`, `DunningRecord`,
  `BankStatement`, `BankStatementLine`, `MatchingRule`, `ReconciliationMatch`),
  declares 7 additional manifest navigation entries in
  `src/manifest.json`, ships statement-presentation manifests under
  `lib/Settings/statements/`, ships nothing into `lib/Settings/seeds/`
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
- Seven manifest navigation entries (Trial Balance, Period Close,
  AP, AR, Financial Statements, Audit Trail, Bank Reconciliation;
  document-attachment-integration ships as a side panel on existing
  pages, no top-level nav).
- Sub-ledger registers (`FiscalPeriod`, `VendorMaster`, `CustomerMaster`,
  `APInvoice`, `ARInvoice`, `PaymentRun`, `DunningRecord`, `BankStatement`,
  `BankStatementLine`, `MatchingRule`, `ReconciliationMatch`) declared per
  capability spec.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via a separate `opsx-apply` cycle on the spec.
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
  already render generically.

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
   pre-filtered to bookkeeping objects.
7. **`bookkeeping-document-attachment-integration`** (consumes
   docudesk) — defines the FK contract for source-document linkage,
   the expected mime-types per attachment role (invoice / receipt /
   statement / contract), and the failure mode when docudesk is
   unavailable.
8. **`bookkeeping-bank-reconciliation`** (depends: T1 GL) —
   declares `BankStatement`, `BankStatementLine`, `MatchingRule`,
   `ReconciliationMatch` registers; supports CAMT.053 + MT940 +
   manual import; matching rules declared per ADR-031 as schema
   metadata.

All eight specs follow the conduction-schema format (RFC 2119,
`### REQ-{Abbrev}-NNN: <name>`, `#### Scenario:` with exactly four
hashtags, GIVEN/WHEN/THEN). Requirement prefixes: `REQ-TB-*`
trial-balance, `REQ-PC-*` period-close, `REQ-AP-*` accounts-payable,
`REQ-AR-*` accounts-receivable, `REQ-FS-*` financial-statements,
`REQ-AT-*` audit-trail, `REQ-DA-*` document-attachment,
`REQ-BR-*` bank-reconciliation.

## New Dependencies

None. T2 consumes existing OpenRegister abstractions plus the
already-bumped `@conduction/nextcloud-vue@^1.0.0-beta.35`. The
dunning-workflow extension on OR is referenced; if not yet stable,
the AR spec annotates the gap and the implementing cycle either waits
or carries a single-method PHP guard per ADR-031
§"PHP guards remain a legitimate seam".

## Impact

- `lib/Settings/shillinq_register.json` — adds ~11 schemas:
  `FiscalPeriod`, `VendorMaster`, `APInvoice`, `PaymentRun`,
  `CustomerMaster`, `ARInvoice`, `DunningRecord`, `BankStatement`,
  `BankStatementLine`, `MatchingRule`, `ReconciliationMatch`. Declares
  `x-openregister-lifecycle` on each that needs a state machine;
  declares `x-openregister-aggregations` on Trial Balance, AR
  aging, AP aging, Cash Flow.
- `src/manifest.json` — adds 7 navigation entries + their `type:
  index` + `type: detail` pairs. The bookkeeping-financial-statements
  capability may add a `type: report` page if its presentation manifest
  needs a dedicated renderer.
- Statement manifests (`lib/Settings/statements/`) — 3 new JSON files
  (RJ 270 balance sheet / P&L / cash flow) imported via
  `ConfigurationService::importFromApp()` in the repair step.
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

### Risk 3: Three-way match requires schemas this T2 doesn't declare

**Severity**: Medium
**Mitigation**: The Purchase Order and Goods Receipt registers
belong to a future procurement capability (planned for T4). T2's
`bookkeeping-accounts-payable-core` spec declares the 3-way match as
REQUIRED-IF-PRESENT — if no PO/GR register is available, the AP
invoice posts with a 2-way match (invoice + manual operator approval).
FK fields are declared in T2 so a future PO/GR capability attaches
without a destructive migration.

### Risk 4: SEPA pain.001 + iDEAL payment initiation requires bank credentials

**Severity**: Low
**Mitigation**: T2 declares the payment-run register and the
pain.001 XML output as a downloadable artefact (operator uploads
to their bank portal). Direct bank initiation via PSD2 lives in T4.
The spec is shape-neutral.

### Risk 5: Financial-statement presentation manifest may need bespoke renderer

**Severity**: Low-Medium
**Mitigation**: The Balance Sheet / P&L / Cash Flow layouts are
hierarchical templates that the generic `CnIndexPage` can't render.
Two paths: (a) a dedicated `CnReportPage` component in
`@conduction/nextcloud-vue` takes a presentation manifest and renders
any statement; (b) a short bespoke Vue file per statement type. Path
(a) preserves ADR-024 Tier-4 and is preferred. Decision lives in
`bookkeeping-financial-statements/design.md` discovery.

### Risk 6: Backdating prevention vs operator correction workflow

**Severity**: Medium
**Mitigation**: Once a period is closed, ALL postings dated within
that period are rejected. Operators must reopen the period (requires
elevated role + audit-trailed reason) to post a correction. This is
industry-standard and matches Exact / AFAS / Twinfield behaviour.
The `bookkeeping-period-close` spec declares the reopen workflow +
role gate.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on the spec.

After implementation (separate cycle), rollback follows the standard
pattern: revert the implementing PR, run the repair step in
down-direction. Registers are non-destructive — unused schemas
remain queryable but unreferenced; operational data (vendor
invoices, customer invoices, bank statements, fiscal periods)
remains in storage. The T2 capabilities chain via `depends_on`;
partial rollback (e.g. keeping AP but reverting bank-reconciliation)
is supported because the AP spec does not depend on bank-reconciliation.

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
5. **Audit-trail UI placement** — should the audit surface live
   under a dedicated `Bookkeeping > Audit` nav entry, or as a side
   panel on every bookkeeping detail page? Confirmed during the
   implementing cycle's UX review; the spec declares the
   capability and leaves placement to the manifest patch.
