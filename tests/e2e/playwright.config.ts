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
 * Note what is missing: `--project`. So whichever config it picks, EVERY
 * project in it runs. The root `playwright.config.ts` declares three:
 *
 *   chromium     — the regression suite. This is the one CI wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030), which re-shoots
 *                  every tutorial + bookings screenshot. It has its own
 *                  dedicated `Journeydoc Capture` job that runs it explicitly
 *                  with `--project docs-capture`.
 *   visual       — pixel-diff baselines. Its own header in the root config
 *                  says the PNGs are host-font/GPU specific and that "a CI
 *                  Linux runner will not byte-match a dev-container baseline;
 *                  the visual project must regenerate its baselines in-CI
 *                  before it can gate."
 *
 * Letting the root config be picked therefore runs two projects that are
 * documented as unable to pass on a CI runner, on top of the one that can.
 * Rather than delete or weaken them, `playwright-test-path: tests/e2e` in
 * .github/workflows/code-quality.yml makes the workflow's FIRST lookup hit
 * this file, which declares only the regression project. The root config is
 * untouched and stays the entry point for local runs, `npm run test:e2e`,
 * `npm run test:e2e:docs` and `--project visual`.
 *
 * The report/output paths also differ deliberately. The workflow uploads
 * `server/apps/<app>/playwright-report/` and `server/apps/<app>/test-results/`,
 * so on CI the artifacts must land at the APP ROOT, not under `tests/e2e/`
 * where the root config writes them. With the root config's paths the
 * "Upload Playwright report" step matched nothing and silently uploaded an
 * empty artifact (`if-no-files-found: ignore`) — a failing run with no report
 * to read.
 *
 * Every path here is absolute (`path.resolve(__dirname, …)`). Playwright
 * resolves `testDir` relative to the CONFIG directory but `globalSetup` and
 * `use.storageState` relative to the CWD, and the workflow's CWD is the app
 * root while this config lives two levels down — so a relative literal would
 * silently resolve against the wrong base and hand every spec an anonymous
 * session.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { resolveBaseURL } from './base-url'

const APP_ROOT = path.resolve(__dirname, '..', '..')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	// CI runners are slower than a dev container and the app boots a full
	// Nextcloud SPA per navigation; the root config's 30s/10s were tuned for
	// a warm local instance.
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: path.join(APP_ROOT, 'playwright-report') }],
		['list'],
	],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		// No localhost:8080 fallback — see tests/e2e/base-url.ts. CI exports
		// the target as BASE_URL, which that resolver honours.
		baseURL: resolveBaseURL(),
		storageState: path.resolve(__dirname, '.auth', 'admin.json'),
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			// Same exclusions the root config's `chromium` project carries:
			// the two screenshot-capture specs belong to `docs-capture` and the
			// pixel baselines to `visual`.
			testIgnore: [
				'**/docs-screenshots.spec.ts',
				'**/bookings-screenshots.spec.ts',
				'**/visual/**',
			],
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
