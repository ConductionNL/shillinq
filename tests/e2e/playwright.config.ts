/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. Whichever config it picks, EVERY project
 * declared in it runs. The ROOT `playwright.config.ts` declares three:
 *
 *   chromium     — the regression suite. This is the one CI wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030). Re-shoots every
 *                  tutorial screenshot into `docs/static/screenshots/…`, driven
 *                  deliberately by `--project docs-capture`.
 *   visual       — pixel-diff baselines. `tests/e2e/visual/README.md` states the
 *                  baselines are host-font/GPU specific, so a CI Linux runner
 *                  cannot byte-match a dev-container baseline; the project is
 *                  explicitly opt-in / non-gating until it can rebaseline in CI.
 *
 * Letting the root config be picked would therefore make every PR re-shoot the
 * documentation screenshots AND fail on visual baselines that were never meant
 * to be compared on a runner. Rather than delete or weaken either project,
 * `playwright-test-path: tests/e2e` in the caller makes the workflow's FIRST
 * lookup hit this file, which declares only the regression project. The root
 * config is untouched and stays the entry point for local runs.
 *
 * ⚠️ `testIgnore` HAS TO BE REPEATED AT PROJECT LEVEL.
 * A project-level `testIgnore` REPLACES the top-level one, it does not merge
 * with it. The root config gets away with splitting the list across the two
 * levels only because Playwright applies the top-level filter to the file list
 * before the project filter. Here the full list is stated in BOTH places, so a
 * future reader cannot delete the top-level entry and silently start collecting
 * `visual/shillinq.visual.spec.ts` (which will fail on baselines) or the helper
 * modules (which export helpers, not tests — Playwright errors with "no tests
 * found in file").
 *
 * PARALLELISM
 * -----------
 * `timeout-minutes: 45` on the shared job is a hard cap, and a CANCELLED job is
 * no verdict at all — it is indistinguishable from a job that was never enabled.
 * Shillinq ships 51 CI spec files (53 `*.spec.ts` + 2 legacy `*.spec.js`, minus
 * the 4 excluded below). MEASURED at `workers: 1`: run 31018434870 collected
 * 271 tests, was still going 42 minutes later, and was killed by the job cap
 * with 135 result lines and no tally. At `workers: 5` the identical suite
 * finished in 23.7m (run 30881746678) and 25.7m (run 30879466171). One worker
 * does not fit the cap; five does, with room.
 *
 * So files run in PARALLEL (`workers: 5`), but `fullyParallel` stays FALSE:
 * tests WITHIN a file still run in declaration order on one worker. That is the
 * conservative half of the lever, and it is deliberate — the suite is not
 * "safe by construction":
 *
 *   - `fullyParallel: false` is what keeps a file's tests in declaration
 *     order on ONE worker, and that — not any per-file declaration — is the
 *     property being relied on. `fullyParallel: true` would flip every file's
 *     intra-file ordering, which nothing here has ever exercised.
 *
 *     ⚠️ Do not re-read the `spec-coverage/*.spec.ts` files as evidence for
 *     this. They USED to open with `test.describe.configure({ mode: 'serial' })`
 *     and that was cited here as proof the suite was order-safe. It proved no
 *     such thing: `serial` does not order anything `fullyParallel: false` had
 *     not already ordered — it only ABORTS the rest of a block on the first
 *     failure. Measured on run 31040595126, that abort was the sole cause of
 *     all 45 "did not run" results, from just three failing tests. The
 *     declarations are gone; see the header of `spec-coverage/_helpers.ts`.
 *     `order-primitive.spec.ts` keeps its own `mode: 'serial'` because it
 *     genuinely is order-dependent.
 *   - The write-performing specs are cross-file safe: `workflows/_fixtures.ts`
 *     stamps every seeded object with a per-run `UNIQUE_PREFIX`
 *     (`e2efin-<base36 time>-<random>`) and deletes what it created in
 *     `afterAll`, so two workers seeding `Account` objects concurrently cannot
 *     see each other's rows.
 *   - The only absolute-count assertions are on FIXED data, not on rows a
 *     sibling worker could add: `workflows/external-adapters-admin.spec.ts`
 *     counts the compiled-in adapter families, and `invoice-quick-draft` /
 *     `recurring-invoicing` count draft lines inside a modal they opened
 *     themselves. `list-views-cndatatable.spec.ts` compares a row count to its
 *     OWN before-count within one test, on one worker.
 *
 * ARTIFACT PATHS
 * --------------
 * Report and traces stay under `tests/e2e/…`, which `tests/e2e/.gitignore`
 * already ignores. The shared workflow's upload steps list
 * `server/apps/<app>/tests/e2e/playwright-report/` and
 * `.../tests/e2e/test-results/` alongside the app-root paths, so both are
 * downloadable from the run.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { resolveBaseURL } from './base-url'

/**
 * Everything under `tests/e2e` that is NOT part of the CI regression suite.
 *
 * Repeated verbatim on the project below — see the header.
 */
const CI_TEST_IGNORE = [
	// Support modules. They export helpers, not tests.
	'**/global-setup.ts',
	'**/base-url.ts',
	'**/_helpers.ts',
	'**/_fixtures.ts',
	'**/_visual-helpers.ts',
	'**/fixtures/**',
	// Pixel-diff baselines cannot byte-match on a runner — see visual/README.md.
	'**/visual/**',
	// Documentation capture (ADR-030). Opt-in via the root config's
	// `docs-capture` project; re-shooting docs screenshots is not a regression.
	'**/docs-screenshots.spec.ts',
	'**/bookings-screenshots.spec.ts',
]

export default defineConfig({
	testDir: __dirname,
	testIgnore: CI_TEST_IGNORE,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),

	// PER-TEST TIMEOUT — 60s, and this number is MEASURED, not chosen.
	//
	// The rule applied here is: a per-test timeout must be a small multiple of
	// the slowest test that actually PASSES, because failures sit on the cap
	// while passes finish fast, so the cap is a pure cost multiplier on every
	// failure and must be as low as the slowest genuine pass allows.
	//
	// Slowest PASSING test, measured from the `list` reporter's own durations:
	//   • run 31018434870 (workers: 1) — 18.7s
	//       `docs-screenshots`-free regression suite, uncontended.
	//   • run 30879466171 (workers: 5) — 26.8s
	//   • run 30881746678 (workers: 5) — 35.6s
	//       `bookkeeping-ifrs15-revenue.spec.ts:95` "Contract Cost Assets +
	//       Performance Obligations routes resolve" — four sequential
	//       `gotoPage()` deep links, each of which reloads the whole SPA.
	//
	// 60s was only 1.7× the slowest pass observed under 5-worker contention.
	// Cutting to 45s would have been 1.26×, inside the run-to-run spread
	// already visible between two 5-worker runs of the same tree (26.8s →
	// 35.6s) — it would manufacture red, which is as dishonest as
	// manufacturing green. So the budget was recovered from `retries` and
	// `workers` instead, where the evidence says the waste actually is.
	//
	// RE-MEASURED AT `workers: 4` (run 31047984852) — and the answer was NOT
	// the one that would have let this number come down:
	//
	//   workers 1 (run 31018434870): slowest pass 18.7s
	//   workers 5 (run 31045795069): slowest pass 31.3s, mean 9.6s
	//   workers 4 (run 31047984852): slowest pass 31.3s, mean 8.6s
	//
	// Dropping to 4 workers improved the MEAN (9.6s -> 8.6s over a larger
	// passing set) but left the TAIL where it was. 45s would therefore be
	// 1.44x the slowest pass, with 13.7s of headroom — and the slowest pass on
	// this same tree has already been observed to swing 22.9s -> 31.3s between
	// runs, an 8.4s spread. One more swing of that size against a 45s cap
	// manufactures red.
	//
	// So 60s stays: 1.9x the measured tail. That is the honest read of the
	// evidence, not a preference — cutting it here to look diligent would be
	// manufacturing failures, which is the same sin as manufacturing passes.
	// Re-open this if the tail moves: the specs at the top of that list are
	// multi-`gotoPage()` tests (each deep link reboots the whole SPA), so the
	// real fix is fewer full-page navigations per test, after which the
	// timeout can drop on evidence. It is never raised.
	timeout: 60_000,
	expect: { timeout: 15_000 },

	// GLOBAL TIMEOUT — 38 minutes, inside the shared job's `timeout-minutes: 45`.
	//
	// This is the single most important line in the file, and it is here
	// because of what run 31018434870 did: it ran 45m22s and the job's
	// conclusion was `cancelled`. A cancelled job is NOT a red run — it is NO
	// VERDICT AT ALL. Playwright was SIGKILLed mid-suite, so it never wrote a
	// summary, never finished the HTML report, and the "Upload Playwright
	// report" step therefore had nothing to upload. The run produced 135 result
	// lines in the log and not one number anybody could act on.
	//
	// `globalTimeout` makes Playwright stop ITSELF first. It then exits with a
	// real failure, prints its own tally (`N passed / N failed / N did not
	// run`), and leaves a complete report on disk for the upload step. A suite
	// that overruns still fails — it just fails LEGIBLY.
	//
	// 38m leaves ~7m of headroom under the 45m job cap for the report write and
	// the artifact upload, which run outside Playwright.
	globalTimeout: 38 * 60 * 1000,
	// See PARALLELISM in the header: files in parallel, tests within a file
	// serial. Files that need more than that already say so themselves with
	// `test.describe.configure({ mode: 'serial' })`.
	fullyParallel: false,

	// WORKERS — 4, deliberately BELOW the 5 that first fitted the cap.
	//
	// More workers is not monotonically better here, and the reason matters:
	// the GitHub runner has 4 cores and is ALSO hosting the `php -S` instance
	// under test (8 PHP workers). Past four, Playwright is competing with the
	// server it is driving, so each test gets slower even though more run at
	// once. Measured on a sibling repo's suite on the same runner class:
	// 6 workers -> 29.2m wall, mean 25.0s/test; 4 workers -> 24.2m wall, mean
	// 13.8s/test. Fewer workers was faster in BOTH wall-clock and per-test.
	//
	// ⚠️ AND CONTENTION CORRUPTS THE TIMEOUT EVIDENCE, WHICH IS THE REAL TRAP.
	// The "slowest passing test" is the number a per-test timeout is supposed
	// to be derived from — but it INFLATES with worker count. On this suite it
	// read 18.7s at 1 worker and 35.6s at 5. So a timeout justified by a
	// measurement taken under too many workers is justifying itself: the
	// contention you added is what makes the long timeout look necessary. Set
	// the worker count first, re-measure, and only then touch the timeout.
	// That ordering is why `timeout` above is still 60_000 and flagged as
	// pending re-measurement rather than cut on the 5-worker number.
	workers: 4,
	// NO RETRIES, DELIBERATELY.
	//
	// Two reasons, both measured on run 30881746678:
	//
	// 1. COST. A failing test here almost always fails by exhausting the 60s
	//    timeout waiting for a selector, not by asserting quickly. `retries: 1`
	//    therefore pays that 60s TWICE per failure. That run had 55 failures,
	//    i.e. ~55 minutes of pure retry wall-clock across 5 workers — most of
	//    its 23.7m runtime, against a 45-minute job cap where exhaustion is
	//    reported as `cancelled`, a conclusion that is no verdict at all.
	// 2. HONESTY. A retry turns a genuine flake into a `flaky` result, and
	//    Playwright still exits 0 on flaky. That run carried 3 of them, so the
	//    job's own conclusion could not distinguish "this suite is stable" from
	//    "this suite is unstable and we retried until it wasn't". A flake is a
	//    defect in the test or the product; it should be fixed at the cause,
	//    which is what happened to all three.
	retries: 0,
	reporter: [
		['html', { open: 'never', outputFolder: path.resolve(__dirname, 'playwright-report') }],
		['list'],
	],
	outputDir: path.resolve(__dirname, 'test-results'),

	use: {
		// Single source of truth — see tests/e2e/base-url.ts.
		baseURL: resolveBaseURL(),
		// Written by global-setup.ts after the admin login.
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			// NOT a duplicate: a project-level testIgnore REPLACES the
			// top-level one rather than merging with it.
			testIgnore: CI_TEST_IGNORE,
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
