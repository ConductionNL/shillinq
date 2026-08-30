## Context

Shillinq has no booking-term model for revenue. The closest thing, `CashflowRecurring`, is a
**cashflow-projection** entry (huur, verzekering, abonnementen, DGA-loon …) that feeds the
13-week forecast by expanding recurring charges into `CashflowWeek` rows — it is not a revenue
booking and was never meant to drive a recurring-revenue KPI. A prior `recurring-revenue-mrr-model`
change attempted a run-rate MRR derived from `CashflowRecurring`; it was retired as superseded
because run-rate MRR ignores term boundaries, conflates one-off implementation fees with
recurring revenue, and cannot be traced back to a signed booking.

This change introduces the correct primitives: a `SalesOrder` (the booking term) with
`SalesOrderLine`s tagged `RECURRING` or `ONE_OFF`, and defines **recognized recurring revenue
per period** (IFRS 15 / ASC 606 over-time recognition), prorated to the overlap of each line's
term with the reporting period. The user's framing: *"the order is the actual booking term;
the contract is the legal signer."* Hence `contractId` is an optional plain string reference,
not a modeled Contract entity.

The decisive constraint is that the reporting period `[periodFrom, periodTo]` is a **runtime**
input (chosen on the dashboard), and the metric is an interval-overlap proration against that
runtime window. Whether the OpenRegister declarative grammar can express that determines the
shape of the change (config vs. chain) — investigated below.

## Goals / Non-Goals

**Goals:**
- Declare `SalesOrder` + `SalesOrderLine` as fully declarative OpenRegister schemas (config).
- Define the recognized-recurring-revenue metric precisely (overlap proration + frequency
  normalization) and the recurring/one-off split.
- Determine, with grammar evidence, whether recognition is declarative or an ADR-031 exception,
  and document that decision in the table below.
- Seed a realistic mixed MKB/consultancy order so the split + a sample-period figure are demonstrable.

**Non-Goals:**
- A full IFRS 15 `Contract` / `PerformanceObligation` / `RevenueWaterfall` model (that is the
  separate `bookkeeping-ifrs15-revenue` capability). This change is the lightweight order-line
  recognition the dashboard needs, not the audit-grade five-step model.
- The recognition service implementation and the read endpoint (chained `-engine` change).
- The pipelinq dashboard widget (downstream pipelinq change).
- Modifying `CashflowRecurring` — it stays a cashflow-projection entry.

## Feasibility investigation — can the declarative grammar express runtime-period overlap proration?

**Question:** can `x-openregister-aggregations` / `-calculations` compute
`Σ over RECURRING lines of monthlyRate × overlapMonths([line term], [@period.from, @period.to])`
where `[@period.from, @period.to]` is supplied at request time?

> **⚠ Live-verification correction (calc grammar).** The infix string-ternary form shown below
> (e.g. `RetentionRule.daysUntilRetention`) does **NOT** actually execute in OpenRegister.
> `CalculationEvaluator` evaluates a **JSON-AST** (`{"if":[cond,then,else]}`, `{"prop":"x"}`,
> `{"eq":[a,b]}`, `{"/":[a,b]}`, `{"lit":n}`) — a bare/string expression is only passed to the
> placeholder resolver, never parsed as a ternary. The on-save `CalculationOnSaveListener` also
> **skips any calc lacking `materialise: true`**. shillinq's existing string-ternary calcs (~28,
> none with `materialise:true`) are therefore dead — a pre-existing bug, out of scope to fix
> wholesale here. `maandWaarde` is implemented in the **correct JSON-AST grammar + `materialise:true`**
> and **live-verified**: MAANDELIJKS 1500 → 1500, JAARLIJKS 12000 → 1000, ONE_OFF → 0, and the
> `sum(maandWaarde) WHERE nature=RECURRING` aggregation returns 2500 (the pipelinq tile shows €2,500).

**Grammar primitives inspected in `lib/Settings/shillinq_register.json`:**

1. **`x-openregister-calculations`** — ternary expressions on `@self.*` with date helpers, e.g.
   `RetentionRule.daysUntilRetention`:
   `@self.effectiveTo != null ? datediff(today(), @self.effectiveTo) : null`.
   These compute a derived field **per object** from that object's own fields + `today()`.
   There is no facility to parameterize a calculation by a caller-supplied date window, and no
   facility to fold across other objects.

2. **`x-openregister-aggregations`** — `groupBy` / `crossJoin` / `calendar` reducers with
   SQL-ish `SUM()` / `CASE WHEN` and the date funcs `DAY() / MONTH() / YEAR() / WEEK()`.
   - The **proration precedent** is `CashflowForecastHorizon.recurringExpansion`
     (`type: crossJoin`, source `CashflowRecurring`, join `CashflowWeek` on `administrationId`).
     It applies a validity-window `filter`
     `(CashflowRecurring.geldigVan <= CashflowWeek.weekEind) AND (CashflowRecurring.geldigTot IS NULL OR CashflowRecurring.geldigTot >= CashflowWeek.weekStart)`
     and a per-enum `matchFrequency` map, e.g.
     `MAANDELIJKS: "DAY(CashflowWeek.weekStart) <= CashflowRecurring.dagVanMaand AND CashflowRecurring.dagVanMaand <= DAY(CashflowWeek.weekEind)"`.
     Crucially, the period boundaries (`weekStart` / `weekEind`) are **stored fields of a
     persisted `CashflowWeek` object** — the grammar joins to a materialized period schema. And
     `matchFrequency` is a **date-membership test** ("does the charge date fall inside this
     week?"), a boolean, NOT `overlap_days × dailyRate` arithmetic.
   - **Runtime parameters** exist as `@params.*` but only in equality `filter`s:
     `emuSaldoByQuarter` uses `filter: { periodQuarter: "@params.quarter", periodYear:
     "@params.year" }` to select whole pre-bucketed rows. The `calendar` aggregation
     (`taxAfdrachtProjection`) interpolates `{year}` into hard-coded due-dates. No site uses
     `@params.*` inside a `datediff` / overlap expression.

**Finding (high confidence):** the grammar **cannot** express runtime-period-parameterized
interval-overlap proration. Two primitives are missing together:
- **(a)** a runtime date-window pair (`@period.from` / `@period.to`) usable inside *arithmetic*
  (date-diff / overlap), not just equality filtering; and
- **(b)** an `overlap_days([a,b],[c,d]) × rate` reducer. The existing proration is a date-membership
  boolean against a *persisted* `CashflowWeek`, which would require materializing every possible
  dashboard date range as period objects — exactly what a runtime metric must avoid.

The grammar could only approximate this by pre-materializing recognition into a persisted
period schema via a nightly job (the path the heavyweight `bookkeeping-ifrs15-revenue` spec
takes with its `RevenueWaterfall` register "calculated nightly"). For a *runtime* dashboard
date range that is not viable.

**Conclusion:** recognition is an **ADR-031 EXCEPTION** — a thin
`OCA\Shillinq\Recognition\RevenueRecognitionService`. The exact precedent already exists in this
register: `emuSaldoByQuarter` declares `guard: OCA\Shillinq\Guard\EmuCalculator::computeQuarterlySaldo`
"if the declarative engine cannot express the combined … filter," and the same register's
`IPAssetValuation` uses guards. The recognition service is the same shape. The schemas stay
declarative `config`; only the recognition is `code`. This makes the overall work **MIXED** →
split into a chain per **ADR-032** (head `config` schemas here, `-engine` `code` next).

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Decision | Rationale / missing primitive |
|---|---|---|
| `SalesOrder` storage + lifecycle (`active`/`ended`) + audit trail + RBAC | **Declarative schema** | Standard OR schema with `x-openregister-lifecycle` + `x-openregister-audit-trail` + `administrationId` RBAC (cf. `CashflowRecurring`, `APTransaction`). No code. |
| `SalesOrderLine` storage + `nature` / `recognitionMethod` enums + term-inheritance shape | **Declarative schema** | Plain schema fields + enums + nullable term fields. No code. |
| Per-object derived flags (e.g. `isOneOff`, `isInTerm(today())`) | **Declarative `x-openregister-calculations`** | Ternary on `@self.*` + `today()` — exactly the `RetentionRule.daysUntilRetention` shape. (Optional convenience; not required for the metric.) |
| **Recognized recurring revenue for `[periodFrom, periodTo]`** (overlap proration × frequency normalization, folded over `RECURRING` lines) | **ADR-031 EXCEPTION — `code`** (`RevenueRecognitionService`, chained `-engine` change) | Missing primitives **(a)** runtime date-window usable in date-diff arithmetic (today `@params.*` is equality-only) and **(b)** an `overlap_days × rate` reducer. Existing proration (`recurringExpansion`) is a date-membership boolean against a *persisted* `CashflowWeek`, not runtime-window overlap. Precedent: `EmuCalculator` guard in the same register. |
| One-off recognition (point-in-time at `recognitionDate` / over-time across delivery window) | **Same exception service** | Same runtime-period overlap arithmetic for the over-time case; trivially in the service. |
| ARR / run-rate secondary view | **Same exception service (cheap)** | Annualized current monthly rate of in-term lines; derived alongside the primary figure. |

**OR-engine extensibility — verified against OpenRegister source, not assumed.** We checked whether
extending OR could keep recognition declarative. OR runs two custom PHP interpreters:
`CalculationEvaluator` (per-object JSON-AST, one object at a time) and `AggregationRunner`
(PHP-fallback reducer + native-SQL fast path). Two *architectural* gaps block a declarative
recognition metric — not one missing function:
- **No per-row computed-value reducer** — `AggregationRunner::computeMetric` sums a *single raw
  column*; there is no slot to fold a computed `overlap × rate` per row before reduction.
- **No query-time parameter binding for named aggregations** — the dashboard date range cannot reach
  a named aggregation (`AggregationController::aggregate()` passes no request params; placeholders are
  now-relative only, resolved server-side). `@period.*` does not exist anywhere in the engine.

Adding an `overlapMonths()` function is trivial (~30 lines) but useless without both gaps filled, and
filling them forces every recognition KPI onto OR's PHP-fallback path (10 000-row cap, cache-defeating
per-period keys), discarding the native-SQL fast path the engine is built around — a cross-cutting
change to 6–8 files across two interpreters, introducing a SQL-untranslatable metric class and a
security-sensitive query-time param surface, for one leaf app's proration math.

**Verdict:** the overlap reducer stays in the shillinq service — this is the durable answer, not a
stopgap. The one clean, *reusable* OR contribution worth a separate `openregister` issue is generic
query-time `@period.*` params on the **ad-hoc** aggregation endpoints (benefits every app's
date-ranged dashboards); but the per-row interval-overlap *reducer* must stay out of OR's aggregation
core because it is inherently PHP-only and breaks the native-SQL contract.

## Decisions

- **D1 — Order + line, not a Contract entity.** Model the booking as `SalesOrder` + lines with
  an optional `contractId` string. *Alternatives:* (i) full `Contract → Order → Line` (rejected —
  the user explicitly chose order+line; a Contract entity duplicates the `bookkeeping-ifrs15-revenue`
  capability and over-models the dashboard need); (ii) reuse `CashflowRecurring` (rejected — it is
  a cashflow-projection entry, not a revenue booking; conflating the two is what the retired MRR
  change got wrong).
- **D2 — `amount` semantics by `nature`.** `RECURRING` → per-interval amount normalized to a
  monthly rate via `frequencyFactor`; `ONE_OFF` → total. *Alternative:* always-monthly amount
  (rejected — forces the user to pre-normalize annual/quarterly contracts, losing the as-signed figure).
- **D3 — Term inheritance.** Null line `termStart`/`termEnd` inherit the order's term. *Alternative:*
  mandatory per-line terms (rejected — most lines share the order term; inheritance keeps seed +
  data entry terse, matches `CashflowRecurring.geldigVan/geldigTot` ergonomics).
- **D4 — Recognition is an ADR-031 exception service, chained (ADR-032).** See the feasibility
  investigation + decision table. *Alternative:* nightly-materialize into a persisted period
  schema and aggregate declaratively (rejected for a *runtime* dashboard date range — that is the
  heavyweight `RevenueWaterfall` path, wrong granularity for an interactive range).
- **D5 — Period granularity = whole-month proration (provisional).** `overlapMonths` counts whole
  calendar months of overlap. *Alternative:* daily proration (`overlap_days / days_in_month`).
  Whole-month matches how SaaS terms are signed and keeps the figure stable across a month;
  daily proration is more precise for mid-month starts. Deferred — see Open Questions; the service
  isolates the choice to one helper.

## Risks / Trade-offs

- [The retired MRR change may have left stale references in the pipelinq dashboard or docs] →
  Mitigation: the downstream pipelinq widget change re-sources the tile from the recognition
  endpoint; this head change touches no pipelinq code. Audit pipelinq for `CashflowRecurring`-run-rate
  reads during the widget change.
- [Whole-month vs daily proration disagreement could surface later] → Mitigation: D5 isolates the
  granularity to a single `overlapMonths` helper in the `-engine` service; switching is a one-line
  change with a unit test, not a schema migration.
- [OR could later add a runtime-period overlap primitive, making the service redundant] →
  Mitigation: file the OR issue now; the exception is explicitly documented as removable.
- [Indefinite `termEnd` (null) extending "to period end" could over-recognize beyond a real
  end-of-life] → Mitigation: an indefinite order is genuinely open-ended; recognition is bounded by
  `periodTo` which is the correct behaviour for a reporting window.

## Seed Data (ADR-001)

Seed one realistic MKB/consultancy `SalesOrder` with three mixed lines, using safe placeholders
(nil UUID `00000000-0000-0000-0000-000000000000`, `<...>`, UPPERCASE business keys). The mix makes
the recurring/one-off split and a sample-period figure demonstrable.

**SalesOrder** `ORDER-2026-0001`
- `orderId`: `ORDER-2026-0001`
- `ondernemingId`: `<ONDERNEMING-NIL>` (`00000000-0000-0000-0000-000000000000`)
- `administrationId`: `<ADMIN-NIL>` (`00000000-0000-0000-0000-000000000000`)
- `klantId`: `KLANT-ACME-CONSULTING`
- `orderDate`: `2026-01-01`
- `termStart`: `2026-01-01`
- `termEnd`: `2026-12-31`
- `status`: `active`
- `currency`: `EUR`
- `contractId`: `CONTRACT-2026-0001` (plain string — no Contract object exists)

**SalesOrderLine 1 — SaaS subscription (RECURRING JAARLIJKS)** `ORDERLINE-2026-0001-A`
- `orderId`: `ORDER-2026-0001`, `nature`: `RECURRING`, `frequentie`: `JAARLIJKS`,
  `recognitionMethod`: `OVER_TIME`, `amount`: `12000.00` (EUR/year → monthlyRate 1000),
  `termStart`/`termEnd`: null (inherit order), `accountNumber`: `8100`.

**SalesOrderLine 2 — Implementation fee (ONE_OFF POINT_IN_TIME)** `ORDERLINE-2026-0001-B`
- `orderId`: `ORDER-2026-0001`, `nature`: `ONE_OFF`, `frequentie`: null,
  `recognitionMethod`: `POINT_IN_TIME`, `amount`: `5000.00`, `recognitionDate`: `2026-01-15`,
  `accountNumber`: `8200`. Recognized point-in-time in January; NOT recurring revenue.

**SalesOrderLine 3 — Monthly retainer (RECURRING MAANDELIJKS)** `ORDERLINE-2026-0001-C`
- `orderId`: `ORDER-2026-0001`, `nature`: `RECURRING`, `frequentie`: `MAANDELIJKS`,
  `recognitionMethod`: `OVER_TIME`, `amount`: `1500.00` (monthlyRate 1500),
  `termStart`/`termEnd`: null (inherit order), `accountNumber`: `8100`.

**Worked sample — recognized recurring revenue for `[2026-01-01, 2026-03-31]` (3 months, whole-month):**
- Line 1 (JAARLIJKS, monthlyRate 1000): 1000 × 3 = **3000**
- Line 3 (MAANDELIJKS, monthlyRate 1500): 1500 × 3 = **4500**
- Line 2 (ONE_OFF): **excluded** from recurring (appears as a separate 5000 one-off recognized in Jan)
- **Recognized recurring revenue = 7500**; one-off = 5000 (Jan only).

## Migration Plan

- **Deploy:** the implementing cycle adds `SalesOrder` + `SalesOrderLine` (with the seed above) to
  `lib/Settings/shillinq_register.json` and registers the two entities in
  `openspec/architecture/adr-000-data-model.md`. Expand-then-contract: the new schemas are additive;
  no existing consumer changes. The `-engine` change then ships the service + endpoint; the pipelinq
  widget opts in last.
- **Rollback:** remove the two schemas from the register (no other schema references them as a hard
  FK); no data migration since this head change ships only seed data.

## Open Questions

- **Period granularity (D5):** whole-month vs daily proration for `overlapMonths`. Provisional:
  whole-month. Resolve before the `-engine` service ships; isolated to one helper.
- **One-off over-time window:** for `ONE_OFF` + `OVER_TIME` lines, what defines the delivery window
  when no explicit window field exists? Provisional: reuse `termStart`/`termEnd` (inherited).
  Confirm whether a dedicated `deliveryStart`/`deliveryEnd` pair is needed (would be a `SalesOrderLine`
  schema addition in this head change).
