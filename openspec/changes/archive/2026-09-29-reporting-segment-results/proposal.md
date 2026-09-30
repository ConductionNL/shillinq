---
kind: code
depends_on: [ledger-posting-path, glline-administration-scope]
---

# Proposal: reporting-segment-results

## Summary

A manager wants profit and loss per cost centre, project or other dimension.
Shillinq tags every ledger line with its dimensions and has a segment
dashboard, but the dashboard adds debit and credit amounts together,
includes balance sheet lines and drafts, and so shows a gross total of
postings rather than a result. This change signs each line, keeps only
posted profit and loss lines, and shows revenue, costs and result per
segment.

## Motivation

Two rows of the shillinq capability matrix
(`openspec/parity/capabilities.json`, compared 2026-09-26) share this
missing half; the OpenSpec pass of 2026-09-27 decided `build` for both.

**`led-dimensions`**, "Tag entries with a cost centre, project or other
dimension and report on it." Rated partial, built. Matrix evidence: those
aggregations "(lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json:385,450)
are `metric: sum, field: amount` with an empty filter, so debit and credit
legs and balance-sheet accounts add up unsigned; the roll-up is not a signed
result." Note: "Tagging works; the report sums amount without side or
account-type sign." All five competitors rate it yes, for example
exact-online (https://www.exact.com/nl/producten/boekhouden/features-en-prijzen,
"Boek kosten en opbrengsten op afdeling of vestiging (kostenplaatsen)"),
snelstart (https://kennisplein.snelstart.nl/snelstartpolaris/wat-kan-ik-met-een-kostenplaats),
twinfield (https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/dimensietypen-3041038),
moneybird (https://helpcenter.moneybird.nl/nl/articles/207266-omzet-en-kosten-per-project)
and odoo (`addons/account/models/account_move_line.py:438`
`analytic_distribution`).

**`rep-segment-pnl`**, "See profit and loss per segment, cost centre or
project." Rated partial, built. Matrix evidence: "each is `metric: sum,
field: amount` with `filter: {}` (lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json:385-465):
debit and credit add up unsigned, balance-sheet lines and unposted lines
are included, so the figure is gross turnover of postings, not a profit or
loss." All five competitors rate it yes, for example twinfield
(https://taasupportportal.wolterskluwer.com/nl/nl-nl/artikel/rapporten-3041146,
"Details weergeven van: ... (relatie, kostenplaats, project, activum en/of
activiteit)") and snelstart
(https://kennisplein.snelstart.nl/klanten/s/article/financi%C3%ABle-rapportages-in-versie-12,
"Werk je met kostenplaatsen? Dan kun je ... hiervan een overzicht
opvragen").

## Affected Projects

- [ ] Project: `shillinq`: a signed amount and two posting stamps on ledger lines, corrected aggregations, and the segment dashboard columns.

## Scope

### In Scope

- `GLLine.signedAmount` (credit positive, debit negative), declared.
- `GLLine.accountClass` and `GLLine.countsInResult`, stamped when the transaction posts or is reversed.
- The five segment aggregations filtered to posted profit and loss lines and summing `signedAmount`, split into revenue and costs.
- The segment dashboard showing revenue, costs and result per segment for a chosen period.

### Out of Scope

- A balance sheet per segment.
- Allocating overhead to segments; allocation rules already exist and run when a transaction posts (`ledger-posting-path`).

## Approach

Declare the sign as a calculation, stamp what the line cannot know itself
when its transaction posts, and filter the aggregations on it. Details are
in design.md.

## New Dependencies

None.

## Impact

- `lib/Settings/register.d/bookkeeping-cost-centers-dimensions.json`: the aggregations; a fragment for the three `GLLine` fields.
- `lib/Listener/`: the posting stamp.
- `src/views/bookkeeping/dimensions/SegmentPnLDashboard.vue`: columns and period filter.

## Cross-Project Dependencies

None. Relies on OpenRegister's `x-openregister-calculations` JSON-AST `if`
(listed in this repo's `declarative-calc-layer` spec) and on its aggregation
endpoint the dashboard already calls.

## Risks

### Risk 1: Historic lines have no stamps
**Severity:** Medium. **Mitigation:** a repair step stamps every line of already posted and reversed transactions once, and the dashboard shows the date from which figures are complete until it has run.

## Rollback Strategy

Restore the previous aggregation definitions. The new fields stay as inert
data.

## Open Questions

None.
