## Context

The head change `order-revenue-recognition` (kind: config) declared `SalesOrder` +
`SalesOrderLine` and **defined** recognized recurring revenue per period (IFRS 15 over-time
recognition), but its `## Feasibility investigation` and `## Declarative-vs-imperative decision
(ADR-031)` sections proved the OpenRegister declarative grammar cannot express the metric: it is an
interval-overlap proration parameterized by a **runtime** reporting window `[from, to]`, and OR has
neither (a) a runtime date-window usable inside date-diff arithmetic (`@params.*` is equality-only,
never date-diff) nor (b) an `overlap_days × rate` per-row reducer. The existing proration precedent
(`CashflowForecastHorizon.recurringExpansion`) is a date-membership boolean against a *persisted*
`CashflowWeek`, not runtime-window overlap arithmetic. The head therefore deferred the computation
to this `code` change (ADR-032 chain).

This change implements the deferred arithmetic as the documented **ADR-031 exception**: a thin
`OCA\Shillinq\Recognition\RevenueRecognitionService` plus a thin read controller + route. The exact
precedent lives in this register — `emuSaldoByQuarter` declares
`guard: OCA\Shillinq\Guard\EmuCalculator::computeQuarterlySaldo` "if the declarative engine cannot
express the combined filter," and `RevenueController` is a read-only derived endpoint that delegates
to a service and validates the administration scope (ADR-005). This change follows both shapes.

The service reads data through OpenRegister's **ObjectService** (ADR-022) — no app-owned tables, no
direct SQL. The schemas and seed data ship in the head change; this change is inert until they merge.

## Goals / Non-Goals

**Goals:**
- Implement `RevenueRecognitionService` computing recognized **recurring** revenue for a runtime
  period `[from, to]` via whole-month overlap proration + frequency normalization, folded over
  `RECURRING` lines.
- Compute one-off recognition separately (point-in-time + over-time) and surface ARR alongside —
  never folding one-off into the recurring figure.
- Expose one read endpoint (`#[NoAdminRequired]`, per-`administrationId` RBAC, route in
  `appinfo/routes.php`) returning a clean shape the pipelinq dashboard widget can consume for its
  date range.
- Ship ≥4 PHPUnit cases pinning the arithmetic (full-month, partial overlap, one-off in/out, empty).

**Non-Goals:**
- Any schema, seed, or register edit — the head owns the data model (this change touches no
  `shillinq_register.json`).
- The pipelinq dashboard widget itself — downstream `pipelinq-recognized-recurring-revenue-widget`.
- A full IFRS 15 `Contract` / `PerformanceObligation` / `RevenueWaterfall` model — that is the
  separate `bookkeeping-ifrs15-revenue` capability (`RevenueController` / `RevenueCutoffService`).
- Daily proration — whole-month is the locked decision (D5 in the head design); the helper isolates
  the choice so a future switch is a one-line, unit-tested change.
- Extending OpenRegister with a runtime-overlap primitive — the head documents that as a removable,
  separately-filed OR contribution; the reducer stays in the shillinq service (the durable answer).

## The recognition algorithm

All money is handled in **euro-cents (integers)** internally to avoid IEEE-754 drift (the
`EmuCalculator` precedent does the same), converting to a decimal euro `number` only at the response
boundary. `amount` is read as a decimal and multiplied/rounded in integer cents.

### Frequency normalization — `monthlyRate(line)`

`monthlyRate(line) = amount(line) × frequencyFactor(frequentie(line))`, normalizing each recurring
line's per-interval `amount` to a per-month rate:

| `frequentie`    | frequencyFactor (per month) | example: amount → monthlyRate |
|-----------------|-----------------------------|-------------------------------|
| `MAANDELIJKS`   | 1                           | 1500 → 1500                   |
| `KWARTAALS`     | 1/3                         | 3000 → 1000                   |
| `JAARLIJKS`     | 1/12                        | 12000 → 1000                  |
| `WEKELIJKS`     | 52/12                       | 100 → 433.33                  |
| `TWEEWEKELIJKS` | 26/12                       | 200 → 433.33                  |

A `RECURRING` line with a null/unknown `frequentie` is a data error → the line contributes 0 and the
service logs a diagnostic (fail-closed; never throw to the client).

### Whole-month overlap — `overlapMonths(term, period)`

`termOf(line)` = the line's `[termStart, termEnd]`, inheriting the order's `[termStart, termEnd]`
where a bound is null. An open-ended `termEnd` (null on both line and order) extends to `period.to`
for the overlap computation (bounded by the reporting window — the correct behaviour for a report).

`overlapMonths(term, period)` = whole calendar months in the intersection of the two intervals:

```
intersectStart = max(term.start, period.from)
intersectEnd   = min(term.end,   period.to)            # term.end := period.to when indefinite
if intersectStart > intersectEnd: return 0             # no overlap
months = (year(intersectEnd) - year(intersectStart)) * 12
       + (month(intersectEnd) - month(intersectStart))
       + 1                                             # inclusive of both boundary months
return max(0, months)
```

Whole-month rounding means a term touching any part of a calendar month counts that whole month
(D5). Example: term `[2026-01-15, 2026-12-31]` ∩ period `[2026-01-01, 2026-03-31]` →
`intersect [2026-01-15, 2026-03-31]` → `(2026-2026)*12 + (3-1) + 1 = 3` months. A mid-month start
does NOT reduce the count — this is the documented whole-month behaviour, pinned by a unit test.

### Recurring fold

```
recognizedRecurring([from,to]) =
  Σ over lines L where nature(L) == RECURRING of
     monthlyRate(L) × overlapMonths(termOf(L), [from,to])
```

### One-off recognition (separate figure)

For lines where `nature(L) == ONE_OFF` (excluded from the recurring fold):

- `recognitionMethod == POINT_IN_TIME` → recognized in **full** (`amount`) iff
  `recognitionDate ∈ [from, to]`, else 0.
- `recognitionMethod == OVER_TIME` → prorated across the line's term (reuse `termOf(L)` — no separate
  delivery-window fields): `amount × overlapMonths(termOf(L), [from,to]) / totalTermMonths(L)`, where
  `totalTermMonths(L) = overlapMonths(termOf(L), termOf(L))`. When the total term is 0 (degenerate),
  contributes 0.

The one-off total is returned (or computable) but reported **separately** — the widget shows the
recurring figure. The endpoint's primary `recognized` field is the **recurring** number.

### ARR (secondary)

`arr = 12 × Σ over RECURRING lines in-term at period.to of monthlyRate(L)` — annualized current
run-rate of lines whose term contains `period.to`. Cheap; derived alongside. Distinct from
`recognized` (a period figure), so both ship in the response.

### Worked example (reuses the head's seed order `ORDER-2026-0001`)

Head seed: Line A SaaS `RECURRING JAARLIJKS` amount 12000 (→ monthlyRate 1000); Line B impl
`ONE_OFF POINT_IN_TIME` amount 5000 `recognitionDate 2026-01-15`; Line C retainer `RECURRING
MAANDELIJKS` amount 1500. Order term `[2026-01-01, 2026-12-31]`.

For `[2026-01-01, 2026-03-31]` (3 whole months):
- A: 1000 × 3 = **3000**; C: 1500 × 3 = **4500** → `recognized` (recurring) = **7500**.
- B (one-off, `recognitionDate 2026-01-15` ∈ period): one-off = **5000**, reported separately.
- `arr` = 12 × (1000 + 1500) = **30000**; `lineCount` = 2 (recurring lines in the fold).

## Service / controller / route shape

### Service — `OCA\Shillinq\Recognition\RevenueRecognitionService`

- Namespace `OCA\Shillinq\Recognition` (new sub-namespace; mirrors the `OCA\Shillinq\Recognition\…`
  path the head's design + spec name for the exception service). Lazy DI of OR's `ObjectService`
  via the DI container (matches `RevenueCutoffService`), plus `LoggerInterface`.
- Public entry point:
  `public function computeRecurring(string $administrationId, string $from, string $to): array`
  returning `['recognized' => float, 'oneOff' => float, 'arr' => float, 'currency' => string,
  'lineCount' => int]`. (The controller projects the response subset.)
- Reads `SalesOrder` + `SalesOrderLine` objects filtered by `administrationId` through ObjectService
  (ADR-022) — OR enforces multitenancy, so the service never cross-reads administrations. Term
  inheritance (null line bounds ← order bounds) is resolved in PHP after the read.
- Pure helpers `monthlyRate()`, `overlapMonths()`, `frequencyFactor()` kept private + side-effect
  free so the unit tests can exercise the arithmetic deterministically (no clock, no I/O).

### Controller — `OCA\Shillinq\Controller\RecognitionController`

Mirrors `RevenueController` exactly (the in-repo ADR-005 precedent):
- `#[NoAdminRequired]` read method `recurringRevenue()`.
- **Auth body-guard:** reject when `userSession->getUser() === null` (401).
- **Per-`administrationId` RBAC / no IDOR:** validate `administrationId` against
  `^[A-Za-z0-9_.\-]{1,64}$` and require it; reads delegate to ObjectService which enforces the
  administration scope server-side — an authenticated user cannot read another administration's
  orders by passing its id (ADR-005 Rule 3 / no-admin-idor gate). Validate `from`/`to` as ISO dates
  (`^\d{4}-\d{2}-\d{2}$`) and that `from <= to`.
- `try/catch (\Throwable)` → log server-side (no stack trace to client), return 500 with a generic
  message. Never `catch → return null` on the data path (unsafe-auth-resolver gate).

### Route — `appinfo/routes.php` (ADR-016)

One entry appended to the `$extra` array passed to `Routes::standard([...])`:
`['name' => 'recognition#recurringRevenue', 'url' => '/api/recognition/recurring-revenue', 'verb' => 'GET']`.

`GET /api/recognition/recurring-revenue?administrationId=<id>&from=<date>&to=<date>` →

```json
{ "recognized": 7500, "arr": 30000, "currency": "EUR", "lineCount": 2 }
```

`recognized` is the **recurring** figure (the widget's number). `arr` and `lineCount` are
informational. The one-off figure is computed internally; whether to also expose it on this endpoint
is an open question below.

**Widget-source note (downstream concern):** OR's abstract stat widget can source a tile from an
endpoint that returns `{ "value": <number> }`. Returning a `value`-compatible shape (or adding a
`"value"` alias mirroring `recognized`) would let the pipelinq widget bind with minimal custom glue
instead of a bespoke fetcher. This is flagged for the 3rd chain link
(`pipelinq-recognized-recurring-revenue-widget`), NOT decided here — see Open Questions.

## Declarative-vs-imperative decision (ADR-031)

This change **is** the exception leg of the head's decision table. Stated plainly with the head's
OR-engine evidence:

| Behaviour | Decision | Rationale / missing primitive |
|---|---|---|
| `SalesOrder` / `SalesOrderLine` storage, enums, lifecycle, audit, RBAC | **Declarative schema (head change)** | Standard OR schema; no code. Untouched here. |
| Per-object derived flags (`isOneOff`, `isInTerm(today())`) | **Declarative `x-openregister-calculations`** | Ternary on `@self.*` + `today()` (head); not needed by this service. |
| **Recognized recurring revenue for `[from, to]`** (overlap proration × frequency normalization, folded over `RECURRING` lines) | **ADR-031 EXCEPTION — `code` (this change)** | Missing OR primitives **(a)** runtime date-window usable in date-diff arithmetic (today `@params.*` is equality-only) and **(b)** an `overlap_days × rate` per-row reducer (`AggregationRunner::computeMetric` sums a single raw column; no slot to fold a computed `overlap × rate`). The existing proration (`recurringExpansion`) is a date-membership boolean against a *persisted* `CashflowWeek`, not runtime-window overlap. Materializing every dashboard range as period objects is exactly what a runtime metric must avoid. Precedent: `EmuCalculator` guard. |
| One-off recognition (point-in-time / over-time) | **Same exception service** | Same runtime-period overlap arithmetic; trivially in the service. |
| ARR / run-rate secondary view | **Same exception service (cheap)** | Annualized current monthly rate of in-term lines; derived alongside. |
| Read endpoint (period-parameterized) | **Thin controller + route (ADR-003/016)** | Derived read; mirrors `RevenueController`. `AggregationController::aggregate()` passes no request params, so a named aggregation cannot receive the dashboard range — the controller must (head finding). |

**Verdict (from the head, restated):** the overlap reducer stays in the shillinq service — the
durable answer, not a stopgap. The one clean reusable OR contribution worth a separate `openregister`
issue is generic query-time `@period.*` params on the **ad-hoc** aggregation endpoints; the per-row
interval-overlap *reducer* stays out of OR's core because it is inherently PHP-only and breaks the
native-SQL contract. This service is explicitly removable if OR ever lands both primitives.

## Test Plan

PHPUnit, `tests/unit/Recognition/RevenueRecognitionServiceTest.php`. The service's arithmetic helpers
are pure (no clock, no I/O); the ObjectService read is stubbed/mocked so each case feeds a fixed set
of `SalesOrder` + `SalesOrderLine` arrays and asserts the returned figures. ≥4 cases (mandatory):

1. **Full-month recurring** — one `RECURRING JAARLIJKS` line, amount 12000 (monthlyRate 1000), term
   `[2026-01-01, 2026-12-31]`, period `[2026-01-01, 2026-03-31]` → `recognized == 3000`,
   `lineCount == 1`. Plus a `MAANDELIJKS` 1500 line in the same order → `recognized == 7500`,
   `arr == 30000` (reproduces the head's worked sample on seed `ORDER-2026-0001`).
2. **Mid-month partial overlap (whole-month rounding)** — `RECURRING MAANDELIJKS` line, monthlyRate
   1000, term `[2026-01-15, 2026-12-31]`, period `[2026-01-01, 2026-03-31]` → `recognized == 3000`
   (the mid-month Jan start still counts the whole of January — pins D5 whole-month behaviour, not
   daily). A second assertion with term `[2026-03-20, 2026-12-31]` and the same period → `1000`
   (March only).
3. **One-off point-in-time in / out of period** — `ONE_OFF POINT_IN_TIME` line amount 5000,
   `recognitionDate 2026-01-15`: for period `[2026-01-01, 2026-01-31]` the one-off figure == 5000 and
   `recognized` (recurring) == 0; for period `[2026-02-01, 2026-02-28]` the one-off figure == 0. In
   both cases the one-off amount is **never** added to `recognized` (the recurring/one-off split).
4. **Empty / no lines → 0** — a `SalesOrder` with no `SalesOrderLine`s (or no orders for the
   administration) → `recognized == 0`, `oneOff == 0`, `arr == 0`, `lineCount == 0`. No exception
   thrown; the empty case is a clean zero, not an error.

Supplementary (recommended, not required for the ≥4 minimum): a non-overlapping term contributes 0;
`KWARTAALS`/`WEKELIJKS` frequencyFactor normalization (3000 → 1000, 100 → 433.33); a `RECURRING` line
with null `frequentie` contributes 0 and logs (fail-closed). The controller's RBAC/validation
behaviour (401 unauthenticated, 400 malformed `administrationId`/dates, scope isolation) is asserted
in a thin `RecognitionControllerTest` where practical, mirroring `RevenueController` coverage.

## Risks / Trade-offs

- [Whole-month rounding disagrees with a stakeholder who expects daily proration] → Mitigation: D5
  is locked whole-month; `overlapMonths()` is a single isolated helper — switching to daily is a
  one-line change + one new unit assertion, no schema/API change. Documented here and in the head.
- [Indefinite `termEnd` (null) over-recognizing beyond a real end-of-life] → Mitigation: an
  indefinite order is genuinely open-ended; recognition is bounded by `period.to`, the correct
  reporting-window behaviour (head risk, restated).
- [Floating-point drift in monthly rates (e.g. WEKELIJKS 433.33…)] → Mitigation: compute in integer
  euro-cents internally (EmuCalculator precedent), round once at the response boundary; unit tests
  assert exact cent figures.
- [Endpoint shape churns when the pipelinq widget adopts it] → Mitigation: keep the response additive
  (a `value` alias can be added without breaking `recognized`); the widget-source decision is
  explicitly deferred to the downstream change (Open Questions).
- [Service inert / 500 if merged before the head's schemas exist] → Mitigation: `depends_on:
  [order-revenue-recognition]`; the service fail-closes to 0/empty when no `SalesOrder` schema is
  present (treats "no objects" as zero), and the chain ordering guarantees the head merges first.
- [IDOR via `administrationId` param] → Mitigation: reads go through ObjectService which enforces the
  administration scope server-side; the controller additionally validates the id format and rejects
  unauthenticated callers (ADR-005, no-admin-idor gate). Asserted in the controller test.

## Migration Plan

- **Deploy:** ships only PHP (service + controller + route + tests) + any i18n key. Additive — no
  schema, no seed, no data migration. The route name `recognition#recurringRevenue` is new (no
  collision with `Routes::standard()` or existing `$extra` entries). Inert until the head's schemas
  are present; the downstream pipelinq widget opts in last.
- **Rollback:** remove the route entry + the two new PHP files + the test; no data or schema state to
  unwind (no register edits). The head change is independent and remains valid.

## Open Questions

- **Endpoint path + response shape / `value`-mirror.** Provisional:
  `GET /api/recognition/recurring-revenue` →
  `{ recognized, arr, currency, lineCount }`. Whether to also emit a `{ "value": recognized }` alias
  (so OR's abstract stat widget binds with no custom glue) is deferred to the downstream pipelinq
  widget change; this design keeps the response additive so the alias can be added non-breaking.
  *Affected artifact:* design.md (this), `appinfo/routes.php`, downstream pipelinq change.
- **Expose a per-line recognition breakdown?** Provisional: NO — the endpoint returns the aggregate
  (`recognized`/`oneOff`/`arr`/`lineCount`) only; a per-line breakdown is a later drill-down concern,
  not needed by the dashboard tile. *Affected artifact:* design.md, RecognitionController response.
- **How is `administrationId` resolved — request param vs. session/user context?** Provisional:
  **request param**, validated + RBAC-enforced via ObjectService (matches `RevenueController`, and
  the dashboard widget passes the selected administration). The in-repo `RevenueCutoffService`
  resolves it from server context in some call paths; confirm the dashboard supplies the
  administration explicitly so the param path is correct. *Affected artifact:* RecognitionController,
  spec scenarios.
- **Is `arr` part of the endpoint, or recurring-only?** Provisional: include `arr` (cheap, useful
  secondary view) but mark it informational; the widget reads `recognized`. *Affected artifact:*
  design.md response shape, spec.
- **One-off figure on this endpoint?** Provisional: computed internally, NOT a top-level field of
  this recurring endpoint by default (kept separate per the locked decision); add only if the widget
  needs it. *Affected artifact:* RecognitionController response, downstream pipelinq change.
