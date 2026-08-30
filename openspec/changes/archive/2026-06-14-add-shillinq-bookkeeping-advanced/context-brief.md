# Proposal: add-shillinq-bookkeeping-advanced

`kind: config` per ADR-032 — the centre of mass is declarative
schema metadata + manifest entries + a small amount of seed data
(NL-taxonomie mappings, allocation-rule shapes). No PHP service
classes are authored.

## Summary

Introduce the **Tier 4 advanced bookkeeping-engine capabilities** for
Shillinq as a single multi-capability change. This adds seven new
declarative capabilities — SBR/XBRL annual reporting, fixed-assets &
depreciation, multi-currency postings & revaluation, cost-centers /
projects / custom dimensions with allocation rules, fiscal-year close
with re-opening guard, PSD2 bank connectors, and a reconciliation /
exception report catalogue — declared as OpenRegister registers +
schemas with `x-openregister-lifecycle` / `-aggregations` /
`-calculations` / `-notifications` rules (per ADR-031), wired into
`src/manifest.json` (per ADR-024), and consuming OpenRegister's audit,
RBAC, mappings, notifications, scheduled-workflow, and approval-workflow
abstractions and openconnector's PSD2 / Digipoort integrations (per
ADR-022) instead of reimplementing them. No PHP service classes, no
custom database tables, no bespoke Vue components — the entire T4
engine surface lands as register metadata + manifest entries + a small
amount of seed data.

This change conforms to the shared
[`nextcloud-app`](../../specs/nextcloud-app/spec.md) spec for app
structure, OpenAPI 3.0 register format, and
`ConfigurationService::importFromApp()` repair-step seeding.

## Motivation

T1 (foundation), T2 (sub-ledgers — written in parallel), and T3
(periods, statements, reconciliation — written in parallel) deliver
the trustworthy double-entry ledger plus the sub-ledger and
period/statement plumbing. T4 is the *advanced engine* layer that turns
that into a real production bookkeeping system for Dutch SMB,
government, and group consolidation use:

| T4 capability | Why it is essential |
|---|---|
| **SBR/XBRL annual reporting** | KvK deponering + Belastingdienst aangifte are legal obligations for every NL company; SBR-banken kredietrapportage and SBR-Wonen are sector mandates |
| **Fixed-assets & depreciation** | Almost every administration capitalises assets; depreciation drives both commercial books (IFRS / Dutch GAAP) and fiscal books (Wet IB / Wet VPB) which routinely diverge |
| **Multi-currency** | Any administration with foreign customers / suppliers needs FX-aware postings, revaluation, and consolidation |
| **Cost centers & dimensions** | Mandatory for segment P&L, project accounting, and pre-positions WBSO time tracking (T4-specialized) which depends on time-per-project |
| **Year-end close** | The legal closure of a fiscal year — opening-balance transfer, retained-earnings posting, dimensional rollover, controlled re-opening — is the highest-stakes operation in any bookkeeping system |
| **Bank connectors** | PSD2 AIS feeds reduce reconciliation effort from days to minutes; every modern bookkeeping product ships them |
| **Reconciliation reports** | Controllers need exception reports across sub-ledger ↔ GL, intercompany, and budget vs actual — the operational visibility layer |

See [`adr-001-bookkeeping-tier-roadmap.md`](../../architecture/adr-001-bookkeeping-tier-roadmap.md)
for the canonical 5-tier breakdown. This change delivers **Tier 4-base**:
`add-shillinq-bookkeeping-advanced`. T1
(`add-shillinq-bookkeeping-foundation`), T2
(`add-shillinq-bookkeeping-compliance`), T3
(`add-shillinq-bookkeeping-operations`), and T4-specialized
(`add-shillinq-gov-sector-mkb-advanced`) are siblings in the same PR.
T5 cross-cutting (intercompany, full consolidation, treasury, IFRS
overlay) is explicitly deferred and tracked separately.

T4 is intentionally broad because every capability here either depends
on or directly leverages an OR abstraction that already exists or is
on its way (mappings, lifecycle, aggregations, scheduled-workflow,
notifications, approval-workflow, openconnector source registry).
Bundling them lets the spec author keep cross-capability dependencies
visible (e.g. multi-currency's FxRate register feeds fixed-asset
revaluation; cost-centers feeds the segment P&L surfaced in
reconciliation reports; year-end close consumes period close).

## Affected Projects

- [x] Project: shillinq — adds 7 new capability specs (folder
  `add-shillinq-bookkeeping-advanced/specs/`). When implementation
  follows, adds ~10 new schemas to `lib/Settings/shillinq_register.json`
  (`XbrlInstance`, `FixedAsset`, `FxRate`, `CostCenter`,
  `KostenDrager`, `Project`, `AllocationRule`, `FiscalYear`,
  `BankConnection`, `Budget`), additive extensions to T1 `GLLine`
  (multi-currency fields, dimension references), 7 manifest navigation
  entries in `src/manifest.json`, and seed data for the
  `AllocationRule` defaults + the NL-taxonomie `Mapping` examples
  under `lib/Settings/seeds/`.
- [ ] Project: openregister — no source changes; this change consumes
  existing OR abstractions (lifecycle, aggregations, calculations,
  notifications, scheduled-workflow, mappings, approval-workflow,
  RBAC, audit-trail). If a needed extension shape is missing, it is
  filed as an OR issue and the gap recorded in `design.md`.
- [ ] Project: openconnector — no source changes here; this change
  *consumes* openconnector source records for Digipoort (SBR
  submission) and PSD2 aggregators (Tink / Klarna Kosma / Plaid-EU).
  Source records are administration-side configuration, not
  shillinq-side code.
- [ ] Project: docudesk — no source changes; XBRL instances reference
  attached source statements (the generated CAMT.053 from bank
  connectors, attached source documents on fixed-asset acquisition)
  by foreign-key URI per ADR-022.
- [ ] Project: launchpad — no source changes; launchpad consumes
  reconciliation reports via runtime GraphQL only (per
  `feedback_launchpad-no-or-dependency.md`); no `shillinq` dep is added.

## Scope

### In Scope

- Seven new capability specs:
  - `bookkeeping-sbr-xbrl-reporting`
  - `bookkeeping-fixed-assets-depreciation`
  - `bookkeeping-multi-currency`
  - `bookkeeping-cost-centers-dimensions`
  - `bookkeeping-year-end-close`
  - `bookkeeping-bank-connectors`
  - `bookkeeping-reconciliation-reports`
- Schema declarations for `XbrlInstance`, `FixedAsset`, `FxRate`,
  `CostCenter`, `KostenDrager`, `Project`, `AllocationRule`,
  `FiscalYear`, `BankConnection`, `Budget` — each declared as an OR
  register per ADR-024 with appropriate `x-openregister-*` metadata
  per ADR-031.
- Additive extensions to T1 `GLLine` to carry multi-currency fields
  (`baseCurrencyAmount`, `transactionCurrency`, `fxRate`, etc.) and
  dimension references (`costCenterCode`, `kostenDragerCode`,
  `projectCode`, free-form `dimensions` map).
- Manifest navigation entries (7+ index/detail page pairs) wired
  through the Tier-4 `CnAppRoot` shell already adopted on
  `feature/adopt-app-manifest`.
- Seed data for the NL-taxonomie `Mapping` examples per supported
  SBR entry point and for the default `AllocationRule` shapes.
- Audit trail consumed from OpenRegister's audit-trail-immutable
  abstraction per ADR-022 — DO NOT reimplement.
- RBAC consumed from OpenRegister's authorization abstraction per
  ADR-022 — DO NOT reimplement. Year-end re-open guard MUST cite
  the `admin` role from OR's RBAC (per REQ-YEC-006).
- Scheduled workloads (ECB rate ingestion, monthly depreciation,
  bank transaction pulls, period-end FX revaluation) declared as
  OR `ScheduledWorkflow` records per ADR-031 §"Background jobs"
  path 2.

### Out of Scope

- **Implementation code** — this is a spec-only change. PHP services,
  Vue components, controllers, tests, and CI changes are deliberately
  not in this proposal; the task list references them but the
  implementation lands via separate `opsx-apply` cycles on each
  capability.
- **Intercompany eliminations and full group consolidation** — the
  data shape is pre-positioned (cost-centers / FxRate /
  reconciliation reports), but the eliminations engine itself is
  T5's job. Multi-currency does declare IAS 21 translation rules
  (REQ-MC-005) since translation is unavoidable for FX-aware
  postings.
- **WBSO time tracking** — `bookkeeping-cost-centers-dimensions`
  pre-positions `time-per-project` (REQ-CC-007) but the actual
  WBSO capability is T4-specialized future work.
- **Sector-specific templates** beyond the three RGS variants shipped
  in T1 (SMB, ZZP, BBV) — housing-corporation, healthcare, and
  education sector specialisations live in T5.
- **Frontend Vue components** beyond what `CnIndexPage` /
  `CnDetailPage` from `@conduction/nextcloud-vue` already render
  generically from a register manifest. No bespoke Vue files in
  this spec.
- **Digipoort certificate management UI** — openconnector owns
  source credentials including WS-Security certificates; shillinq
  does not surface a credential-management UI for them.

## Approach

Seven deltas, each adding ADDED Requirements to a brand-new spec, in
dependency order:

1. **`bookkeeping-sbr-xbrl-reporting`** (depends on T3
   `bookkeeping-financial-statements` and `bookkeeping-vat-btw-filing`) — declares the
   `XbrlInstance` register with a draft/validated/submitted/accepted/
   rejected lifecycle, references the OR `Mapping` abstraction for
   line→concept mapping, and consumes openconnector's Digipoort
   source for submission. NL-taxonomie versions pinned per entry
   point.
2. **`bookkeeping-fixed-assets-depreciation`** (depends on T1 GL) —
   declares the `FixedAsset` register with depreciation rules as
   `x-openregister-calculations` derived fields (`currentBookValue`,
   `monthlyDepreciation`, `commercialBookValue`, `fiscalBookValue`),
   parallel commercial/fiscal streams, disposal as a declarative
   lifecycle transition, monthly run as an OR scheduled-workflow.
3. **`bookkeeping-multi-currency`** (depends on T1 GL) — extends
   `GLLine` additively with `baseCurrencyAmount` /
   `transactionCurrency` / `fxRate`, declares the `FxRate` register
   (ECB + manual + internal-policy), daily ECB ingestion as a
   scheduled workflow, period-end revaluation as a scheduled
   workflow, IAS 21 functional-currency translation for
   consolidation.
4. **`bookkeeping-cost-centers-dimensions`** (depends on T1 GL) —
   declares `CostCenter`, `KostenDrager`, `Project`, `AllocationRule`
   registers; extends `GLLine` additively with dimension references;
   allocation rules as schema metadata per ADR-031; segment P&L as
   `x-openregister-aggregations`. Pre-positions WBSO.
5. **`bookkeeping-year-end-close`** (depends on T3 period-close) —
   declares the `FiscalYear` register with open/closing/closed/
   reopened lifecycle; closing emits retained-earnings transfer;
   closing emits next-year opening-balance journal; dimensional
   rollover; admin-only reopen with reason + reverse-and-reopen
   audit chain (consuming ADR-022 RBAC).
6. **`bookkeeping-bank-connectors`** (depends on T3 bank
   reconciliation) — declares `BankConnection` register; PSD2 AIS
   consumed from openconnector by source slug; transaction pulls as
   scheduled workflows materialising CAMT.053 attachments via
   docudesk; consent renewal as declarative lifecycle with 14-day
   warning; notifications as `x-openregister-notifications`. No
   credentials in shillinq.
7. **`bookkeeping-reconciliation-reports`** (depends on T1 GL, T2 AP
   core, T2 AR core) — declares saved-query objects as
   `x-openregister-aggregations`; sub-ledger ↔ GL match, intercompany
   match, variance analysis vs `Budget` register, controller
   exception report. Consumed by launchpad via runtime GraphQL (no
   install-time dep per `feedback_launchpad-no-or-dependency.md`).

All seven specs follow the conduction-schema format (RFC 2119,
`### REQ-{Abbrev}-NNN: <name>`, `#### Scenario:` with exactly 4 hashtags,
GIVEN/WHEN/THEN). Suggested prefixes: `REQ-SBR-*`, `REQ-FA-*`,
`REQ-MC-*`, `REQ-CC-*`, `REQ-YEC-*`, `REQ-BC-*`, `REQ-RR-*`.

## New Dependencies

None. This change consumes existing OpenRegister abstractions,
existing openconnector source records (Digipoort, PSD2 aggregators)
that operators configure, and the already-bumped
`@conduction/nextcloud-vue@^1.0.0-beta.35` (from `shillinq-manifest-tier4`).

## Impact

- `lib/Settings/shillinq_register.json` — adds ~10 schemas, declares
  `x-openregister-lifecycle` on `XbrlInstance`, `FixedAsset`,
  `FiscalYear`, `BankConnection`; declares `x-openregister-calculations`
  on `FixedAsset` (depreciation derived fields);
  `x-openregister-aggregations` on `GLLine` (segment P&L) and on the
  `SavedQuery` records owning the reconciliation reports;
  `x-openregister-notifications` on `BankConnection` /
  `BankStatement`; declares additive field additions on `GLLine`
  (multi-currency + dimensions).
- `lib/Settings/seeds/sbr-mappings/*.json` — seed data for the
  NL-taxonomie mapping per entry point (kvk-jaarrekening,
  belastingdienst-vpb, belastingdienst-ib, sbr-banken-kredietrapportage,
  sbr-wonen).
- `lib/Settings/seeds/allocation-rules/*.json` — seed shapes for the
  default `AllocationRule` examples.
- `src/manifest.json` — adds 7+ navigation entries (SBR/XBRL Filings,
  Fixed Assets, FX Rates, Cost Centers, Kostendragers, Projects,
  Allocation Rules, Fiscal Years, Bank Connections, Reconciliation
  Reports, Budgets) and matching `type: index` + `type: detail` pages.
- No new PHP services. No new Vue components. No new controllers. No
  new TimedJobs (scheduled work is OR `ScheduledWorkflow` records).

## Cross-Project Dependencies

- **OpenRegister** — depends on the following abstractions being
  stable: `x-openregister-lifecycle`, `x-openregister-aggregations`,
  `x-openregister-calculations`, `x-openregister-notifications`
  (ADR-031), audit-trail-immutable, authorization/RBAC,
  approval-workflow, mappings, `ScheduledWorkflow` + n8n adapter
  (ADR-022). If a needed shape is missing (e.g. cross-administration
  aggregation for intercompany match, or single-true field validator
  for `isClosingAccount` cardinality already declared in T1), the
  gap is filed as an OR issue and the relevant requirement is
  annotated in `design.md` under "Declarative-vs-imperative
  decision".
- **openconnector** — operators must have configured `Source` records
  for: a Digipoort production endpoint (SBR submission), an ECB FX
  rate source (daily rate ingestion), and at least one PSD2
  aggregator (bank connections). shillinq references these by slug;
  no code coupling.
- **docudesk** — fixed-asset acquisition documents, XBRL submission
  receipts, and CAMT.053-from-aggregator statements are referenced
  by docudesk attachment URI from the relevant register records.
- **launchpad** — reads reconciliation reports via runtime GraphQL on
  OR (per `feedback_launchpad-no-or-dependency.md`). launchpad MUST NOT
  list shillinq as a dependency.

## Risks

### Risk 1: OpenRegister's aggregation engine cannot express the cross-line balance constraint, the cross-administration intercompany match, or the cross-schema variance join declaratively

**Severity**: Medium
**Mitigation**: Two patterns from ADR-031 apply. (1) Where a
single-schema aggregation suffices (e.g. segment P&L grouping
`GLLine` by `dimensions.costCenterCode`), declarative is the
default. (2) Where the aggregation crosses schemas or
administrations (intercompany match, budget-vs-actual variance),
the spec keeps the requirement shape-neutral (e.g. REQ-RR-003 says
"a saved query MUST match each intercompany posting…" without
prescribing engine internals); the resolution lives in the
implementation cycle. If the engine cannot express it, ADR-031's
exception path applies: a thin single-method PHP guard called *by*
the aggregation engine. The guard is single-method, ~20 LOC, and
explicitly cited as an ADR-031 exception in the implementing cycle's
design doc. The author resolves this during `opsx-ff` discovery,
not during `opsx-apply`.

### Risk 2: openconnector source records for Digipoort and PSD2 aggregators may not yet be configurable when this change implements

**Severity**: Low–Medium
**Mitigation**: openconnector's pluggable source registry is mature.
The implementing cycle MUST verify the configured source slugs
(Digipoort production + ECB + at least one PSD2 aggregator) before
running the manifest validator. If a source type is missing, an
openconnector issue is filed and the relevant capability spec stays
shape-neutral (the requirement names the slug, not the underlying
protocol). No shillinq-side fallback HTTP client.

### Risk 3: PSD2 SCA consent renewal UX is operator-disruptive every 90 days

**Severity**: Medium
**Mitigation**: REQ-BC-006 declares a 14-day advance-warning
lifecycle auto-transition (`active → expiring`) and a notification
to the configured recipient. The renewal action itself routes through
openconnector's SCA flow (operator clicks once, completes SCA in
the bank's UI, returns). The operator UX is bounded; no shillinq
re-implementation of SCA.

### Risk 4: NL-taxonomie evolves rapidly; mappings can fall behind

**Severity**: Medium
**Mitigation**: Per REQ-SBR-002 the `XbrlInstance` records pin
`taxonomyVersion` per generated instance; per REQ-SBR-006 the
`Mapping` records are versioned independently. Historical instances
remain intact even when a new taxonomy ships. The mapping seed files
under `lib/Settings/seeds/sbr-mappings/` are versioned in the
filename (`nt17`, `nt18`, etc.) so coexistence is trivial.

### Risk 5: Year-end close is the single highest-stakes operation and a wrong implementation could corrupt every downstream period

**Severity**: High
**Mitigation**: The close is declarative (no PHP `YearEndCloseService`,
per REQ-YEC-001) so the implementing cycle's review surface is the
schema metadata, which is tight. The reversibility of `closed →
reopened` (REQ-YEC-006) is preserved as a designed admin-only escape
hatch with reverse-and-reopen audit trail. Implementation cycle MUST
include integration tests: close a year with mixed P&L, verify
retained earnings posting, verify opening-balance journal, verify
re-open emits both reversing entries, verify the audit chain is
queryable.

### Risk 6: Fixed-asset commercial/fiscal divergence (Wet IB / Wet VPB) edge cases

**Severity**: Medium
**Mitigation**: REQ-FA-004 mandates parallel streams with separate
postings (sub-account or `bookSet` dimension); this matches Exact /
AFAS / Twinfield practice. The implementing cycle's review MUST
include a competent Dutch bookkeeper persona confirming the divergent
rate behaviour against a real Wet IB depreciation rule (e.g. the
20% fiscal cap on certain asset classes).

### Risk 7: Cost-allocation rule complexity (volume-driver, headcount-driver) creep

**Severity**: Low
**Mitigation**: REQ-CC-004 ships four named drivers
(`fixed-percentage`, `fixed-amount`, `volume`, `headcount`). Adding
a new driver is additive (enum extension). Custom domain-specific
allocation logic is out of scope; if an operator needs a custom
driver, an OR issue is filed for the driver enum extension and the
operator-side rule is configured through normal `AllocationRule`
edits.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change
folder; no runtime impact because no implementation lands until
`opsx-apply` is run on each capability spec. After implementation
(separate cycles per capability), rollback follows the standard
pattern: revert the implementing PR, run the repair step in
down-direction (registers are non-destructive — unused schemas remain
queryable but unreferenced). No data migration risk at the spec stage.
Per-capability rollback affects only the affected register +
manifest entries; the other six capabilities remain operational.

## Open Questions

1. **Cross-administration intercompany aggregation** — see Risk 1. The
   `opsx-ff` design phase resolves whether the aggregation engine can
   express the intercompany match across two administrations, or
   whether a thin saved-query guard is needed. The spec is
   shape-neutral.
2. **Single-true `isClosingAccount` enforcement** — `REQ-CoA-009`
   (T1) declared the uniqueness; T4's year-end close depends on it.
   Confirm during `opsx-ff` whether OR has a native single-true
   validator or whether T1's thin lifecycle precondition is the
   canonical answer.
3. **PSD2 aggregator selection per administration** — single global
   default vs per-administration override? REQ-BC-002 supports per-
   connection aggregator selection; the operator UX for the default
   is settled in the implementing cycle's UX review.
4. **NL-taxonomie version pinning policy** — auto-upgrade to the
   latest taxonomy on filing, or operator-controlled? REQ-SBR-002
   pins `taxonomyVersion` per instance; the *new-filing default*
   policy lives in administration settings, not the schema.
5. **FX revaluation cadence** — period-end only, or operator-
   selectable? REQ-MC-004 ties it to period close as the default;
   operator-selectable monthly revaluation is a future enhancement.



## Design

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



## Tasks

# Tasks — Bookkeeping Advanced Engine (T4)

> **Spec-only change.** Per `proposal.md` Scope, implementation code is
> deliberately out of scope here. The tasks below describe the work
> per-capability `opsx-apply` cycles will execute against the seven
> spec deltas — they are recorded now so the spec-review gate,
> dependency planning, and tier-cascade impact are all visible at
> proposal time. No source files are edited by this change itself.

## 0. Deduplication Check

### Task 0.1: Confirm no T4 schema or capability already exists

- **spec_ref**: all seven specs (folder scan)
- **files**: `lib/Settings/shillinq_register.json`,
  `openspec/specs/**`, `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the shillinq repo at the head of `feat/bookkeeping-engine`
    WHEN `lib/Settings/shillinq_register.json` is inspected
    THEN no `XbrlInstance`, `FixedAsset`, `FxRate`, `CostCenter`,
    `KostenDrager`, `Project`, `AllocationRule`, `FiscalYear`,
    `BankConnection`, or `Budget` schema is already declared.
  - GIVEN `openspec/specs/` WHEN scanned THEN no
    `bookkeeping-sbr-xbrl-reporting`, `bookkeeping-fixed-assets-depreciation`,
    `bookkeeping-multi-currency`, `bookkeeping-cost-centers-dimensions`,
    `bookkeeping-year-end-close`, `bookkeeping-bank-connectors`, or
    `bookkeeping-reconciliation-reports` capability spec already
    exists.
  - GIVEN `adr-000-data-model.md` WHEN read THEN any pre-existing
    entries for the T4 entities are catalogued and the reconciliation
    notes from `design.md` are appended in the implementing cycles.
  - GIVEN T1's `bookkeeping-general-ledger/spec.md` and the
    T2/T3 parallel changes WHEN cross-checked THEN the additive
    extensions on `GLLine` (multi-currency fields, dimension
    references) do not conflict with field names already used by T2
    or T3.
- [ ] Implement
- [ ] Test

## 1. Spec foundation (this change)

### Task 1.1: Author bookkeeping-sbr-xbrl-reporting spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-sbr-xbrl-reporting/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries the
    `Status: proposed` / `Scope: shillinq` /
    `Tier: T4 (advanced engine)` /
    `Depends on: bookkeeping-financial-statements (T3), bookkeeping-vat-btw-filing (T3)`
    header.
  - GIVEN the spec WHEN scanned THEN every requirement uses
    `### REQ-SBR-NNN:` and SHALL/MUST/SHOULD/MAY RFC 2119 keywords.
  - GIVEN each requirement WHEN inspected THEN at least one
    `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4
    hashtags on the scenario header).
  - GIVEN the spec WHEN scanned THEN it explicitly cites ADR-022
    (openconnector Digipoort consumption) and ADR-031 (declarative
    lifecycle on `XbrlInstance`).
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.2: Author bookkeeping-fixed-assets-depreciation spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-fixed-assets-depreciation/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN it declares depreciation
    derived fields as `x-openregister-calculations` and the monthly
    posting run as an OR `ScheduledWorkflow`, and cites ADR-031
    explicitly for both.
  - GIVEN the spec WHEN scanned THEN it mandates parallel
    commercial / fiscal streams (REQ-FA-004) and disposal as a
    declarative lifecycle transition (REQ-FA-006).
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.3: Author bookkeeping-multi-currency spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-multi-currency/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN it declares the additive
    extension on `GLLine` for `baseCurrencyAmount` /
    `transactionCurrency` / `fxRate`, the `FxRate` register, ECB
    ingestion as a scheduled workflow consuming openconnector, and
    IAS 21 consolidation translation via OR Mappings.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.4: Author bookkeeping-cost-centers-dimensions spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-cost-centers-dimensions/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1)`.
  - GIVEN the spec WHEN scanned THEN it declares dimensions as
    OR-managed registers, the `AllocationRule` schema with cadence
    routing, segment P&L as `x-openregister-aggregations`, and
    pre-positions WBSO via REQ-CC-007.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.5: Author bookkeeping-year-end-close spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-year-end-close/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-period-close (T3)`.
  - GIVEN the spec WHEN scanned THEN it declares the
    `FiscalYear` register with open/closing/closed/reopened
    lifecycle, the closing emits T1 `JournalEntry` records
    (retained-earnings + opening-balance), dimensional rollover
    via CloudEvents, and the admin-only reopen guard consuming OR
    RBAC per ADR-022.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.6: Author bookkeeping-bank-connectors spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-bank-connectors/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-bank-reconciliation (T3)`.
  - GIVEN the spec WHEN scanned THEN it forbids embedded
    aggregator HTTP clients and OAuth flows (REQ-BC-001), forbids
    credentials on shillinq records or in a shillinq table
    (REQ-BC-002 / REQ-BC-003), and declares transaction polling as
    an OR `ScheduledWorkflow` materialising CAMT.053 via docudesk
    (REQ-BC-004).
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.7: Author bookkeeping-reconciliation-reports spec

- **spec_ref**: `openspec/changes/add-shillinq-bookkeeping-advanced/specs/bookkeeping-reconciliation-reports/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries
    `Depends on: bookkeeping-general-ledger (T1), bookkeeping-accounts-payable-core (T2), bookkeeping-accounts-receivable-core (T2)`.
  - GIVEN the spec WHEN scanned THEN it forbids a PHP report
    engine (REQ-RR-001), declares all reports as saved-query
    objects consumed by launchpad via runtime GraphQL (REQ-RR-007),
    and cites `feedback_launchpad-no-or-dependency.md` for the launchpad
    no-install-time-dep rule.
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.8: Author proposal.md + design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it references the
    shared `nextcloud-app` spec per shillinq config.yaml
    `rules.proposal` and includes Affected Projects / Scope /
    Risks / Rollback / Open Questions.
  - GIVEN `design.md` WHEN inspected THEN it includes the
    Reuse Analysis table, the Declarative-vs-imperative decision
    table per ADR-031 enforcement, and the Seed Data section per
    hydra `rules.design`.
- [x] Implement
- [ ] Test (peer review — bookkeeper persona reads each capability
  end-to-end and confirms production-grade fit)

---

## (The following tasks are recorded for downstream per-capability `opsx-apply` cycles, not for this spec-only change.)

## 2. Register declarations — `lib/Settings/shillinq_register.json`

### Task 2.1: Declare the `XbrlInstance` schema

- **spec_ref**: `bookkeeping-sbr-xbrl-reporting/spec.md`
  (REQ-SBR-001 .. REQ-SBR-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN a JSON Schema validator WHEN `XbrlInstance` is loaded
    THEN every field from REQ-SBR-002 is present with the typing the
    spec mandates.
  - GIVEN the schema WHEN scanned for lifecycle metadata THEN it
    carries the `x-openregister-lifecycle` block with the
    draft/validated/submitted/accepted/rejected transitions from
    REQ-SBR-003.
  - GIVEN the schema WHEN scanned THEN the submission action
    routes through an openconnector source slug (REQ-SBR-004), not
    via an embedded HTTP client.
- [ ] Implement
- [ ] Test (`composer check:strict` + `npm run check:manifest`;
  PHPUnit asserting schema load + lifecycle transitions; integration
  test using a mocked openconnector source returning a Digipoort
  receipt)

### Task 2.2: Declare the `FixedAsset` schema with depreciation calculations

- **spec_ref**: `bookkeeping-fixed-assets-depreciation/spec.md`
  (REQ-FA-001 .. REQ-FA-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-FA-002 are
    present (assetNumber, name, assetCategory, acquisitionDate,
    acquisitionCost, currency, usefulLifeMonths, residualValue,
    depreciationMethod, degressiveRate, commercialRate, fiscalRate,
    assetAccountNumber, accumulatedDepAccountNumber,
    depreciationExpenseAccountNumber, disposalDate,
    disposalAccountingTreatment, lifecycleState, administrationId).
  - GIVEN the schema's calculations WHEN scanned THEN
    `monthlyDepreciation`, `currentBookValue`,
    `commercialBookValue`, `fiscalBookValue` are declared as
    `x-openregister-calculations` per REQ-FA-003 + REQ-FA-004.
  - GIVEN the schema's lifecycle WHEN scanned THEN the
    `active → disposed` action emits a closing `JournalEntry` per
    REQ-FA-006.
- [ ] Implement
- [ ] Test (PHPUnit: derived field correctness over time; parallel
  stream divergence; disposal closing-journal emission)

### Task 2.3: Declare the `FxRate` register and the multi-currency extension on `GLLine`

- **spec_ref**: `bookkeeping-multi-currency/spec.md` (REQ-MC-001 .. REQ-MC-006)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the `FxRate` schema WHEN loaded THEN fields per REQ-MC-002
    are present and the uniqueness constraint on
    (baseCurrency, quoteCurrency, date, source) is declared.
  - GIVEN the T1 `GLLine` schema patch WHEN inspected THEN the
    additive multi-currency fields per REQ-MC-001
    (`baseCurrencyAmount`, `transactionCurrency`, `baseCurrency`,
    `fxRate`, `fxRateSource`, `fxRateDate`) are present without
    breaking T1 callers (default `fxRate = 1.0` when
    `transactionCurrency = baseCurrency`).
  - GIVEN the implementing PR WHEN reviewed THEN no T1 field rename
    occurs; T1's `amount` is reinterpreted as `transactionAmount`
    semantically with no on-disk migration.
- [ ] Implement
- [ ] Test (PHPUnit: single-currency posting fxRate=1; foreign-
  currency posting converts correctly; rounding edge cases;
  duplicate FxRate rejected; manual rate without reason rejected)

### Task 2.4: Declare `CostCenter`, `KostenDrager`, `Project`, `AllocationRule` and extend `GLLine` with dimensions

- **spec_ref**: `bookkeeping-cost-centers-dimensions/spec.md` (REQ-CC-001 .. REQ-CC-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN each of the four schemas WHEN loaded THEN fields per
    REQ-CC-002 / REQ-CC-004 are present, hierarchy declared via
    `x-openregister-relations` self-relation for the first three.
  - GIVEN the T1 `GLLine` schema patch WHEN inspected THEN additive
    dimension fields per REQ-CC-003 are present and the
    `dimensions` free-form map validates against registered custom
    dimension registers.
  - GIVEN `AllocationRule` WHEN scanned THEN `fixed-percentage`
    rules enforce the "targets sum to 100" precondition via
    `x-openregister-lifecycle.requires`.
- [ ] Implement
- [ ] Test (PHPUnit: relation resolution; dimension key/value
  validation; allocation precondition; per-posting rule splits
  transaction keeping balance; segment P&L aggregation rolls up
  children)

### Task 2.5: Declare the `FiscalYear` register with year-end-close lifecycle

- **spec_ref**: `bookkeeping-year-end-close/spec.md` (REQ-YEC-001 .. REQ-YEC-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-YEC-002 are
    present and `yearNumber` is unique per `administrationId`.
  - GIVEN the schema's lifecycle WHEN scanned THEN
    `open → closing → closed → reopened` transitions are declared,
    the closing emits T1 `JournalEntry` records, the
    `closed → reopened` carries an admin role guard per ADR-022,
    and the reopen requires `reopenReason`.
- [ ] Implement
- [ ] Test (PHPUnit: profit-year + loss-year close emit balanced
  retained-earnings journal; opening-balance journal carries only
  balance-sheet accounts; archived dimensions skipped in rollover;
  non-admin reopen rejected; reopen-no-reason rejected; reopen
  emits two reversing journals)

### Task 2.6: Declare the `BankConnection` register with PSD2 lifecycle

- **spec_ref**: `bookkeeping-bank-connectors/spec.md` (REQ-BC-001 .. REQ-BC-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the schema WHEN loaded THEN fields per REQ-BC-002 are
    present and no field names match `*Secret*` / `*ClientId*` /
    `*ApiKey*` / `*Token*` (REQ-BC-002 scenario).
  - GIVEN the schema's lifecycle WHEN scanned THEN
    `active → expiring` auto-transitions 14 days before
    `consentExpiresAt`, fires a notification per REQ-BC-005, and
    transitions to `expired` on the deadline.
  - GIVEN the implementing PR WHEN reviewed THEN no Guzzle /
    Symfony HttpClient / curl_init usages exist in
    `lib/Service/Bank*` / `lib/Service/Psd2*` / `lib/Service/Aggregator*`.
- [ ] Implement
- [ ] Test (PHPUnit: lifecycle time-based transition; consent-renewal
  routes through openconnector; CAMT.053 attachment via docudesk;
  notification fires on new transaction)

### Task 2.7: Declare the `Budget` register and saved-query reports

- **spec_ref**: `bookkeeping-reconciliation-reports/spec.md`
  (REQ-RR-001 .. REQ-RR-007)
- **files**: `lib/Settings/shillinq_register.json`
- **acceptance_criteria**:
  - GIVEN the `Budget` schema WHEN loaded THEN fields are present
    per REQ-RR-004 (`accountNumber`, `periodId`, `budgetAmount`,
    `currency`, `administrationId`, `lifecycleState`).
  - GIVEN the four saved queries (sub-ledger ↔ GL, intercompany,
    variance, controller exception) WHEN inspected THEN they are
    declared as `x-openregister-aggregations` records consumed by
    both the manifest pages and launchpad via runtime GraphQL.
  - GIVEN the implementing PR WHEN reviewed THEN no `lib/Service/`
    class names match `*Report*` / `*Reconciliation*` / `*Variance*`
    (REQ-RR-001 scenario).
- [ ] Implement
- [ ] Test (PHPUnit: matched reconciliation reports zero variance;
  mismatched surfaces as exception; intercompany match for grouped
  administrations; within-threshold variance does not flag;
  exception report sorted by severity; launchpad GraphQL discovery)

## 3. Seed data — `lib/Settings/seeds/`

### Task 3.1: Ship NL-taxonomie mapping seeds per SBR entry point + version

- **spec_ref**: `bookkeeping-sbr-xbrl-reporting/spec.md` (REQ-SBR-005, REQ-SBR-006)
- **files**: `lib/Settings/seeds/sbr-mappings/kvk-jaarrekening-nt17.json`,
  `lib/Settings/seeds/sbr-mappings/kvk-jaarrekening-nt18.json`,
  `lib/Settings/seeds/sbr-mappings/belastingdienst-vpb-nt17.json`,
  `lib/Settings/seeds/sbr-mappings/belastingdienst-vpb-nt18.json`,
  `lib/Settings/seeds/sbr-mappings/belastingdienst-ib-nt17.json`,
  `lib/Settings/seeds/sbr-mappings/sbr-banken-kredietrapportage-nt17.json`,
  `lib/Settings/seeds/sbr-mappings/sbr-wonen-nt17.json`
- **acceptance_criteria**:
  - GIVEN each seed file WHEN parsed as JSON THEN every record
    conforms to the OR `Mapping` shape and the `_meta` block carries
    `source: "NL-taxonomie"`, `variant: <entry-point>`,
    `taxonomyVersion: <version>`.
  - GIVEN each file's top of file WHEN read THEN the SPDX header
    per `feedback_spdx-in-docblock.md` is present.
- [ ] Implement
- [ ] Test (PHPUnit: load + import + queryable; bookkeeper-persona
  spot-check on key lines from each entry point)

### Task 3.2: Ship allocation-rule example seeds

- **spec_ref**: `bookkeeping-cost-centers-dimensions/spec.md` (REQ-CC-004)
- **files**: `lib/Settings/seeds/allocation-rules/overhead-by-headcount.json`,
  `lib/Settings/seeds/allocation-rules/it-by-volume.json`,
  `lib/Settings/seeds/allocation-rules/facility-by-fixed-percentage.json`
- **acceptance_criteria**:
  - GIVEN each seed file WHEN loaded THEN every record conforms to
    the `AllocationRule` schema and ships with
    `lifecycleState: paused` per design.md Seed Data section.
- [ ] Implement
- [ ] Test (PHPUnit: load + import + paused-state assertion)

### Task 3.3: Extend the repair step to import T4 seeds

- **spec_ref**: T4 capability specs (REQ-SBR-006, REQ-CC-004)
- **files**: existing repair class under `lib/Migration/` or
  `lib/Settings/SettingsService.php`
- **acceptance_criteria**:
  - GIVEN a fresh shillinq install
    WHEN the repair step runs
    THEN the NL-taxonomie mapping seeds appear in the `Mapping`
    register and allocation-rule examples appear in the
    `AllocationRule` register; idempotent on re-run.
  - GIVEN per-administration override
    WHEN an operator edits a seeded mapping or rule
    THEN the operator edit persists across subsequent repair runs.
- [ ] Implement
- [ ] Test (PHPUnit + browser smoke in dev container)

## 4. Manifest navigation — `src/manifest.json`

### Task 4.1: Add SBR/XBRL Filings navigation + pages

- **spec_ref**: `bookkeeping-sbr-xbrl-reporting/spec.md` (REQ-SBR-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > SBR/XBRL Filings` with a `type: index` page
    binding to `XbrlInstance` and a `type: detail` page.
  - GIVEN `node tests/validate-manifest.js` WHEN run THEN it exits 0.
- [ ] Implement
- [ ] Test (validate-manifest + browser smoke)

### Task 4.2: Add Fixed Assets navigation + pages

- **spec_ref**: `bookkeeping-fixed-assets-depreciation/spec.md` (REQ-FA-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Fixed Assets` with `type: index` + `type:
    detail` pages binding to `FixedAsset`.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.3: Add FX Rates navigation + pages

- **spec_ref**: `bookkeeping-multi-currency/spec.md` (REQ-MC-006)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > FX Rates` with `type: index` + `type: detail`
    pages binding to `FxRate` and filter chips per REQ-MC-006.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.4: Add Dimensions navigation (cost centers, kostendragers, projects, allocation rules)

- **spec_ref**: `bookkeeping-cost-centers-dimensions/spec.md` (REQ-CC-006)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN entries under
    `Bookkeeping > Dimensions` exist for `CostCenter`,
    `KostenDrager`, `Project`, and `AllocationRule` with matching
    `type: index` + `type: detail` pages.
  - GIVEN a custom dimension register is added WHEN the manifest
    is reloaded THEN the new dimension MUST appear in the nav with
    no PHP / Vue edits (REQ-CC-006 scenario).
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.5: Add Fiscal Years navigation + pages

- **spec_ref**: `bookkeeping-year-end-close/spec.md` (REQ-YEC-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Fiscal Years` with `type: index` + `type:
    detail` pages binding to `FiscalYear`; the detail page MUST
    surface the close and reopen actions gated by role per
    REQ-YEC-006.
- [ ] Implement
- [ ] Test (same as 4.1; persona test confirming admin sees reopen,
  bookkeeper does not)

### Task 4.6: Add Bank Connections navigation + pages

- **spec_ref**: `bookkeeping-bank-connectors/spec.md` (REQ-BC-007)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Bank Connections` with `type: index` + `type:
    detail` pages binding to `BankConnection` surfacing the
    consent-renewal action and remaining-days countdown when
    `state = expiring`.
- [ ] Implement
- [ ] Test (same as 4.1)

### Task 4.7: Add Reconciliation Reports + Budgets navigation + pages

- **spec_ref**: `bookkeeping-reconciliation-reports/spec.md` (REQ-RR-006)
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN the manifest WHEN scanned THEN it declares
    `Bookkeeping > Reconciliation Reports` listing the saved-query
    catalog and rendering each via `type: detail` pages bound to
    the saved-query metadata, plus a `Bookkeeping > Budgets`
    index/detail pair.
- [ ] Implement
- [ ] Test (same as 4.1; launchpad widget end-to-end confirming
  runtime-GraphQL consumption with no shillinq dep on launchpad)

## 5. ADR-000 reconciliation notes

### Task 5.1: Update adr-000-data-model.md per capability

- **spec_ref**: `proposal.md` Impact section
- **files**: `openspec/architecture/adr-000-data-model.md`
- **acceptance_criteria**:
  - GIVEN the ADR WHEN opened THEN one-paragraph annotations are
    added introducing `XbrlInstance`, `FixedAsset`, `FxRate`,
    `CostCenter`, `KostenDrager`, `Project`, `AllocationRule`,
    `FiscalYear`, `BankConnection`, `Budget` and naming their
    capability spec.
  - GIVEN existing entries that T4 supersedes or extends WHEN read
    THEN the reconciliation notes from design.md's Reuse Analysis
    are inserted.
- [ ] Implement
- [ ] Test (peer review by the bookkeeper persona)

## 6. Conditional ADR-031 exception guards (only if engine-dependency risks confirm)

### Task 6.1 (conditional): Author IntercompanyMatchGuard

- **spec_ref**: `bookkeeping-reconciliation-reports/spec.md` REQ-RR-003
- **files**: `lib/Aggregation/IntercompanyMatchGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN `opsx-ff` discovery concluded the aggregation engine
    cannot express cross-administration intercompany match
    declaratively
    WHEN the guard is implemented
    THEN it has exactly one method
    `matchPostings(string $groupId, string $periodId): array` and is
    referenced from the saved-query record.
  - GIVEN the guard WHEN code-reviewed THEN it carries the ADR-031
    exception annotation linking back to design.md's
    Declarative-vs-imperative decision table.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: matched pair returns zero variance; unmatched
  leg returns the open amount; mixed-currency match uses
  base-currency-amount)

### Task 6.2 (conditional): Author BudgetVarianceJoinGuard

- **spec_ref**: `bookkeeping-reconciliation-reports/spec.md` REQ-RR-004
- **files**: `lib/Aggregation/BudgetVarianceJoinGuard.php` (new, single method)
- **acceptance_criteria**:
  - GIVEN `opsx-ff` discovery concluded the aggregation engine
    cannot join `GLLine` aggregations to `Budget` declaratively
    WHEN the guard is implemented
    THEN it has exactly one method
    `computeVariance(string $accountNumber, string $periodId, string $administrationId): array`.
  - GIVEN the guard WHEN code-reviewed THEN it carries the ADR-031
    exception annotation.
- [ ] Implement (only if conditional triggered)
- [ ] Test (PHPUnit: within-threshold + above-threshold cases)

## Verification

- [ ] All Section 1 tasks (this change's own deliverables) checked off
- [ ] `openspec validate` exits clean on the change folder
- [ ] Manual peer review by a competent Dutch bookkeeper persona
      (e.g. `/test-persona-janwillem` for SMB, plus a municipal
      controller persona for BBV) confirms each T4 capability shape
      matches real production bookkeeping practice
- [ ] Architecture reviewer confirms ADR-022 + ADR-024 + ADR-031
      compliance across all seven specs (no app-local audit; no
      app-local RBAC; no app-local approval; no service-class state
      machines / aggregations / calculations / notifications; no
      embedded HTTP clients for Digipoort or PSD2; launchpad carries no
      shillinq dep; manifest carries the navigation; no per-app
      TimedJobs for scheduled work)
- [ ] No source code changes outside
      `openspec/changes/add-shillinq-bookkeeping-advanced/`

## Tests (company-wide ADR-009)

<!-- T4 spec-only change. Implementation-cycle tests are pre-declared on tasks 2-6 above for completeness. -->

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for new/changed business logic
      (`tests/Unit/`) — declared on tasks 2.1-2.7, 3.1-3.3, 6.1, 6.2;
      land with per-capability implementation cycles
- [ ] Newman/Postman tests for new/changed API endpoints — no new
      endpoints in T4 (OR exposes register CRUD + saved-query
      execution generically; tests cover the register HTTP surface)
- [ ] Browser tests (Playwright MCP) for UI changes — declared on
      tasks 4.1-4.7; lands with implementation cycles
- [ ] All tests pass (`composer test`) — enforced at implementing
      PR's CI gate

## Documentation (company-wide ADR-010)

<!-- User-facing tutorial pages land with the implementation cycle, not the spec. -->

- [ ] N/A for the spec change itself
- [ ] Feature documentation updated in `docs/` —
      `docs/user-guide/bookkeeping/sbr-xbrl-filings/`,
      `docs/user-guide/bookkeeping/fixed-assets/`,
      `docs/user-guide/bookkeeping/multi-currency/`,
      `docs/user-guide/bookkeeping/dimensions/`,
      `docs/user-guide/bookkeeping/year-end-close/`,
      `docs/user-guide/bookkeeping/bank-connections/`,
      `docs/user-guide/bookkeeping/reconciliation-reports/` authored
      during implementation cycles per ADR-030 journeydoc convention
- [ ] Screenshot captured and committed to `docs/images/` —
      authored during implementation cycles (≥1 per capability:
      filings list, asset detail, FX rates index, dimension
      hierarchy, fiscal-year close action, bank connection renewal,
      controller exception report)

## i18n (company-wide ADR-005)

<!-- No user-facing strings in the spec; translation work lands with the implementation cycle. -->

- [ ] N/A for the spec change itself
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings
      added during implementation cycles — required terms:
      `SBR Filing`, `XBRL Instance`, `Jaarrekening`, `Aangifte`,
      `Digipoort`, `NL-taxonomie`, `Fixed Asset`, `Vast actief`,
      `Depreciation`, `Afschrijving`, `Commercial`, `Fiscal`,
      `Disposal`, `FX Rate`, `Wisselkoers`, `ECB`, `Manual rate`,
      `Cost Center`, `Kostenplaats`, `Kostendrager`, `Project`,
      `Allocation Rule`, `Verdelingsregel`, `Driver`, `Fiscal Year`,
      `Boekjaar`, `Year-end Close`, `Jaarafsluiting`, `Reopen Year`,
      `Heropen Boekjaar`, `Bank Connection`, `Bankkoppeling`,
      `Consent`, `Toestemming`, `SCA Renewal`, `Reconciliation
      Report`, `Aansluitingsrapport`, `Variance`, `Verschil`,
      `Exception`, `Uitzondering`, `Controller`, `Controlerend
      Boekhouder`, `Budget`, `Budget vs Actual`