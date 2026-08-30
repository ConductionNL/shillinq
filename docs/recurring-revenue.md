# Recurring revenue: why shillinq and pipelinq show different numbers

**Spec:** [`order-revenue-recognition`](../openspec/changes/order-revenue-recognition/specs/recurring-revenue-recognition/spec.md) (config head) + [`order-revenue-recognition-engine`](../openspec/changes/order-revenue-recognition-engine/specs/recurring-revenue-recognition/spec.md) (engine).
**Endpoint:** `GET /index.php/apps/shillinq/api/recognition/recurring-revenue?administrationId=<id>&from=<date>&to=<date>` → `{ recognized, arr, currency, lineCount }`.
**Service:** `lib/Recognition/RevenueRecognitionService.php`. **Controller:** `lib/Controller/RecognitionController.php#recurringRevenue`.

## The two numbers are different on purpose

shillinq shows **recognized recurring turnover** — the IFRS 15 over-time figure: each `RECURRING` `SalesOrderLine` is normalized to a monthly rate and recognized **only for the whole calendar months its term overlaps the fiscal period you are looking at**. It deliberately *excludes* revenue that has not yet been earned and *prorates* partial periods, so it ties back to the books for that period. pipelinq's CRM tile shows the **recurring run-rate** instead — the sum of the monthly-normalized value (`maandWaarde`) of every *active* recurring order line, ignoring period and term boundaries entirely. The run-rate answers "what are we billing per month right now?"; the recognized figure answers "how much recurring turnover did we actually earn in this period?". They will not match whenever a term starts or ends inside the reporting window, when a line is not yet in-term, or when you look at a period shorter or longer than a month — and that divergence is correct, not a bug.

Both build on the same per-line monthly normalization. `maandWaarde = amount × frequencyFactor(frequentie)` with `MAANDELIJKS`=1, `KWARTAALS`=1/3, `JAARLIJKS`=1/12, `WEKELIJKS`=52/12, `TWEEWEKELIJKS`=26/12, and `0` for `ONE_OFF` lines (one-offs never count toward a run-rate). pipelinq sums `maandWaarde` (filtered to `nature=RECURRING`) as a plain OpenRegister aggregation. shillinq takes the same monthly rate and multiplies it by the number of whole months the line's term overlaps the period.

## Worked example (seed order `ORDER-2026-0001`)

The seed order has three lines: A = SaaS `RECURRING JAARLIJKS` €12 000 (→ €1 000/mo), B = implementation `ONE_OFF POINT_IN_TIME` €5 000 (recognitionDate 2026-01-15), C = retainer `RECURRING MAANDELIJKS` €1 500. Order term `[2026-01-01, 2026-12-31]`.

- **pipelinq run-rate** = `maandWaarde` of the recurring lines = €1 000 (A) + €1 500 (C) = **€2 500/mo**. B contributes €0 (one-off). This is period-independent.
- **shillinq recognized turnover** depends on the period you pick. For **Q1 [2026-01-01, 2026-03-31]** (3 whole months of overlap) it is €1 000 × 3 + €1 500 × 3 = **€7 500**, with the €5 000 implementation fee reported *separately* as a one-off (recognized in January because its recognitionDate falls in the period), never folded into the recurring figure. For a single month it would be €2 500; for a line that only starts mid-year the recognized figure for an early period would be €0 while the run-rate still shows it once it is active.

The endpoint also returns `arr` = 12 × the run-rate of in-term recurring lines (here 12 × €2 500 = €30 000) as an informational annualized view, kept distinct from the period `recognized` figure.
