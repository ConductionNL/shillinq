# Design — Bookkeeping Advanced Engine (T4)

## Context

Shillinq's 5-tier bookkeeping rollout (see `proposal.md`) reaches **T4
— advanced engine** after T1 lays the trustworthy double-entry ledger,
T2 adds the AP/AR/bank/cash sub-ledgers, and T3 adds fiscal periods,
financial statements, bank reconciliation, and VAT/BTW filing. T4 is
where the engine becomes production-grade for Dutch SMB, government,
and group consolidation use: legal annual filings, asset accounting,
multi-currency operations, analytical accounting, fiscal-year close,
PSD2 bank connectivity, and the operational visibility layer
(reconciliation reports).

Bundling these seven capabilities is intentional. Each capability either
depends on or shares the same OpenRegister abstractions
(`x-openregister-lifecycle`, `-aggregations`, `-calculations`,
`-notifications`, scheduled-workflow, mappings, RBAC,
approval-workflow) and the same openconnector integration pattern
(Digipoort, PSD2 aggregators, ECB rates). Splitting into seven separate
changes would multiply the planning + dependency-management overhead
without buying isolation that the per-capability spec folders already
provide.

The change is **spec-only**. Implementation lands later through
per-capability `opsx-apply` cycles and the standard Hydra pipeline;
this doc explains *why* the shape of each capability is what it is.

## Goals

- Express every T4 surface as **declarative metadata** —
  schemas + `x-openregister-*` blocks + manifest entries — per
  ADR-031. No new PHP service classes for state machines,
  aggregations, calculations, or notifications. PHP guards remain
  legitimate as ADR-031 exceptions, single-method, ~20 LOC.
- Consume every OpenRegister abstraction that already exists for
  audit trail, RBAC, approval workflow, mappings, scheduled
  workflows, notifications — per ADR-022. No reimplementation in
  shillinq.
- Consume openconnector for every cross-system integration —
  Digipoort SBR submission, ECB FX rates, PSD2 aggregator AIS pulls
  — per ADR-022. shillinq carries no HTTP clients for external
  systems.
- Keep T4 narrow enough that the operator UX layer (T5 sector
  specialisations, future bookkeeper personas) can layer on top
  without reshaping T4's schemas.
- Make each capability spec a **competent-bookkeeper readable
  contract** — a Dutch SMB or municipal accountant should recognise
  the model as a faithful production-grade bookkeeping system.

## Non-Goals

- No intercompany eliminations or full group consolidation
  (multi-currency declares IAS 21 translation rules in REQ-MC-005
  because translation is unavoidable; eliminations are T5).
- No WBSO time tracking — `bookkeeping-cost-centers-dimensions`
  pre-positions `time-per-project` (REQ-CC-007); the WBSO capability
  itself is T4-specialized future work.
- No sector-specific templates beyond the three RGS variants from T1.
- No frontend Vue components beyond the generic `CnIndexPage` /
  `CnDetailPage` from `@conduction/nextcloud-vue` bound through
  `src/manifest.json`. Bespoke views are deferred until a real need
  appears.
- No PHP code authored in this change (the spec-only intent is
  explicit; tasks reference where guards land *if* needed).

## Decisions

### D1 — Declarative-first, per ADR-031, across all seven capabilities

Every T4 behaviour expressible as schema metadata MUST be declared
in `lib/Settings/shillinq_register.json`, not authored as a PHP
service. Concretely:

| Behaviour | Declarative form |
|---|---|
| XBRL instance state machine (draft → validated → submitted → accepted / rejected) | `x-openregister-lifecycle` on `XbrlInstance` |
| Asset state machine (proposed → active → disposed → archived) | `x-openregister-lifecycle` on `FixedAsset` |
| Depreciation derived fields (`currentBookValue`, `monthlyDepreciation`, `commercialBookValue`, `fiscalBookValue`) | `x-openregister-calculations` on `FixedAsset` |
| Fiscal-year close (open → closing → closed → reopened) | `x-openregister-lifecycle` on `FiscalYear` |
| Bank connection lifecycle (pending → active → expiring → expired / revoked) | `x-openregister-lifecycle` on `BankConnection` |
| Consent expiry warning (auto-transition 14 days before expiry) | `x-openregister-lifecycle` time-based transition on `BankConnection` |
| New-transaction notifications | `x-openregister-notifications` on `BankStatement` |
| Daily ECB FX-rate ingestion | OR `ScheduledWorkflow` + n8n adapter (ADR-031 path 2) |
| Monthly depreciation posting | OR `ScheduledWorkflow` + n8n adapter (ADR-031 path 2) |
| Bank transaction polling | OR `ScheduledWorkflow` + n8n adapter (ADR-031 path 2) |
| Period-end FX revaluation | OR `ScheduledWorkflow` + n8n adapter (ADR-031 path 2) |
| Cost-allocation rules (per-posting / monthly / period-close cadence) | `AllocationRule` schema + `x-openregister-lifecycle` action on `GLTransaction.post` (per-posting) or `ScheduledWorkflow` (monthly / period-close) |
| Segment P&L roll-up | `x-openregister-aggregations` on `GLLine` keyed by dimension |
| Sub-ledger ↔ GL reconciliation | OR `SavedQuery` / `x-openregister-aggregations` |
| Intercompany match | OR `SavedQuery` joining administrations |
| Budget-vs-actual variance | OR `SavedQuery` joining `GLLine` aggregations to `Budget` |
| XBRL line → taxonomy mapping | OR `Mapping` record consumed via Mappings abstraction (ADR-022) |
| Audit trail of every transition | OR audit-trail-immutable (no schema config required) |
| Year-end re-open RBAC guard | OR authorization `admin` role reference (ADR-022) |
| Year-end re-open approval requirement (reason) | `x-openregister-lifecycle` precondition declaring required field |

**Alternative considered**: Author seven PHP services (`XbrlReportService`,
`DepreciationService`, `FxRateService`, `AllocationService`,
`YearEndCloseService`, `BankConnectorService`, `ReconciliationService`)
mirroring Exact / Twinfield / AFAS style. Rejected per ADR-031 — that
would replicate exactly the anti-pattern decidesk's MotionService /
VotingService / QuorumService are mid-migration away from. T4 starts
clean.

### D2 — XBRL document is a transformation on top of `FinancialStatement`, not a re-aggregation

The XBRL instance MUST consume T3's already-balanced
`FinancialStatement` object and map each line to the configured
NL-taxonomie concept via a `Mapping` record. The alternative —
re-aggregating ledger lines per XBRL concept — would risk drift
between the XBRL filing and the operator-visible financial statement.
Single source of truth.

Submission of the XBRL instance to Digipoort is consumed from
openconnector by source slug (per ADR-022). No Digipoort HTTP client,
no WS-Security certificate handling, no SMPP/SMP protocol code in
shillinq.

**Alternative considered**: Embed a Digipoort SOAP client and
WS-Security stack in shillinq for direct submission. Rejected — that
duplicates openconnector's reason for existing and forces shillinq
to track Digipoort protocol changes. Per ADR-022, every cross-system
integration that a sibling app can provide MUST be consumed from
that sibling.

### D3 — Fixed-asset depreciation is a derived field, not a materialised schedule

Per ADR-031's `isOverdue`-on-decidesk-ActionItem pattern,
depreciation values are derivable on demand from the asset's fields
(`acquisitionCost`, `residualValue`, `usefulLifeMonths`,
`depreciationMethod`, etc.) plus the current date. No persisted
schedule table; no `DepreciationScheduleService` materialising
per-month rows. The monthly posting workflow reads the derived
field for the current period and emits the GL posting.

Parallel commercial vs fiscal streams are two `x-openregister-calculations`
fields (`commercialBookValue`, `fiscalBookValue`) computed from the
same source fields with different rates. Each posts to a dedicated
sub-account or `bookSet` dimension so the trial balance can filter.

**Alternative considered**: Materialise a `DepreciationSchedule`
table per asset with one row per month. Rejected — that's the
ADR-031 anti-pattern (storing what can be calculated), wastes space,
and creates a synchronisation surface (asset edited → schedule must
be regenerated). Derived fields stay fresh by definition.

### D4 — Multi-currency `GLLine` extension is additive, single-currency callers stay correct

Per the T1 spec, every `GLLine` carries `amount` and `currency`.
T4's multi-currency extension treats `amount` as
`transactionAmount` in `transactionCurrency`, and adds
`baseCurrencyAmount` / `baseCurrency` / `fxRate` / `fxRateSource` /
`fxRateDate` so the trial balance and statements always have a
single-currency view to aggregate. For single-currency postings,
`fxRate = 1.0` and `baseCurrencyAmount = amount`.

This shape avoids a destructive migration of T1 data and keeps T2
sub-ledgers and T3 statements compatible (they read
`baseCurrencyAmount` for aggregation, `transactionAmount` for
display).

**Alternative considered**: Split `GLLine` into `GLLineTransaction`
+ `GLLineBase` to avoid mixing currencies in one row. Rejected —
the row-per-currency-view shape forces every aggregation to join
two schemas and the maintenance cost is high. Additive fields are
the canonical ADR-001 pattern.

### D5 — Cost-allocation rules are schema metadata, not a service

The `AllocationRule` register declares the rule shape (source
pattern, driver, targets, target dimension, cadence). The cadence
field routes execution: `per-posting` rules fire as an
`x-openregister-lifecycle` action on `GLTransaction.post`;
`monthly` / `period-close` rules fire from an OR
`ScheduledWorkflow`. Either way, no PHP `AllocationService`
orchestrates the rule — the rule body lives in the schema and the
execution shape is declarative.

The constraint that fixed-percentage targets sum to 100 is
expressible as an `x-openregister-lifecycle` precondition on
`AllocationRule.save` (per ADR-031). The cross-line balance
constraint emitted when the rule splits a transaction is the same
constraint T1 declared on `GLTransaction.post` — declarative
re-use, no duplication.

### D6 — Year-end close is the highest-stakes operation; declarative makes it auditable

Year-end close emits two journals (retained-earnings transfer in
year N, opening-balance journal in year N+1) and a dimensional
rollover. Each is a lifecycle action on `FiscalYear` (per
REQ-YEC-003 / -004 / -005). The audit chain is consumed from
OR's audit-trail-immutable (no app config), making every step
queryable post-hoc.

Re-opening a closed year is the operational escape hatch. Per
REQ-YEC-006, the `closed → reopened` transition is admin-only
(consuming OR's RBAC `admin` role per ADR-022), requires a
non-empty `reopenReason`, and emits two reversing `JournalEntry`
records that pair with the original closing + opening journals.
The audit chain is fully traceable; an auditor can reconstruct
the close/reopen history from the audit trail alone.

**Alternative considered**: Make year-end close irreversible with
an "emergency admin override" outside the lifecycle. Rejected —
that bypasses the audit chain and creates an audit blind spot.
The declared reopen path keeps everything visible.

### D7 — PSD2 credentials live in openconnector, NC AppConfig holds shillinq-side settings only

Aggregator OAuth client id/secret, refresh tokens, and consent-
flow state MUST live in openconnector's `Source` registry — that's
what openconnector is for. shillinq's `BankConnection` record
carries only the consent reference (a non-credential identifier
issued by the aggregator) and the source slug pointing at the
openconnector source.

shillinq-side connector settings (default aggregator for new
administrations, default consent-renewal notification recipient)
live in NC's `IAppConfig` via the existing
`SettingsController` / `SettingsService`. No `shillinq_bank_*`
database table.

This split makes credential rotation an openconnector operation
(no shillinq deploy needed) and keeps shillinq's data model
credential-free (auditor-friendly: no risk of leaking secrets via
register exports).

### D8 — Reconciliation reports are aggregation queries, not a report engine

Every reconciliation report (sub-ledger ↔ GL, intercompany, variance,
controller exceptions) is declared as an
`x-openregister-aggregations` query on a `SavedQuery` record. The
queries are consumed by:

- launchpad dashboard widgets via runtime GraphQL (per
  `feedback_launchpad-no-or-dependency.md`)
- the shillinq manifest detail page that surfaces the report

No PHP `ReportingService.generateReconciliation()` exists; the same
aggregation serves both consumers. The severity classification on
exception rows (`critical` / `warning` / `info`) is encoded as a
calculated field, not as PHP logic.

This is the canonical launchpad-no-shillinq-dep shape: shillinq publishes
saved queries against OR registers; launchpad discovers them through
the GraphQL schema; neither app imports the other.

## Reuse Analysis

| Capability needed | What already exists | T4 reuse strategy |
|---|---|---|
| XBRL instance state machine | `x-openregister-lifecycle` (ADR-031) | Declarative — no PHP state machine |
| Digipoort submission HTTP path | openconnector `Source` registry | Consumed by slug (ADR-022); no embedded client |
| NL-taxonomie line→concept mapping | OR `Mapping` register + Mappings abstraction (ADR-022) | One `Mapping` record per (entry point, taxonomy version) |
| Fixed-asset depreciation values | `x-openregister-calculations` (ADR-031) | Derived fields, no schedule table |
| Monthly depreciation posting run | OR `ScheduledWorkflow` + n8n adapter (ADR-031 path 2) | Operator-configurable cadence; no per-app TimedJob |
| Disposal closing journal | `x-openregister-lifecycle` action on `FixedAsset.active → disposed` | Declarative — emits `JournalEntry` via CloudEvent |
| FX rate storage | New `FxRate` register | T4 adds the register; rates loaded via scheduled-workflow |
| ECB daily rate ingestion | openconnector source + OR `ScheduledWorkflow` | Workflow calls openconnector source by slug, upserts `FxRate` |
| Period-end FX revaluation | OR `ScheduledWorkflow` triggered on period close | Reads open foreign-currency positions, emits balanced GL postings; no PHP service |
| IAS 21 functional-currency translation for consolidation | OR `Mapping` abstraction | Mapping references rate register; no `ConsolidationTranslationService` |
| Cost-center / project / dimension storage | New registers (`CostCenter`, `KostenDrager`, `Project`) | Declared the same way as T1 `Account`; same RBAC + audit + lifecycle |
| Custom dimensions | OR register abstraction (ADR-022) | Operator declares a custom dimension register; `GLLine.dimensions` free-form map validates against it via relations engine |
| Cost-allocation rule storage | New `AllocationRule` register | Schema-declared per ADR-031; cadence routes execution to lifecycle action or scheduled workflow |
| Segment P&L aggregation | `x-openregister-aggregations` on `GLLine` (ADR-031) | Keyed by dimension; consumed by launchpad + manifest pages |
| Fiscal-year storage | New `FiscalYear` register | Declared per ADR-024; lifecycle drives the close |
| Retained-earnings transfer journal | T1 `JournalEntry` (manual sub-type) | Emitted by `FiscalYear.open → closing` action; consumes T1 |
| Opening-balance journal in next year | T1 `JournalEntry` (manual sub-type) | Emitted by `FiscalYear.closing → closed` action; consumes T1 |
| Dimensional rollover at year-end | OR CloudEvents | Lifecycle action emits CloudEvents per dimension register |
| Year-end re-open RBAC guard | OR authorization `admin` role | Referenced from `x-openregister-lifecycle` per ADR-022 |
| Reverse-and-reopen audit chain | T1 `JournalEntry` (reversing sub-type) | Emitted by `FiscalYear.closed → reopened` action |
| PSD2 aggregator integration | openconnector `Source` registry | Consumed by slug per ADR-022; no aggregator HTTP client in shillinq |
| Bank transaction CAMT.053 generation | OR `ScheduledWorkflow` (workflow normalises aggregator JSON) | Workflow attaches the CAMT.053 via docudesk |
| Bank connection lifecycle | `x-openregister-lifecycle` on `BankConnection` | Declarative; consent expiry warning is time-based auto-transition |
| New-transaction notifications | `x-openregister-notifications` on `BankStatement` | Declarative recipient resolution + channel fan-out per ADR-031 |
| Sub-ledger ↔ GL reconciliation | OR `SavedQuery` / `x-openregister-aggregations` | Saved-query records; consumed by launchpad + manifest pages |
| Intercompany match | OR `SavedQuery` joining administrations | Shape-neutral spec; engine-dependency resolution in opsx-ff |
| Variance analysis vs budget | OR `SavedQuery` joining `GLLine` aggregations to `Budget` | Threshold check encoded as calculated field |
| Controller exception report | OR `SavedQuery` consolidating REQ-RR-002/003/004 outputs | Severity classification as calculated field |
| Audit trail | OR audit-trail-immutable | Consumed automatically (no schema config) |
| RBAC | OR authorization | Per-schema role definitions; `admin` referenced for year-end reopen, `controller` for reconciliation reports, `treasurer` for bank connections |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4 adopted on `feature/adopt-app-manifest`) | T4 adds 11+ menu entries + matching `type: index` / `type: detail` pages |
| LaunchPad consumption | launchpad runtime GraphQL on OR (per `feedback_launchpad-no-or-dependency.md`) | shillinq publishes saved queries; launchpad discovers via GraphQL schema; no install-time dep |
| Seed data import | `ConfigurationService::importFromApp()` (per shillinq config.yaml `design` rule) | Repair-step pattern from T1; extended for NL-taxonomie mappings + allocation-rule defaults |

**Net new code in T4 implementation**: ~10 schema declarations + 11+
manifest pages + N seed JSON files (NL-taxonomie mappings per entry
point + allocation-rule defaults). Possibly 1-3 short PHP lifecycle
or aggregation guards (~20 LOC each, single-method) if engine-
dependency risks confirm (Risk 1 in `proposal.md`).

## Declarative-vs-imperative decision (per ADR-031)

Per ADR-031 enforcement, each T4 behaviour was classified before this
spec was finalised. Single big table here so the reviewer can sweep
all seven capabilities in one read.

| Capability | Behaviour | Decision | Why |
|---|---|---|---|
| sbr-xbrl-reporting | XBRL state machine | Declarative (`x-openregister-lifecycle`) | Pure state machine |
| sbr-xbrl-reporting | Digipoort submission HTTP | Consumed from openconnector (ADR-022) | Sibling app provides the integration |
| sbr-xbrl-reporting | Line → concept mapping | Consumed from OR Mappings (ADR-022) | Abstraction exists |
| fixed-assets-depreciation | Asset state machine | Declarative | Same |
| fixed-assets-depreciation | Depreciation values | Declarative (`x-openregister-calculations`) | Derived from existing fields |
| fixed-assets-depreciation | Monthly depreciation run | OR `ScheduledWorkflow` (ADR-031 path 2) | Periodic external trigger needed |
| fixed-assets-depreciation | Disposal closing journal | Declarative (lifecycle action emits T1 `JournalEntry`) | Composition of existing primitives |
| multi-currency | FX rate storage | Declarative (new register) | Schema declaration |
| multi-currency | ECB rate ingestion | OR `ScheduledWorkflow` + openconnector source (ADR-031 path 2 + ADR-022) | Periodic external pull |
| multi-currency | Period-end revaluation | OR `ScheduledWorkflow` triggered on period close | Periodic batch work |
| multi-currency | Realised gain/loss on settlement | Declarative (lifecycle action on sub-ledger record) | Per-event computation |
| multi-currency | IAS 21 consolidation translation | Consumed from OR Mappings (ADR-022) | Abstraction exists |
| cost-centers-dimensions | Dimension storage (cost-center, kostendrager, project, custom) | Declarative (new registers) | Schema declarations |
| cost-centers-dimensions | Hierarchy navigation | Declarative (`x-openregister-relations` self-relation) | Standard relation shape |
| cost-centers-dimensions | Allocation rule storage | Declarative (new `AllocationRule` register) | Schema declaration |
| cost-centers-dimensions | Allocation rule execution (per-posting) | Declarative (`x-openregister-lifecycle` action on `GLTransaction.post`) | Composition of existing primitives |
| cost-centers-dimensions | Allocation rule execution (monthly / period-close) | OR `ScheduledWorkflow` (ADR-031 path 2) | Periodic batch work |
| cost-centers-dimensions | Segment P&L aggregation | Declarative (`x-openregister-aggregations`) | Single-schema aggregation |
| year-end-close | Fiscal-year state machine | Declarative | Pure state machine |
| year-end-close | Retained-earnings transfer journal | Declarative (lifecycle action emits T1 `JournalEntry`) | Composition of existing primitives |
| year-end-close | Next-year opening-balance journal | Declarative (lifecycle action emits T1 `JournalEntry` in next-year `FiscalYear`) | Composition of existing primitives |
| year-end-close | Dimensional rollover | Declarative (lifecycle CloudEvents consumed by dimension registers) | Composition of existing primitives |
| year-end-close | Reopen RBAC guard | Consumed from OR RBAC (ADR-022) | Abstraction exists |
| year-end-close | Reverse-and-reopen audit chain | Declarative (lifecycle action emits T1 reversing `JournalEntry` records) | Composition of existing primitives |
| bank-connectors | Aggregator integration | Consumed from openconnector (ADR-022) | Sibling app provides the integration |
| bank-connectors | Connection state machine + consent-expiry warning | Declarative (`x-openregister-lifecycle` with time-based transition) | State machine + time predicate |
| bank-connectors | Transaction polling | OR `ScheduledWorkflow` (ADR-031 path 2) | Periodic external pull |
| bank-connectors | New-transaction notifications | Declarative (`x-openregister-notifications`) | Abstraction fits |
| reconciliation-reports | All reports | Declarative (`x-openregister-aggregations` / `SavedQuery`) | Aggregation queries |
| reconciliation-reports | Severity classification | Declarative (calculated field) | Pure function of row data |
| reconciliation-reports | launchpad consumption | Runtime GraphQL on OR (per `feedback_launchpad-no-or-dependency.md`) | launchpad discovers via GraphQL schema; no install-time dep |
| All capabilities | Audit trail | Consumed from OR audit-trail-immutable (ADR-022) | Abstraction exists |
| All capabilities | RBAC | Consumed from OR authorization (ADR-022) | Abstraction exists |

No service class authored in this T4 envelope. If the
intercompany-match, budget-variance-join, or cross-administration
aggregation needs a thin PHP guard, it is a single-method `~20 LOC`
file in `lib/Aggregation/` and explicitly cited as an ADR-031
exception in the implementing cycle's design doc.

## Seed Data

T4 ships two classes of seed under `lib/Settings/seeds/`:

### NL-taxonomie mapping seeds (per SBR entry point + taxonomy version)

| File | Purpose | Approximate row count |
|---|---|---|
| `sbr-mappings/kvk-jaarrekening-nt17.json` | KvK deponering jaarrekening — NL-taxonomie nt17 | ~200 line→concept rows |
| `sbr-mappings/kvk-jaarrekening-nt18.json` | KvK deponering jaarrekening — NL-taxonomie nt18 | ~210 rows |
| `sbr-mappings/belastingdienst-vpb-nt17.json` | Aangifte vennootschapsbelasting — nt17 | ~150 rows |
| `sbr-mappings/belastingdienst-vpb-nt18.json` | Aangifte vennootschapsbelasting — nt18 | ~160 rows |
| `sbr-mappings/belastingdienst-ib-nt17.json` | Aangifte inkomstenbelasting (winst uit onderneming) — nt17 | ~100 rows |
| `sbr-mappings/sbr-banken-kredietrapportage-nt17.json` | SBR-banken kredietrapportage — nt17 | ~80 rows |
| `sbr-mappings/sbr-wonen-nt17.json` | SBR-Wonen (housing-corporation reporting) — nt17 | ~120 rows |

Format: a JSON array of mapping records each carrying
`{financialStatementLineId, taxonomyConcept, contextRef, unitRef}`.
Loaded via `ConfigurationService::importFromApp()` in the repair
step. Per-administration override is allowed: operators may edit
mappings to reflect company-specific extension concepts.

### Allocation-rule seed shapes

| File | Purpose | Approximate row count |
|---|---|---|
| `allocation-rules/overhead-by-headcount.json` | Default headcount-driver overhead spread example | 1 |
| `allocation-rules/it-by-volume.json` | Default volume-driver IT-spend allocation example | 1 |
| `allocation-rules/facility-by-fixed-percentage.json` | Default fixed-percentage facility-cost allocation example | 1 |

These are *example shapes*, not active rules — they ship in
`lifecycleState: paused` so operators can review and activate. The
implementing cycle's UX includes a `Try it` action that flips them
to `active` after operator confirmation.

Each seed file's top of file carries:

- SPDX header (EUPL-1.2 + Copyright Conduction B.V.) per
  `feedback_spdx-in-docblock.md`.
- An `_meta` block (`{ "_meta": { "source": "NL-taxonomie", "variant":
  "kvk-jaarrekening", "taxonomyVersion": "nt18", "imported":
  "<iso-timestamp>" } }`) so a future migration to a newer
  taxonomy version can identify which records were template-sourced
  versus operator-authored.

No seed data for `XbrlInstance`, `FixedAsset`, `FxRate` (loaded by
the daily workflow), `CostCenter` / `KostenDrager` / `Project` /
`AllocationRule` actual rules (administration-specific),
`FiscalYear` (auto-created), `BankConnection` (operator-configured),
or `Budget` (administration-specific) — those are accumulated through
operation.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| OR's aggregation engine cannot express the cross-administration intercompany match declaratively | Document as OR issue; thin single-method PHP guard per ADR-031 §"PHP guards remain a legitimate seam"; specs (REQ-RR-003) are shape-neutral |
| openconnector source slugs for Digipoort + ECB + PSD2 may not yet be production-ready | Implementing cycle verifies source availability before manifest validator runs; if missing, opens openconnector issue and the relevant capability spec stays shape-neutral |
| PSD2 SCA renewal every 90 days is operator-disruptive | Declarative 14-day warning auto-transition + notification + one-click reauthorise routing through openconnector. Bounded UX. |
| NL-taxonomie versions evolve | `XbrlInstance.taxonomyVersion` pinned per instance; mappings versioned in filename; coexistence trivial |
| Year-end close is highest-stakes; wrong implementation corrupts downstream periods | Declarative implementation (no service class); designed admin-only reverse-and-reopen escape hatch (REQ-YEC-006); integration tests on the implementing PR |
| Fixed-asset commercial / fiscal divergence edge cases (Wet IB caps) | Parallel-stream design (REQ-FA-004) matches Exact / AFAS practice; bookkeeper-persona review required before implementing PR merges |
| Cost-allocation driver scope creep | Four named drivers shipped (`fixed-percentage`, `fixed-amount`, `volume`, `headcount`); driver enum is extensible additively; custom drivers require OR issue |
| Bank-connector credentials accidentally land in shillinq | REQ-BC-003 forbids credentials in shillinq schemas; REQ-BC-001 forbids HTTP clients; reviewer gates on grep for these patterns; openconnector owns credentials |
| Multi-currency rounding (negative-zero, € 0.005 banker's rounding) | T1 already encodes signs in `side` enum (no negative numbers); T4 multi-currency follows same; rounding convention documented in implementing cycle's tests |
| Reports become a parallel reporting engine via creep | REQ-RR-001 hard-forbids report-engine services; reports MUST be `SavedQuery` / `x-openregister-aggregations`; reviewer gate; launchpad is the canonical visualisation surface |

## Migration Plan

Spec-only — no runtime migration in this change. When per-capability
implementation lands:

1. `lib/Settings/shillinq_register.json` is patched with the new
   schemas per capability (additive — no existing schema changes).
2. Multi-currency adds optional fields to T1 `GLLine` additively; no
   migration of existing data.
3. Cost-centers-dimensions adds optional dimension fields to T1
   `GLLine` additively; no migration.
4. `src/manifest.json` is patched per capability with new menu
   entries + index/detail page pairs (additive).
5. The repair step is extended per capability to seed NL-taxonomie
   mappings + allocation-rule examples.
6. ADR-000 gains one-paragraph annotations per capability noting the
   new entities and their relationship to existing ones (e.g.
   `FixedAsset` is referenced from `GLLine.subLedgerRef` when
   `subLedgerType = fixed-asset`).

Down-direction: registers are non-destructive — disabling the seed
import + reverting the manifest leaves stranded but queryable
records. Per-capability rollback affects only the relevant register;
the other six capabilities remain operational.

## Open Questions

1. **Cross-administration intercompany aggregation** — resolved in
   `opsx-ff` discovery on `bookkeeping-reconciliation-reports`. If
   no engine support: thin PHP guard, documented.
2. **Single-true `isClosingAccount` enforcement** — confirm during
   `opsx-ff` on `bookkeeping-year-end-close` whether OR has a native
   single-true validator (T1's REQ-CoA-009 left this open).
3. **PSD2 aggregator default selection per administration** — UX
   detail settled in the implementing cycle's UX review.
4. **NL-taxonomie auto-upgrade policy** — operator-controlled per
   administration; default policy lives in administration settings,
   not the schema.
5. **FX revaluation cadence** — period-end is the default per
   REQ-MC-004; operator-selectable monthly revaluation deferred to
   future enhancement.
6. **Bookkeeper-persona review for fixed-asset Wet IB rules** —
   scheduled before the implementing PR for
   `bookkeeping-fixed-assets-depreciation` merges; persona
   `/test-persona-janwillem` (SMB owner) plus a domain-expert
   accountant review.
