# Tasks: spend-analytics-ui

## 0. Pre-flight — derive the contract from the code, not from the docs

- [x] Read `lib/Controller/SpendAnalyticsController.php` and
      `lib/Service/SpendAnalyticsService.php` plus their two PHPUnit suites and
      write down the real request/response shape.
      **RESULT — request:** `GET /apps/shillinq/api/analytics/spend` with two
      REQUIRED query parameters, `administration_id` (matched against
      `/^[A-Za-z0-9_.\-]{1,64}$/`) and `dimension` (one of `supplier`,
      `category`, `costCentre`, `period` — the literal `DIMENSIONS` constant).
      **Response 200:** `{ dimension, label, groups: [{ key, amount }], total,
      backend }`, where `label` is server-translated, `key` is the raw group
      scalar (`vendorId` / `accountNumber` / `costCenterCode` / `periodId`, and
      may be `null`), `amount` and `total` are floats, and `backend` defaults to
      `"unknown"`. **Errors, all `{ "error": "<message>" }`:** 400 on a
      missing/malformed `administration_id` or an unknown `dimension`, 401 when
      anonymous, 404 (never 403) when the caller holds no membership, 500 on any
      `\Throwable` out of the service — including REQ-GLS-003's deliberate raise.
- [x] Confirm the endpoint has no consumer today.
      **RESULT: `grep -rn "analytics/spend" src/` returned nothing at the base
      commit and no `src/manifest.d/*.json` declared it.**
- [x] Read `#1087` (`feat/glline-administration-scope`, in CI) rather than
      guessing at the completeness gate, since it is not on this branch's base.
      **RESULT: `SpendAnalyticsService::assertGlScopeIsEnforceable()` compares
      `GlLineAdministrationBackfillMigrator::GATE_CONFIG_KEY` against
      `GATE_CONTRACT_VERSION` and throws `RuntimeException` when they differ; the
      controller catches it and returns 500. The gate is a version, not a
      boolean, so a new `GLLine` writer can invalidate every stored proof.**

## 1. Navigation — spend no top-level slot

- [x] Nest the page as a leaf under the existing `ReportingCompliance` cluster by
      merging into that menu id, following `budget-grid-view.json`'s pattern.
- [x] Re-measure the effective manifest before and after with
      `tests/validate-nav-reachability.js`.
      **RESULT: 51 top-level entries before and after (9 nav clusters + 42
      settings-section); pages 571 → 572; `ReportingCompliance` children 65 → 66.
      No new orphans.**

## 2. The page

- [x] Declare `SpendAnalytics` as `type: "dashboard"` — not `type: "custom"`,
      which gate-69 rule (c) ratchets — in a new
      `src/manifest.d/spend-analytics-ui.json`, using `config.widgets[]` +
      `config.layout[]` (page-level `widgets[]` in the `body` slot is gate-69
      rule (a)'s failure, and is not used here).
- [x] Render through one custom `kind: "widget"` in the page's
      `widget-spend-analysis` slot — the `CashflowChartWidget` precedent — with a
      `_note` and a reason-bearing `@custom-widget-ratchet exclude`, because no
      built-in dashboard widget can distinguish "unavailable" from "no rows"
      (`CnChartWidget` discards `ep.error`; `CnStatWidget` renders a bare em dash
      behind a `title` tooltip with no test id).
- [x] Give every state a `data-testid` carrying the dimension key, so no e2e
      locator has to match a label. (Labels are unsafe here twice over: the
      instance renders Dutch, and `reportViews.js` already ships a card whose
      label matched another feature's element and reported a false PASS.)

## 3. Honesty of the failure path

- [x] Keep four distinct render states per view — `loading` / `error` / `empty` /
      `rows` — and make `totalFor()` return `null`, not `0`, for any view that did
      not answer, so no template branch can reach a figure from a failed request.
- [x] Render the unavailable state with the server's own message plus, for the
      three GL-backed views, the reason the gate is shut.

## 4. Tests

- [x] `tests/vitest/spendAnalyticsPanel.spec.js` — pure `methods`/`computed`
      assertions over fixed payloads, including the negative halves. 14 tests.
- [x] `tests/e2e/spend-analytics.spec.ts` — four Playwright scenarios, with the
      endpoint stubbed via `page.route` so the gate-shut case is deterministic
      rather than dependent on whether this instance's backfill has run.
- [x] Prove each new test can fail: break it, watch it go red, restore. Three
      plants, each restored: `totalFor()` returning `0` instead of `null` (3
      red), `stateFor()` losing its `error` branch (2 red), `load()` losing its
      empty-administration guard (2 red).

### Where the scenarios live, and why

The delta targets the EXISTING `spend-analytics` capability
(`specs/spend-analytics/spec.md` inside this change) rather than inventing a
`spend-analytics-ui` capability, because "this endpoint has a UI consumer" is a
durable fact about the endpoint, not about the change — and this repo already
has twelve changes whose delta targets a differently-named capability.

The same three requirements are ALSO written into
`openspec/specs/spend-analytics/spec.md` now, rather than at archive time.
Gate-19 reads `openspec/specs/*/spec.md` and nothing else: with the delta only
in `openspec/changes/`, the gate answered

    [gate-19] e2e-coverage: EMPTY SCOPE — … NO spec file was touched …
    This is not a pass.

which is a SKIP. Four Playwright tests that no gate can see are four tests one
refactor away from being deleted as unused. With the requirements in the
canonical spec the same run answers `PASS — 76 reference(s) in e2e suite`, and
renaming one scenario heading makes it FAIL — verified both ways.

## 5. Verify

- [x] `node tests/validate-manifest.js`, `validate-manifest-shell.js`,
      `validate-nav-reachability.js`, `validate-registers.js` — all exit 0.
- [x] `npm run test:unit` (251 passed), prettier + eslint clean on every changed
      file (`--max-warnings=0`).
- [x] `check:manifest-budget` — required a documented re-measure; see the note
      added to `tests/check-manifest-budget.js`. The base had 649 B of headroom
      left, so this was not trimmable.
- [x] gate-19 e2e-coverage PASS, gate-16 spec-coverage 0 findings, gate-52
      custom-widget-ratchet 0 findings (1 ratchet-excluded), gate-69
      page-type-discipline custom pages base=53 head=53 delta=+0.
- [ ] Playwright against a deployment that actually serves this branch. NOT
      DONE, and not doable from here: the dev box bind-mounts
      `apps-extra/shillinq` (branch `development`, bundle built 2026-08-21
      13:24), which contains 0 occurrences of `SpendAnalyticsPanel` and 0 of
      `reporting-compliance/spend-analytics`. All four tests fail there with
      `Received: "/apps/shillinq"` — the SPA catch-all redirect for a route the
      served bundle does not declare. An unmodified control test
      (`reporting-compliance-overview.spec.ts`) passes on the same box, so the
      harness is sound and the red is the stale bundle, not this page. The page
      DOES compile: a production webpack build of this worktree emits it into
      `js/shillinq-main.js`.
