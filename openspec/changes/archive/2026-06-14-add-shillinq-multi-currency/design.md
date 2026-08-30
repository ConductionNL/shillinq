# Design — Multi-Currency

**status: pr-created**

## Decisions

### D1 — Additive `GLLine` extension, single-currency callers stay correct

Per the T1 spec, every `GLLine` carries `amount` and `currency`. T4's
multi-currency extension treats `amount` as `transactionAmount` in
`transactionCurrency`, and adds `baseCurrencyAmount` / `baseCurrency` /
`fxRate` / `fxRateSource` / `fxRateDate` so the trial balance and
statements always have a single-currency view to aggregate. For
single-currency postings, `fxRate = 1.0` and `baseCurrencyAmount =
amount`.

This shape avoids a destructive migration of T1 data and keeps T2
sub-ledgers and T3 statements compatible (they read
`baseCurrencyAmount` for aggregation, `transactionAmount` for display).

**Alternative considered**: Split `GLLine` into `GLLineTransaction` +
`GLLineBase` to avoid mixing currencies in one row. Rejected — the
row-per-currency-view shape forces every aggregation to join two
schemas and the maintenance cost is high. Additive fields are the
canonical ADR-001 pattern.

### D2 — FX orientation: `baseCurrencyAmount = transactionAmount × fxRate`, same as `FxRate.rate`

`fxRate` on a `GLLine` is the rate that converts ONE unit of
`transactionCurrency` into base-currency units. A USD line in a EUR
administration on a day when 1 USD ≈ 0.9259 EUR stores `fxRate:
0.9259`. The `FxRate` register uses the same orientation so
`GLLine.fxRate` and the looked-up `FxRate.rate` join directly with no
reciprocation.

The ECB reference XML feed is published as "1 EUR = N foreign" (i.e.
inverted relative to our storage); the ingestion workflow per
REQ-MC-003 MUST invert the published rate when storing (`our rate =
1 / ECB rate`) and round to at least 6 decimal places to preserve
precision on the round-trip.

**Alternative considered**: Store ECB rates in their native
orientation and reciprocate at read time. Rejected — every read site
would need to know the orientation, and a missing reciprocation would
silently double-error a posting. Inverting once on ingest is
auditable.

### D3 — ECB ingestion + period-end revaluation as scheduled workflows, not services

Per ADR-031 §"Background jobs" path 2, both the daily ECB import and
the period-end revaluation MUST be implemented as OR
`ScheduledWorkflow` records calling n8n workflows. shillinq MUST NOT
author `FxRateImportJob extends TimedJob` or
`FxRevaluationService` PHP classes. The ECB HTTP fetch routes through
openconnector per ADR-022; the revaluation workflow reads each open
foreign-currency position, computes the difference between book-rate
and period-end rate, and emits a balanced `GLTransaction` per
position.

**Alternative considered**: Author a per-app TimedJob + service.
Rejected per ADR-031.

### D4 — Realised gain/loss as a declarative lifecycle action

Realised gain/loss on settlement MUST be computed declaratively at
settlement time via an `x-openregister-lifecycle` action on the
sub-ledger record (per T2 sub-ledgers) that compares book-rate to
settlement-rate. No PHP service orchestrates.

### D5 — IAS 21 consolidation translation via OR `Mapping`

For groups consolidating foreign subsidiaries (per T5 intercompany /
consolidation), per-subsidiary statements MUST be translated to the
parent's presentation currency using IAS 21 rules: monetary
assets/liabilities at closing rate, equity at historical rate, P&L at
average rate for the period, and the resulting cumulative translation
adjustment (CTA) MUST post to a dedicated equity account on the
consolidated balance sheet. The translation MUST be declared as a
`Mapping` consumed via the OR Mappings abstraction (per ADR-022)
referencing the `FxRate` register; no PHP
`ConsolidationTranslationService`.

## Reuse Analysis

| Capability needed | What already exists | Reuse strategy |
|---|---|---|
| FX rate storage | New `FxRate` register | T4 adds the register; rates loaded via scheduled workflow |
| ECB daily rate ingestion | openconnector source + OR `ScheduledWorkflow` | Workflow calls openconnector source by slug, upserts `FxRate` |
| Period-end FX revaluation | OR `ScheduledWorkflow` triggered on period close | Reads open foreign-currency positions, emits balanced GL postings; no PHP service |
| Realised gain/loss on settlement | `x-openregister-lifecycle` action on sub-ledger record | Per-event computation, declarative |
| IAS 21 functional-currency translation | OR `Mapping` abstraction | Mapping references rate register; no `ConsolidationTranslationService` |
| `GLLine` multi-currency extension | T1 `GLLine` schema | Additive — no destructive migration; T1 callers see `fxRate=1.0`, `baseCurrencyAmount=amount` |
| Audit trail | OR audit-trail-immutable | Consumed automatically |
| Manifest navigation | `src/manifest.json` + `CnAppRoot` (Tier-4) | Adds 1 menu entry + 1 index/detail page pair |

**Net new code in implementation cycle**: 1 schema declaration
(`FxRate`) + additive patches on `GLLine` + 1 manifest entry pair + 2
scheduled-workflow records + 1 `Mapping` for IAS 21 translation. No
new PHP service.

## Seed Data

None. FX rates are loaded by the daily ECB workflow on first run; no
template ships in this change.
