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
