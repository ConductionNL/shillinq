# Change: spend-analytics-ui

## Why

`GET /apps/shillinq/api/analytics/spend` is fully implemented, routed
(`appinfo/routes.php`, `spendAnalytics#spend`), administration-scoped and
covered by two PHPUnit suites — and it has **zero consumers**. Verified at the
base of this branch:

```
$ grep -rn "analytics/spend" src/
$ grep -rln "SpendAnalytics" src/manifest.json src/manifest.d/*.json
```

Both return nothing. Four working views — spend by supplier, by category, by
cost centre, by period — reach no user.

The alternative on the table was retiring the endpoint. That was rejected: the
views answer a question the app has no other surface for, and the aggregation
is already delegated to OpenRegister's aggregation-api per ADR-022, so there is
nothing leaf-side to maintain. This change gives it a consumer instead.

## What this change must get right

Not the happy path — the failure path.

`glline-administration-scope` REQ-GLS-003 makes the three GL-backed views
(`category`, `costCentre`, `period`) **raise** while the
`GLLine.administrationId` backfill is unproven, which
`SpendAnalyticsController::spend()` turns into `HTTP 500 { "error": "Failed to
compute spend analysis" }`. That raise is deliberate: filtering on a property
some rows still lack matches nothing for those rows, so the alternative to
raising is a silent zero in a bookkeeping total — a wrong number that looks
like a real one.

A UI that renders that 500 as an empty chart, a blank panel or a `€0.00` tile
converts the raise straight back into the silent zero it exists to prevent. So
this change's load-bearing requirement is that an unavailable view is rendered
as unavailable, in words, with no figure attached.

### Why the declarative widgets could not be used

Measured against `@conduction/nextcloud-vue` at the version this repo pins:

- `CnChartWidget` subscribes to `useEndpointSource` and returns only
  `{ dsData, dsRefetch, epData, epRefetch, chartTokenCtx }` from its `setup()`
  — `ep.error` is **discarded**. A 500 therefore reaches the user as the
  widget's `emptyLabel`, i.e. "no data".
- `CnStatWidget` / `CnDeltaWidget` do surface it, as
  `<span class="cn-stat-widget__error" :title="displayError">—</span>`: a bare
  em dash whose only explanation is a mouse-hover tooltip, carrying no
  `data-testid`.
- `CnWidgetObjectTable` binds to an OpenRegister collection, which this payload
  is not, and swallows `epError` in its template.

So the page is a `type: "dashboard"` (no growth in the gate-69 custom-**page**
count) hosting one custom `kind: "widget"` through a page slot — the
`CashflowChartWidget` precedent already on the Dashboard page — and takes
gate-52's documented `@custom-widget-ratchet exclude` with the reason above.

## Navigation

shillinq is at its ADR-097 ceiling of six top-level clusters, reached by
demoting an entry. This change adds **no** top-level entry: the page is a leaf
merged into the existing `ReportingCompliance` cluster by menu id, the same
mechanism `budget-grid-view.json` uses to nest under `Budgets`.

Measured with `tests/validate-nav-reachability.js` (the real shared
`buildManifest` pipeline), before and after:

| | before | after |
|---|---|---|
| top-level menu entries | 51 | 51 |
| — of which nav clusters (`section !== 'settings'`) | 9 | 9 |
| — of which settings-section | 42 | 42 |
| pages in the effective manifest | 571 | 572 |
| `ReportingCompliance` children | 65 | 66 |

## The `glline-administration-scope` exclusion this change invalidates

`glline-administration-scope` REQ-GLS-003 carries, **on branch
`feat/glline-administration-scope` (PR #1087, in CI at the time of writing)**:

> `@e2e exclude` `/api/analytics/spend` has NO frontend consumer — `grep -rn
> "analytics/spend" src/` returns nothing and no `src/manifest.d/` page declares
> it … Re-tag as `@e2e glline-administration-scope::…` when a UI consumer lands.

That text is **not on `origin/development`**, and therefore not in this branch.
On this base the same requirement still carries the two positive tags
`@e2e glline-administration-scope::scoped-views-still-return-rows` and
`::totals-exclude-another-administration` that #1080 planned. So there is
nothing here to retag: the exclusion and its retraction both belong to #1087's
own diff.

What this change does instead is remove the exclusion's PREMISE — a UI consumer
now exists — and record the fact where a gate can see it (REQ-SPA-006..008 in
`openspec/specs/spend-analytics/spec.md`). Whoever merges #1087 must resolve the
two branches' versions of that spec: the "has NO frontend consumer" reason is
false the moment both land.

Note also that neither of #1087's own two scenarios is coverable by this page as
built. `scoped-views-still-return-rows` needs a backfilled register (the gate is
shut on every instance available here) and `totals-exclude-another-administration`
needs two administrations with GL activity and a member of only one — shared
state this suite deliberately does not create. Retagging them at this page would
be a tag, not a test.

## Out of scope

- An administration **switcher**. The panel reports on the caller's active
  administration (`GET /api/administrations/context`). Adding a picker is a
  separate decision about whose money a reader may compare.
- Cross-tab (supplier × period). `openspec/specs/spend-analytics/spec.md`
  REQ-SPA-004 already routes that to OpenRegister; there is no endpoint to
  consume.
- Changing the endpoint. This change adds no PHP.
