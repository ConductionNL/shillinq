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
UI. Live PSD2 bank initiation is T4.

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
the existing PDF utility bound through a manifest action.

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
| PDF export | `@conduction/nextcloud-vue` PDF utility | Manifest-driven action button; no shillinq-side PDF code |
| Audit trail per register | OR audit-trail-immutable (ADR-022) | Every T2 register declares `x-openregister-audit: true`; no app-local audit |
| Audit UI surface | OR audit-log UI | Pre-filtered manifest entry in `src/manifest.json` — no shillinq code |
| Source-document attachment | docudesk (ADR-022) | FK by URI per `bookkeeping-document-attachment-integration` spec; mime-type contract per role; no shillinq file storage |
| Bank statement parsing | OR `x-openregister-calculations` for CAMT.053 + MT940 (if extension supports); else a single-method `OCA\Shillinq\Lifecycle\StatementParser` per ADR-031 exception | Parser path resolved during `opsx-ff` discovery; spec is shape-neutral |
| Bank matching rules | OR `x-openregister-aggregations` consuming declarative match predicates | Rule predicates declared as schema metadata on `MatchingRule`; aggregation emits candidate matches; operator confirms |
| Suspense account routing | T2 references T1's `Account` register | A designated account flagged `isSuspenseAccount: true` (additive field on T1's Account schema — or carried as an administration setting); unmatched lines post against it |
| Vendor/customer master | New T2 registers | `VendorMaster` + `CustomerMaster` — domain models. Cross-app linkage to OR's `contact` abstraction if stable per ADR-022 (else app-local for now with a migration plan) |
| Manifest navigation | T1's already-adopted Tier-4 manifest | T2 adds 7 navigation entries (trial-balance, period-close, AP, AR, financial-statements, audit-trail, bank-reconciliation); document-attachment ships as a side panel, no top-level nav |
| Lifecycle engine | `x-openregister-lifecycle` (ADR-031) | All T2 lifecycle-bearing schemas declare it |
| Aggregation engine | `x-openregister-aggregations` (ADR-031) | Trial balance, AR aging, AP aging, Cash Flow all aggregations |
| RBAC | OR authorization | Per-schema role definitions: `bookkeeper`, `ap-approver`, `ar-controller`, `treasurer`, `auditor` |

**Net new code in T2 implementation**: ~11 schema declarations +
~7 manifest entries + ~3 statement presentation manifests. At most
3 single-method PHP lifecycle guards (`ThreeWayMatchGuard`,
`DunningGuard`, `StatementParser`) gated by ADR-031 exception,
each ~20 LOC.

## Declarative-vs-imperative decision (per ADR-031)

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

**Example seed objects (Dutch values):**

`rj270-balance-sheet.json` (extract):
```json
{
  "_meta": { "source": "RJ 270 (2026)", "variant": "smb", "imported": "" },
  "sections": [
    { "code": "BA", "label": "Vaste activa", "accountRange": ["0100", "0999"], "sign": "debit" },
    { "code": "CA", "label": "Vlottende activa", "accountRange": ["1000", "1999"], "sign": "debit" },
    { "code": "EQ", "label": "Eigen vermogen", "accountRange": ["5000", "5999"], "sign": "credit" },
    { "code": "LT", "label": "Langlopende schulden", "accountRange": ["4000", "4499"], "sign": "credit" },
    { "code": "ST", "label": "Kortlopende schulden", "accountRange": ["4500", "4999"], "sign": "credit" }
  ]
}
```

`rj270-pl.json` (extract):
```json
{
  "_meta": { "source": "RJ 270 (2026)", "variant": "smb", "imported": "" },
  "sections": [
    { "code": "NET", "label": "Netto omzet", "accountRange": ["8000", "8099"], "sign": "credit" },
    { "code": "COGS", "label": "Kostprijs omzet", "accountRange": ["7000", "7099"], "sign": "debit" },
    { "code": "OPEX", "label": "Bedrijfskosten", "accountRange": ["7100", "7999"], "sign": "debit" },
    { "code": "TAX", "label": "Vennootschapsbelasting", "accountRange": ["8900", "8999"], "sign": "debit" },
    { "code": "NET_RESULT", "label": "Nettoresultaat", "accountRange": ["8990", "8990"], "sign": "credit" }
  ]
}
```

Each statement-manifest file carries:
- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- An `_meta` block so a future migration to a different presentation
  standard (BBV — T3; IFRS full — T5) can identify which records
  were template-sourced versus operator-authored.

BBV-conformant statement manifests are explicitly T3.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR aggregation engine cannot express opening/closing buckets in one query | Per-spec design discovery resolves "one query vs three composed"; both paths are declarative and acceptable |
| OR dunning-workflow not yet stable at T2 implementation time | Spec shape-neutral; PHP guard fallback (`DunningGuard`, single-method, ~20 LOC) per ADR-031 exception; remove when OR extension lands |
| PO / GR registers not available for 3-way match | AP spec is conditional: 2-way match when PO/GR absent; FKs declared so future T4 procurement capability attaches additively |
| Reopening a closed period is destructive | Reopen requires elevated role + audit-trailed reason; original close timestamp + actor preserved in audit; matches industry-standard behaviour |
| Financial-statement renderer (`CnReportPage`) not yet in nextcloud-vue | Spec shape-neutral; falls back to a short bespoke Vue per statement type if library doesn't ship in time; library path is preferred |
| CAMT.053 / MT940 format drift across Dutch banks | Use battle-tested OR parsing extension if available; per-bank quirks handled through matching-rule customisation rather than parser changes |
| Vendor / customer master overlaps with OR's `contact` abstraction | Per ADR-022, prefer the OR abstraction; T2 declares the bookkeeping-side fields as a thin view onto contacts if the OR abstraction is stable; otherwise app-local with documented migration plan |
| Audit trail UI placement (dedicated nav vs side panel) | Spec declares the capability; placement resolved during implementing cycle's UX review |
| Three single-method PHP guards may grow into services | Reviewer guidance + explicit "single-method, ~20 LOC, ADR-031 §exception" annotation in each guard's file header |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation
lands:

1. `lib/Settings/shillinq_register.json` is patched with ~11 new
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
chain `depends_on` graph means partial rollback is supported.

## Open Questions

1. **Opening/closing bucket aggregation shape** — resolved in
   `bookkeeping-trial-balance/spec.md` discovery before implementing
   cycle.
2. **Dunning-workflow stability on OR** — resolved in
   `bookkeeping-accounts-receivable-core/spec.md` discovery; OR issue
   filed if needed.
3. **PO/GR availability at T2 implementation time** — `bookkeeping-accounts-payable-core`
   is conditional; current assumption is PO/GR ships T4 alongside
   procurement.
4. **`CnReportPage` library availability** — resolved in
   `bookkeeping-financial-statements/spec.md` discovery; nextcloud-vue
   roadmap item.
5. **Vendor/customer master vs OR contact abstraction** — resolved
   per ADR-022 review during the implementing cycle.
6. **Audit-trail UI placement** — confirmed during implementing
   cycle's UX review.
7. **Suspense account designation: schema flag vs administration
   setting** — resolved in `bookkeeping-bank-reconciliation/spec.md`
   discovery.
8. **CAMT.053 / MT940 parser path** — declarative extension or
   `StatementParser` guard, resolved in
   `bookkeeping-bank-reconciliation` discovery.
