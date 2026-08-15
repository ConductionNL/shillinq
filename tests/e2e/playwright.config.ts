/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * `enable-playwright` defaults to FALSE in the shared workflow and was never
 * set for this repo, so the "E2E Tests (Playwright)" job has reported
 * **skipped** on every run shillinq has ever produced — while the tree ships a
 * root `playwright.config.ts` and 53 spec files under `tests/e2e/`. A skipped
 * job is not a pass; it is the absence of a measurement, and it looks exactly
 * like a green one in the PR rollup.
 *
 * WHY A SECOND CONFIG RATHER THAN JUST FLIPPING THE FLAG
 * -----------------------------------------------------
 * The shared workflow runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. So whichever config it picks, EVERY
 * project in it runs. The root config declares three:
 *
 *   chromium     — the regression suite. This is the one CI wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030); re-shoots every
 *                  tutorial screenshot, and has its own dedicated job.
 *   visual       — pixel-diff baselines, which are host-font/GPU specific and
 *                  will not byte-match on a CI Linux runner.
 *
 * Letting the root config be picked would therefore run two projects that
 * cannot pass on a runner, on top of the one that can. `playwright-test-path:
 * tests/e2e` in the caller makes the workflow's FIRST lookup hit this file,
 * which declares only the regression project. The root config is untouched and
 * stays the entry point for local runs and `npm run test:e2e:docs`.
 *
 * The report/output paths deliberately point at the APP ROOT: the workflow
 * uploads `<app>/playwright-report/` and `<app>/test-results/`, so with the
 * root config's relative paths the upload step would match nothing and
 * silently produce an empty artifact — a failing run with no report to read.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { BASE_URL } from './base-url'

const APP_ROOT = path.resolve(__dirname, '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),

	// The shared workflow caps this job at `timeout-minutes: 45`, and a job the
	// runner kills at its cap reports **cancelled** — which is NO VERDICT: not a
	// pass, not a failure, no information. `globalTimeout` below sits inside
	// that cap so Playwright stops first and exits non-zero WITH a tally.
	//
	// This suite has never run in CI, so there is no measured slowest-pass to
	// size against. The values start at decidesk's proven settings and should be
	// re-derived from the first green run rather than left as a guess:
	// `retries: 0` because a retry only ever converts red to green, and a first
	// run needs to show what is actually flaky.
	timeout: 20_000,
	expect: { timeout: 10_000 },
	globalTimeout: 38 * 60_000,
	fullyParallel: false,
	retries: 0,
	workers: 1,

	// `github` is what makes a killed run legible: it emits a ::error::
	// annotation per failure AS IT HAPPENS. `list` alone prints failure bodies
	// only in an end-of-run summary, so a run cut off at the cap shows a column
	// of ✘ marks and not one error message.
	reporter: process.env.CI
		? [
				['github'],
				['list'],
				[
					'html',
					{
						open: 'never',
						outputFolder: path.join(APP_ROOT, 'playwright-report'),
					},
				],
			]
		: [
				[
					'html',
					{
						open: 'never',
						outputFolder: path.join(APP_ROOT, 'playwright-report'),
					},
				],
				['list'],
			],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		// Single source of truth — tests/e2e/base-url.ts. The specs import the
		// same resolver, so `page.goto()` (which uses this baseURL) and the
		// specs' own `page.request` calls cannot address different hosts.
		baseURL: BASE_URL,
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
	},

	projects: [
		{
			name: 'chromium',
			// Same exclusions the root config's regression project uses: the
			// screenshot-capture specs belong to the Journeydoc job and the
			// pixel baselines cannot match on a runner.
			testIgnore: [
				'**/docs-screenshots.spec.ts',
				'**/bookings-screenshots.spec.ts',
				'**/visual/**',
			],
			use: {
				...devices['Desktop Chrome'],
			},
		},
	],
})
