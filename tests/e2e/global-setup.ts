/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar / localStorage to `tests/e2e/.auth/admin.json`.
 * Every spec then reuses that storage state via the `use.storageState`
 * setting in playwright.config.ts, so individual tests start from an
 * authenticated session without each one paying the login cost.
 *
 * Why a real browser login (instead of POSTing to /login directly):
 * Nextcloud's login form ships a CSRF token (`requesttoken`) plus a
 * `oc_session_passphrase` cookie that must be set in the same browser
 * context. Driving the form via Playwright sidesteps having to
 * reverse-engineer the token-rotation contract, which has shifted
 * across NC 28 / 29 / 30.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/), mirrored
 * from launchpad's journeydoc setup (the longest-running journeydoc
 * adopter).
 */

import type { FullConfig, Page } from '@playwright/test'

import { chromium, request } from '@playwright/test'
import { execSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'
import { resolveBaseURL } from './base-url.ts'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')
const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'shillinq-main.js')

/**
 * Ensure the webpack bundle exists before specs hit `/apps/shillinq/`.
 *
 * The shared `ConductionNL/.github/quality.yml` Playwright job runs
 * `npm ci` + `npx playwright install` before the spec run, but never
 * `npm run build`. On a fresh CI VM the `js/shillinq-main.js` artefact
 * doesn't exist, so the rendered page loads a 404 script tag and the
 * Vue app never mounts — every selector wait then times out.
 *
 * Skipping the build entirely on CI would require a cross-repo PR to
 * `ConductionNL/.github` adding a `npm run build` step to the shared
 * workflow; doing it here keeps the fix self-contained.
 *
 * Note: locally, the app running in the dev container is usually
 * mounted from a separate checkout, so this build only helps CI / a
 * checkout that serves its own `js/`.
 */
function ensureBundleBuilt(): void {
	if (fs.existsSync(BUNDLE_PATH)) {
		return
	}
	console.log(
		`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`,
	)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, {
			failOnStatusCode: false,
		})
		if (!res.ok()) {
			throw new Error(
				`Nextcloud status.php returned ${res.status()} at ${baseURL}. `
					+ `Make sure the docker container is running and reachable.`,
			)
		}
		const body = await res.json().catch(() => ({}))
		if (!body || body.installed !== true) {
			throw new Error(
				`Nextcloud at ${baseURL} is not installed (status.php = ${JSON.stringify(body)}).`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

/**
 * Complete Shillinq's first-time setup, and mark the getting-started
 * walkthrough as seen, on the instance under test.
 *
 * WHY THIS IS NOT OPTIONAL
 * ------------------------
 * Until `legal_country` / `legal_region` / `rgs_template` / `administration_id`
 * are all set, the app renders NOTHING but its "Set up this app" dialog — no
 * `#shillinq` root, no `<main>` content, on EVERY route. Measured 2026-08-16
 * against a freshly-installed instance: 77 of 262 executed specs failed, and
 * the dominant error was `element(s) not found` / `toBeVisible() failed`,
 * because the setup dialog was the only thing on the page. The suite was
 * describing an un-onboarded instance, not the app.
 *
 * The same is true of the walkthrough: once setup completes, a 4-step tour
 * opens over the dashboard on first visit and swallows clicks. It is
 * suppressed by writing the version the manifest declares
 * (`walkthrough.completionConfigKey`) to the user's preferences — the same
 * key `useWalkthrough()` reads.
 *
 * Driven through the setup API rather than by clicking the seven wizard tabs:
 * the wizard is itself under test elsewhere, and a fixture that depends on the
 * UI it is preparing fails for two unrelated reasons at once.
 *
 * `nl` / `municipality` is the widest choice — it is the organisation type the
 * BBV compliance specs assume, and it leaves every non-BBV surface reachable.
 * The provincie / waterschap variants scope themselves per spec.
 *
 * Idempotent: `init-administration` reports `skipped` when ADM-001 already
 * exists, and re-running `seed` is a no-op. Failures here are FATAL by design —
 * a suite that silently proceeds against an un-onboarded instance produces a
 * red run that looks like broken code.
 */
async function completeAppSetup(page: Page): Promise<void> {
	const result = await page.evaluate(async () => {
		// The requesttoken must come from THIS page — a bare fetch without it is
		// rejected, and a rejected setup call would leave the whole suite
		// describing an un-onboarded instance.
		const w = window as unknown as { OC?: { requestToken?: string } }
		const token =
			document.head.querySelector<HTMLMetaElement>('meta[name=csrf-token]')
				?.content
			|| w.OC?.requestToken
			|| ''
		const base = '/index.php/apps/shillinq/api'
		const send = async (method: string, url: string, body: unknown) => {
			const res = await fetch(url, {
				method,
				headers: { 'Content-Type': 'application/json', requesttoken: token },
				body: JSON.stringify(body ?? {}),
			})
			return { url, status: res.status }
		}

		const calls = [
			await send('POST', `${base}/setup/config`, {
				legal_country: 'nl',
				legal_region: 'municipality',
				rgs_template: 'municipality',
			}),
			await send('POST', `${base}/setup/action/init-administration`, {}),
			await send('POST', `${base}/setup/action/seed`, {}),
			await send('PUT', `${base}/preferences/walkthrough_completed_version`, {
				value: '999.0.0',
			}),
		]

		const status = await fetch(`${base}/setup/status`, {
			headers: { requesttoken: token },
		})
		const completed = status.ok
			? ((await status.json().catch(() => ({}))) as { completed?: boolean })
					.completed === true
			: false

		return { calls, completed }
	})

	const failed = result.calls.filter((c) => c.status >= 400)
	if (failed.length > 0 || result.completed !== true) {
		throw new Error(
			'[playwright globalSetup] Shillinq first-time setup did not complete: '
				+ JSON.stringify(result)
				+ '. Every spec would then be asserting against the setup dialog '
				+ 'rather than the app, so the run is stopped here instead.',
		)
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	// Never fall back to a literal — the old chain ended at the SHARED dev
	// container on :8080, so an unset environment fired repeated logins at
	// somebody else's instance. See tests/e2e/base-url.ts.
	const baseURL =
		(config.projects[0]?.use?.baseURL as string | undefined) ?? resolveBaseURL()
	const username = process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? 'admin'

	ensureBundleBuilt()
	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(AUTH_DIR, { recursive: true })

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// Hit the login form so the CSRF token + session passphrase land in
	// the browser jar.
	await page.goto('/index.php/login')
	await page.locator('input[name="user"]').fill(username)
	await page.locator('input[name="password"]').fill(password)
	await page.locator('button[type="submit"]').first().click()
	// Nextcloud bounces to a default app (dashboard / launchpad / …) on
	// success. The post-submit redirect chain can take a few seconds on a
	// heavy instance and the `#header`-only wait races with it, so wait for
	// the URL to leave /login first (the authoritative success signal),
	// then settle on the authenticated header. Both waits are forgiving so
	// a slow-but-successful login is not reported as a credential failure.
	try {
		await page.waitForURL((url) => !/\/login(\?|$|\/)/.test(url.toString()), {
			timeout: 30_000,
		})
	} catch {
		// fall through to the header wait + explicit error below.
	}
	await page
		.waitForSelector('#header, header.header', { timeout: 30_000 })
		.catch(() => {})
	// Catch wrong-credentials early so the failure message is clear.
	const currentUrl = page.url()
	if (/\/login(\?|$|\/)/.test(currentUrl)) {
		throw new Error(
			`Login appears to have failed — still on ${currentUrl}. `
				+ `Check NC_ADMIN_USER / NC_ADMIN_PASS (defaults admin/admin).`,
		)
	}

	// Onboard the app before any spec runs. Must happen while this page is
	// authenticated — the setup endpoints are admin-gated and CSRF-protected.
	await completeAppSetup(page)

	// Persist the storage state so individual specs reuse the session.
	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
