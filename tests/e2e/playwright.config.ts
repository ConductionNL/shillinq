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
 * lookup hit this file, which declares only the regression projects (below:
 * `chromium` plus the isolated `setup-wizard-isolated`). The root config is
 * untouched and stays the entry point for local runs.
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
 * Repeated verbatim on both projects below — see the header.
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

/**
 * `setup-wizard-english.spec.ts` resets shillinq's setup app-config
 * SERVER-SIDE (`resetSetupStateServerSide()`, see that file's own header) so
 * ADR-042's wizard gates the shell again. The Nextcloud instance under test
 * is ONE shared backend across every worker, so for the whole window between
 * that reset and the file's `afterAll` restore, every OTHER spec file's
 * app-config reads see an unconfigured instance and the "Set up this app"
 * dialog blocks their page too — not a theory: this is what actually
 * produced `provincies-bbv-variant.spec.ts` and
 * `spec-coverage/belastingen.spec.ts`'s intermittent `cn-index-page` /
 * "tables=0 lists=0 actions=0" failures on `development` (run 32329757674).
 *
 * ⚠️ WHY THIS PROJECT IS ENV-GATED, NOT JUST SEPARATED
 * -------------------------------------------------------
 * A separate `setup-wizard-isolated` project (below) alone does NOT stop the
 * contamination in the run CI actually performs: the shared workflow invokes
 * `npx playwright test --config="$CONFIG"` with NO `--project` flag (see
 * `ConductionNL/.github/.github/workflows/quality.yml`'s `playwright` job —
 * confirmed by reading it via `gh api`), so Playwright collects EVERY
 * declared project and dispatches them together. Verified against the
 * installed `playwright` package's own runner source
 * (`createPhasesTask()`/`createRunTestsTask()` in
 * `node_modules/playwright/lib/runner/index.js`): projects with no
 * `dependencies` relationship land in the SAME phase and share the SAME
 * worker pool — a separate project alone is only a labeling change, not
 * isolation, for a config-less `npx playwright test` invocation.
 *
 * `dependencies` (Playwright's only cross-project SEQUENCING primitive)
 * was considered and rejected: `createRunTestsTask()` only queues a
 * dependent project's tests when `!project.deps.some(p =>
 * !successfulProjects.has(p))` — i.e. ANY failure in a dependency SILENTLY
 * SKIPS every test in whatever depends on it (the documented
 * auth-`setup`-project pattern; never designed for "just don't overlap").
 * This suite routinely has unrelated failures elsewhere, so wiring
 * dependencies here would trade one contamination hazard for a worse,
 * silent one: `chromium` depending on this project would blank out up to
 * 50 files' worth of results the moment THIS file fails; the reverse would
 * silently stop running THIS file the moment anything ELSE in the suite
 * fails. Either is the "a workflow that never started reads as passing"
 * defect this fleet has been burned by before, not a fix.
 *
 * So, until the real fix lands (see below), this project is GATED BEHIND
 * `RUN_SETUP_WIZARD_SPEC=1` and therefore absent from the default CI run
 * entirely — not run-and-racing, not silently overlapping, just not
 * collected. Default CI (`RUN_SETUP_WIZARD_SPEC` unset) sees `chromium`'s
 * 273 tests across 53 files with ZERO contamination window, because there
 * is nothing else in the run to contaminate anything with.
 *
 * ⚠️ THIS IS A FULLY WORKING, CORRECT SPEC — NOT ABANDONED OR BROKEN.
 * Every assertion in `setup-wizard-english.spec.ts` was fixed and is
 * runnable (strict-mode locator scoping, `NcSelect`'s `appendToBody`
 * teleport, its split-label rendering, the real `waitForActionComplete`
 * signal, and both the test's and `afterAll`'s timeout budgets — see that
 * file's own comments). A future reader must not read this gate as
 * "disabled because broken" or add `test.skip`: run it explicitly —
 *
 *     RUN_SETUP_WIZARD_SPEC=1 npx playwright test --project=setup-wizard-isolated
 *
 * — locally, or via a future dedicated CI job once the cross-repo split
 * below exists.
 *
 * THE REAL FIX (cross-repo, not this file): genuine non-overlap needs two
 * SEPARATE `npx playwright test --project=X` process invocations as two
 * steps, neither gating the other's execution — that has to be added to
 * the SHARED `ConductionNL/.github/.github/workflows/quality.yml`
 * `playwright` job, which affects all 18 fleet apps on this reusable
 * workflow and belongs in its own PR against `.github`. Concrete proposal:
 * `openspec/changes/setup-wizard-english/notes-github-playwright-split.md`.
 * Once that lands, delete this env-gate and the `dependencies` discussion
 * above becomes moot — the projects run as two independently-reported CI
 * steps instead.
 */
const SETUP_WIZARD_ISOLATED_SPEC = '**/setup-wizard-english.spec.ts'

/**
 * Set to run `setup-wizard-isolated` at all — see the block above for why
 * it is opt-in rather than always-collected. Any non-empty value other than
 * exactly `'1'` is treated as unset (fail toward NOT adding the contaminating
 * project by accident from a typo'd truthy-ish string).
 */
const RUN_SETUP_WIZARD_SPEC = process.env.RUN_SETUP_WIZARD_SPEC === '1'

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
		[
			'html',
			{
				open: 'never',
				outputFolder: path.resolve(__dirname, 'playwright-report'),
			},
		],
		['list'],
	],
	outputDir: path.resolve(__dirname, 'test-results'),

	use: {
		// Single source of truth — see tests/e2e/base-url.ts.
		baseURL: resolveBaseURL(),
		// Written by global-setup.ts after the admin login.
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		// ⚠️ MUST NOT BE `on-first-retry` — THIS CONFIG SETS `retries: 0`.
		//
		// `on-first-retry` writes a trace only when a test is RETRIED. With
		// `retries: 0` there is never a first retry, so the two settings
		// together mean NO TRACE IS EVER WRITTEN. That is not a theory:
		// measured on run 31052498432, which had 53 failures and attached
		// **0** traces, while attaching 53 screenshots (the screenshots are
		// the positive control — artifact attachment itself works fine).
		//
		// The pairing is quietly self-defeating: `retries: 0` is the right
		// call for honesty (a retry only ever converts red to green), and
		// `on-first-retry` is Playwright's sensible DEFAULT-ish idiom — but
		// each silently disables the other's value. Every failure was being
		// diagnosed blind from a single screenshot.
		//
		// `retain-on-failure` writes a full trace for each failing test and
		// discards it for passing ones. It costs artifact size only on red
		// runs, which is exactly when it is worth paying: a sibling repo's
		// traces artifact went 15.5 MB -> 195 MB on this switch, and that is
		// what made its failures diagnosable at all.
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			// NOT a duplicate: a project-level testIgnore REPLACES the
			// top-level one rather than merging with it. Also excludes
			// `setup-wizard-english.spec.ts` unconditionally — see
			// SETUP_WIZARD_ISOLATED_SPEC above — regardless of whether
			// RUN_SETUP_WIZARD_SPEC is set, so that file NEVER falls back
			// into this project's shared pool (the one thing that must not
			// happen — it's the whole reason it has its own project).
			testIgnore: [...CI_TEST_IGNORE, SETUP_WIZARD_ISOLATED_SPEC],
			use: { ...devices['Desktop Chrome'] },
		},
		// Opt-in only — see RUN_SETUP_WIZARD_SPEC / SETUP_WIZARD_ISOLATED_SPEC
		// above for why this project is absent from the array entirely
		// (not merely empty of tests) unless explicitly requested. Default
		// CI runs never see this project declared at all.
		...(RUN_SETUP_WIZARD_SPEC
			? [
					{
						// See SETUP_WIZARD_ISOLATED_SPEC above for what this
						// isolates and, just as importantly, what it does NOT
						// guarantee without the cross-repo workflow split.
						name: 'setup-wizard-isolated',
						testMatch: SETUP_WIZARD_ISOLATED_SPEC,
						// Moot with only one file in this project (nothing else
						// to run concurrently against it here) — kept because it
						// states the actual intent per Playwright's own
						// documented use for `project.workers`: "when all tests
						// from a project share a single resource ... and
						// therefore cannot be executed in parallel."
						workers: 1,
						use: { ...devices['Desktop Chrome'] },
					},
				]
			: []),
	],
})
