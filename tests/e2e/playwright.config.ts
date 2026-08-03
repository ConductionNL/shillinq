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
 * the 4 excluded below). Run one-at-a-time on a `php -S` instance that would not
 * finish inside the cap.
 *
 * So files run in PARALLEL (`workers: 5`), but `fullyParallel` stays FALSE:
 * tests WITHIN a file still run in declaration order on one worker. That is the
 * conservative half of the lever, and it is deliberate — the suite is not
 * "safe by construction":
 *
 *   - 9 of the 10 `spec-coverage/*.spec.ts` files already declare
 *     `test.describe.configure({ mode: 'serial' })`, and `order-primitive.spec.ts`
 *     declares `mode: 'serial'` plus its own timeout/retries. Those authors
 *     recorded an intra-file ordering dependency; `fullyParallel: true` would
 *     not break them (an explicit `mode` wins) but it WOULD flip every other
 *     file's intra-file ordering, which nothing here has ever exercised.
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
	timeout: 60_000,
	expect: { timeout: 15_000 },
	// See PARALLELISM in the header: files in parallel, tests within a file
	// serial. Files that need more than that already say so themselves with
	// `test.describe.configure({ mode: 'serial' })`.
	fullyParallel: false,
	workers: 5,
	retries: process.env.CI ? 1 : 0,
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
