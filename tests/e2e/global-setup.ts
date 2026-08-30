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

import { chromium, request, type FullConfig, type Page } from '@playwright/test'
import { resolveBaseURL } from './base-url'
import { execSync } from 'child_process'
import * as path from 'path'
import * as fs from 'fs'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')
const APP_ROOT = path.resolve(__dirname, '..', '..')
const BUNDLE_PATH = path.join(APP_ROOT, 'js', 'shillinq-main.js')
const INFO_XML = path.join(APP_ROOT, 'appinfo', 'info.xml')

/**
 * localStorage key `CnAppRoot` reads the walkthrough's last-seen version from.
 * Namespaced by app id — see `walkthroughSeenVersion()` in
 * `@conduction/nextcloud-vue/…/CnAppRoot`.
 */
const WALKTHROUGH_SEEN_KEY = 'cn-walkthrough-seen:shillinq'

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
	// SIZE FLOOR, not `existsSync`. A truncated or half-written bundle is still
	// a file, so a bare existence check returns early and the suite runs against
	// a page whose script tag serves 0 bytes — the exact control condition run
	// 30858387599 used to manufacture false PASSES (with no router there is no
	// redirect, so URL assertions "succeed"). A healthy build measures ~12.2 MB
	// (run 30881358951 logged 12242717 bytes); 1 MB is a floor no real build can
	// fall under and no broken one can reach.
	const MIN_BUNDLE_BYTES = 1_000_000
	if (
		fs.existsSync(BUNDLE_PATH)
		&& fs.statSync(BUNDLE_PATH).size >= MIN_BUNDLE_BYTES
	) {
		return
	}
	if (fs.existsSync(BUNDLE_PATH)) {
		// eslint-disable-next-line no-console
		console.log(
			`[playwright globalSetup] bundle at ${BUNDLE_PATH} is only `
				+ `${fs.statSync(BUNDLE_PATH).size} bytes (floor ${MIN_BUNDLE_BYTES}); rebuilding.`,
		)
	}
	// eslint-disable-next-line no-console
	console.log(
		`[playwright globalSetup] bundle missing at ${BUNDLE_PATH}; running 'npm run build' once…`,
	)
	execSync('npm run build', { cwd: APP_ROOT, stdio: 'inherit' })
}

/** Read `<version>` out of `appinfo/info.xml` — the app version the SPA reports. */
function readAppVersion(): string {
	const xml = fs.readFileSync(INFO_XML, 'utf8')
	const m = xml.match(/<version>\s*([^<\s]+)\s*<\/version>/)
	if (!m) {
		throw new Error(`Could not read <version> from ${INFO_XML}`)
	}
	return m[1]
}

/**
 * Mark the ADR-043 product walkthrough as already seen for this browser profile.
 *
 * WHY THIS IS SEEDING, NOT SUPPRESSION
 * ------------------------------------
 * `src/manifest.json` declares `walkthrough.enabled: true` with a single tour,
 * `shillinq:getting-started`, whose `trigger` is `first-visit`. `CnAppRoot`
 * mounts `CnWalkthrough` for it, and the tour renders
 *
 *     <div role="dialog" aria-modal="true" class="cn-walkthrough"
 *          aria-label="Welcome to Shillinq">
 *       <div class="cn-walkthrough__dim cn-walkthrough__dim--full"></div>
 *
 * — a FULL-VIEWPORT dim that legitimately swallows pointer events until the
 * user finishes or skips the tour. That is the intended product behaviour for
 * a genuinely first-time visitor, and it is exactly what every CI run gets,
 * because a fresh runner profile has never seen it.
 *
 * The symptom is deliberately confusing and worth recording: the target button
 * RESOLVES and reports "visible, enabled and stable", then the click retries
 * until the test times out. Run 30879466171 logged it verbatim:
 *
 *     - locator resolved to <button data-testid="cn-action-import-bill" …>
 *     - attempting click action
 *       - element is visible, enabled and stable
 *       - <div class="cn-walkthrough__dim cn-walkthrough__dim--full"></div>
 *         from <div role="dialog" … aria-label="Welcome to Shillinq">…</div>
 *         subtree intercepts pointer events
 *
 * So it presents as a TIMEOUT ON A PRESENT ELEMENT, never as "overlay open" —
 * which is why it reads like a dozen unrelated flaky-click defects.
 *
 * This is the THIRD onboarding surface in the stack, and the other two are
 * already handled elsewhere: Nextcloud's own `#firstrunwizard` (dismissed by
 * the specs) and the ADR-042 setup wizard (completed by `ci-seed.sh` over its
 * admin API). The walkthrough had no equivalent, so it is handled here.
 *
 * `CnAppRoot.walkthroughSeenVersion()` reads `localStorage` — NOT a server-side
 * per-user config, despite what `manifest.walkthrough.completionConfigKey`
 * suggests; the shipped getter's own docblock says apps wanting cross-device
 * persistence must override the `#walkthrough` slot. `useWalkthrough`'s
 * `autoStartTour` then gates on it:
 *
 *     if (tour.trigger === 'first-visit' && !seenVersion) return tour
 *
 * so ANY non-empty value stops a `first-visit` tour from auto-starting. We
 * write the real app version rather than a sentinel, so that if a future
 * `version-bump` tour is added it will still fire for a version ABOVE this one
 * — seeding the profile as a returning user, not disabling the feature.
 *
 * ⚠️ Nothing here touches an assertion, a timeout or a skip. The walkthrough
 * itself stays enabled in the product and is still reachable in-app via
 * "replay walkthrough"; it simply is not re-offered to an already-onboarded
 * profile. Specs that want to test the tour can clear the key themselves.
 *
 * Gated on a read-back so a silently-failing write cannot look like success.
 *
 * @param page A page already authenticated on the Nextcloud origin.
 */
async function markWalkthroughSeen(page: Page): Promise<void> {
	const version = readAppVersion()
	await page.evaluate(
		([key, value]) => window.localStorage.setItem(key, value),
		[WALKTHROUGH_SEEN_KEY, version],
	)
	const readBack = await page.evaluate(
		(key) => window.localStorage.getItem(key),
		WALKTHROUGH_SEEN_KEY,
	)
	if (readBack !== version) {
		throw new Error(
			`Failed to seed ${WALKTHROUGH_SEEN_KEY}: wrote "${version}", read back ${JSON.stringify(readBack)}. `
				+ 'The walkthrough overlay would intercept pointer events for the whole run.',
		)
	}
	// eslint-disable-next-line no-console
	console.log(
		`[playwright globalSetup] ${WALKTHROUGH_SEEN_KEY} = ${version} (walkthrough will not auto-start)`,
	)
}

/**
 * Stand the non-gating setup wizard down for every spec.
 *
 * CnAppRoot opens it whenever the server reports an OPTIONAL setup step as
 * outstanding, and shillinq declares six. It renders as a modal over the
 * shell, so a click on anything behind it does not fail fast — it waits out
 * the full timeout. That surfaces as dozens of unrelated specs failing on
 * their first interaction rather than as one cause.
 *
 * Seeded here, alongside the walkthrough, so the key travels with the
 * storage state into every spec's context. Dismissing it reactively per
 * test races the dialog's enter transition; seeding the key it reads does
 * not.
 *
 * The key is versioned (`cn-setup-wizard-dismissed:<appId>:<setup.version>`),
 * so a RANGE is seeded: bumping manifest.setup.version must not silently
 * re-open the wizard across the whole suite.
 *
 * @param page The authenticated page whose storage state is about to be captured.
 */
async function markSetupWizardDismissed(page: Page): Promise<void> {
	const keys = await page.evaluate(() => {
		const written: string[] = []
		for (let v = 0; v <= 20; v++) {
			const key = `cn-setup-wizard-dismissed:shillinq:${v}`
			window.localStorage.setItem(key, '1')
			written.push(key)
		}
		return written.filter((k) => window.localStorage.getItem(k) === '1')
	})
	if (keys.length !== 21) {
		throw new Error(
			`Failed to seed the setup-wizard dismissal: wrote 21 keys, read back ${keys.length}. `
				+ 'The setup wizard would render over the shell and intercept pointer events for the whole run.',
		)
	}
	// eslint-disable-next-line no-console
	console.log(
		'[playwright globalSetup] cn-setup-wizard-dismissed:shillinq:0..20 = 1 (setup wizard will not auto-open)',
	)
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

	// Seed the walkthrough as already-seen BEFORE the state is captured, so the
	// key travels with the storage state into every spec's context.
	await markWalkthroughSeen(page)
	await markSetupWizardDismissed(page)

	// Persist the storage state so individual specs reuse the session.
	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
