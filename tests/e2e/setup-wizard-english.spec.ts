/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * setup-wizard-english — REQ-SWE-005.
 *
 * Proves the required first-time setup wizard (ADR-042, `CnSetupWizard`)
 * renders English source text on a GENUINELY first-run instance, walking
 * every step (`welcome` -> `country` -> `organisation` -> `rgs-template` ->
 * `administration` -> `seed` -> `done`) and asserting the new English
 * `title`/`body`/option-label copy from `src/manifest.json` `.setup.steps[]`
 * (setup-wizard-english REQ-SWE-001), with no residual Dutch token.
 *
 * WHY A SERVER-SIDE RESET, NOT JUST A FRESH BROWSER CONTEXT
 * -----------------------------------------------------------
 * `SetupController::status()` (`lib/Controller/SetupController.php`) gates
 * the wizard on SERVER-SIDE app-config values (`legal_country`,
 * `legal_region`, `rgs_template`, `administration_id`, `setup_seed_done`,
 * `setup_completed_version`), not on anything client-side. This repo's own
 * `tests/e2e/ci-seed.sh` COMPLETES that wizard over the admin API before the
 * rest of the suite runs — every other spec in this directory assumes the
 * app is already set up. A fresh Playwright browser context therefore does
 * NOT reproduce the wizard: the server remembers. This spec resets the
 * server-side keys with `occ config:app:delete` (see `resetSetupState()`
 * below) before asserting the wizard renders — see design.md "Fresh-context
 * e2e: server state, not just browser state".
 *
 * ⚠️ CROSS-FILE CONTAMINATION HAZARD, AND WHY afterAll RESTORES STATE
 * ----------------------------------------------------------------------
 * `tests/e2e/playwright.config.ts` runs spec FILES in parallel (`workers:
 * 4`) even though `fullyParallel: false` keeps one file's own tests serial
 * on one worker. Every other spec file in this suite assumes the wizard is
 * already complete (`ci-seed.sh`'s nl / municipality(`gemeente`) / bbv
 * baseline). Resetting the server-side setup keys here would leave EVERY
 * concurrently-running sibling spec file staring at a blocking "Set up this
 * app" dialog instead of its own page. `afterAll` below unconditionally
 * restores the exact baseline `ci-seed.sh` establishes — via the same admin
 * HTTP endpoints, not by trusting whatever the UI walk left behind — so this
 * spec is safe to run inside the shared suite. Because the hazard is real
 * regardless, running this file with `--workers=1` (or as its own CI job) is
 * still the safer choice if the shared config ever changes.
 *
 * @spec openspec/changes/setup-wizard-english/specs/setup-wizard-english/spec.md
 */

import { test, expect, request, type Page } from '@playwright/test'
import { execSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'

import { resolveBaseURL } from './base-url'

const APP = '/apps/shillinq'
const APP_ROOT = path.resolve(__dirname, '..', '..')
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASS ?? 'admin'

// ── Server-side setup-state reset (REQ-SWE-005) ──────────────────────────

/** Every app-config key `SetupController::status()` reads to decide `completed`. */
const SETUP_CONFIG_KEYS = [
	'legal_country',
	'legal_region',
	'rgs_template',
	'administration_id',
	'setup_seed_done',
	'setup_completed_version',
]

/**
 * Resolve the Nextcloud server root so `occ` can be invoked.
 *
 * The shared CI workflow's own seed step (`ci-seed.sh`) runs with cwd set to
 * the Nextcloud server root and reaches this app at `apps/shillinq/...`
 * (`.github/workflows/code-quality.yml`'s `playwright-seed-command` comment)
 * — i.e. this repo is checked out at `<server-root>/apps/shillinq`.
 * `playwright test` itself runs from THIS repo's own root
 * (`playwright-test-path: tests/e2e`), so the server root is two levels up
 * by default. Override with `NC_SERVER_ROOT` when the layout differs (e.g.
 * a docker dev container that bind-mounts this repo somewhere else).
 */
function resolveServerRoot(): string {
	const override = process.env.NC_SERVER_ROOT
	if (override && override.trim() !== '') {
		return override.trim().replace(/\/+$/, '')
	}
	return path.resolve(APP_ROOT, '..', '..')
}

/**
 * Run one `occ` subcommand against the instance under test.
 *
 * WHY THIS IS NOT ALWAYS `php <server-root>/occ` DIRECTLY
 * ---------------------------------------------------------
 * On this team's docker dev setup the checked-out `server/` tree on the HOST
 * is source only — the live instance (config.php, DB connection, `installed:
 * true`) exists solely INSIDE the Nextcloud container. Host `php occ status`
 * there reads an empty/placeholder `config/config.php` and reports `installed:
 * false` against a completely different (unconfigured) install, so
 * `config:app:delete` fails with "Nextcloud is not installed" even though the
 * real instance the browser drives is healthy. `NC_SERVER_ROOT` alone cannot
 * fix this — the problem is the EXECUTION CONTEXT (host vs. container), not
 * the path.
 *
 * `NC_CONTAINER` is this fleet's established escape hatch for exactly that
 * (see `openregister/tests/e2e/base-url.ts::resolveContainer()` and
 * `openbuild/tests/e2e/global-setup.ts::resolveContainerFor()`): when set,
 * every `occ` call here runs via `docker exec -u www-data <container> php
 * occ …` instead of the host binary. Unset (the CI default, where `occ` runs
 * against a real `php -S`-served install on the runner itself) falls back to
 * the previous direct-host invocation unchanged.
 */
function runOcc(serverRoot: string, occPath: string, args: string[]): void {
	const container = process.env.NC_CONTAINER
	const quotedArgs = args.map((a) => JSON.stringify(a)).join(' ')
	const command = container
		? `docker exec -u www-data ${JSON.stringify(container)} php occ ${quotedArgs}`
		: `php ${JSON.stringify(occPath)} ${quotedArgs}`
	execSync(command, { cwd: serverRoot, stdio: 'pipe' })
}

/**
 * Delete every setup app-config key server-side, so `SetupController::
 * status()` reports `completed: false` again on the NEXT request — a
 * genuinely first-run instance, not merely a fresh browser context.
 *
 * Idempotent: `occ config:app:delete` exits 0 whether or not a key exists.
 */
function resetSetupStateServerSide(): void {
	const serverRoot = resolveServerRoot()
	const occPath = path.join(serverRoot, 'occ')
	if (!process.env.NC_CONTAINER && !fs.existsSync(occPath)) {
		throw new Error(
			`[setup-wizard-english] cannot find occ at ${occPath} (resolved server root: `
				+ `${serverRoot}). Set NC_SERVER_ROOT to the Nextcloud install directory, or set `
				+ 'NC_CONTAINER to the docker container serving the instance under test (see '
				+ "runOcc() above), so this spec can reset shillinq's setup app-config server-side "
				+ '— a fresh browser context alone does not reproduce the wizard, since '
				+ 'SetupController::status() gates on server-side app-config (design.md '
				+ '"Fresh-context e2e").',
		)
	}
	for (const key of SETUP_CONFIG_KEYS) {
		runOcc(serverRoot, occPath, ['config:app:delete', 'shillinq', key])
	}
}

/**
 * Restore the exact baseline `tests/e2e/ci-seed.sh` establishes for the rest
 * of the suite (nl / municipality(`gemeente`) / bbv), over the same admin
 * HTTP endpoints `ci-seed.sh` itself uses — NOT by trusting whatever state
 * the UI walk below left behind, so this converges to a known-good baseline
 * even if an assertion above failed mid-walk.
 *
 * `legal_region: 'gemeente'` (not `'municipality'`, the manifest option's
 * `value`) is intentional — it matches `ci-seed.sh`'s own payload, which is
 * what `bbv-compliance.spec.ts` and friends were seeded against.
 */
async function restoreCiSeedBaseline(baseURL: string): Promise<void> {
	const ctx = await request.newContext({
		baseURL,
		httpCredentials: { username: ADMIN_USER, password: ADMIN_PASS },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
	try {
		await ctx.post('/index.php/apps/shillinq/api/setup/config', {
			data: {
				legal_country: 'nl',
				legal_region: 'gemeente',
				rgs_template: 'bbv',
			},
		})
		await ctx.post(
			'/index.php/apps/shillinq/api/setup/action/init-administration',
			{ data: {} },
		)
		await ctx.post('/index.php/apps/shillinq/api/setup/action/seed', {
			data: {},
		})
		const status = await ctx.get('/index.php/apps/shillinq/api/setup/status')
		const body = await status.json().catch(() => ({}))
		if (body?.completed !== true) {
			// eslint-disable-next-line no-console
			console.warn(
				'[setup-wizard-english] afterAll restore did not report completed:true — '
					+ `sibling specs may see a blocking setup dialog. status: ${JSON.stringify(body)}`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

// ── Curated Dutch-token spot-check ───────────────────────────────────────
// A small, high-precision list of the EXACT pre-change Dutch strings this
// change removed (design.md's wizard string inventory table), asserted
// ABSENT from the rendered wizard. This is a targeted regression check, not
// a general-purpose scanner — `tests/l10n/check-dutch-tokens.js` (REQ-SWE-004)
// already covers that with a broader curated stopword list; duplicating its
// machinery here would test the gate, not the product.
const PRE_CHANGE_DUTCH_STRINGS = [
	'Welkom bij Shillinq',
	'Juridische regio (land)',
	'Organisatietype',
	'Rekeningschema (RGS)',
	'Administratie aanmaken',
	'Rekeningschema en referentiedata laden',
	'Klaar',
	'Nederland',
	'België',
	'Duitsland',
	'Gemeente',
	'Provincie',
	'Waterschap',
	'BBV (overheid)',
]

/** Dismiss Nextcloud's own `#firstrunwizard` — a DIFFERENT dialog from shillinq's own setup wizard. */
async function dismissFirstRunWizard(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('Setup wizard — English source text (REQ-SWE-005)', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async () => {
		resetSetupStateServerSide()
	})

	test.afterAll(async () => {
		// Unconditional — must run even if a step assertion above threw, so
		// sibling spec files never inherit a blocking setup dialog.
		await restoreCiSeedBaseline(resolveBaseURL())
	})

	test('the wizard gates the shell and walks all 7 steps in English @e2e REQ-SWE-001 REQ-SWE-005', async ({
		page,
	}) => {
		await page.goto(APP, { waitUntil: 'domcontentloaded', timeout: 25_000 })
		await dismissFirstRunWizard(page)

		const dialog = page.getByRole('dialog').first()
		await expect(
			dialog,
			"shillinq's own ADR-042 setup dialog must gate the shell on a reset instance",
		).toBeVisible({ timeout: 15_000 })

		/**
		 * Assert a step's OWN heading.
		 *
		 * The wizard renders a progress TABLIST whose labels are the step
		 * titles, so every title now appears TWICE in the dialog — once as
		 * `.cn-wizard-dialog__progress-label` in the tablist, once as the
		 * step's `.notecard__heading`. `dialog.getByText(<title>)` therefore
		 * resolves to 2 elements and Playwright strict mode fails before it
		 * compares anything:
		 *
		 *   strict mode violation: … resolved to 2 elements
		 *
		 * All seven step titles collide this way; the run only reported the
		 * first because the assertion throws. Target the notecard heading —
		 * the step BODY is what this spec is about, and a tablist label is
		 * present for every step whether or not that step is showing.
		 */
		const stepHeading = (title: string) =>
			dialog.locator('.notecard__heading', { hasText: title }).first()

		// ── Step 0: welcome ──────────────────────────────────────────────
		await expect(stepHeading('Welcome to Shillinq')).toBeVisible()
		await expect(
			dialog.getByText(
				'First choose the country (legal region) and organisation type, then the chart-of-accounts template',
				{ exact: false },
			),
		).toBeVisible()
		await assertNoDutchToken(dialog)
		await clickContinue(dialog)

		// ── Step 1: country ──────────────────────────────────────────────
		await expect(stepHeading('Legal region (country)')).toBeVisible()
		await expect(
			dialog.getByText(
				'In which country is this organisation legally established?',
				{ exact: false },
			),
		).toBeVisible()
		await expect(dialog.getByText('Netherlands', { exact: true })).toBeVisible()
		await expect(dialog.getByText('Belgium', { exact: true })).toBeVisible()
		await expect(dialog.getByText('Germany', { exact: true })).toBeVisible()
		await assertNoDutchToken(dialog)
		await selectOption(dialog, 'Netherlands')
		await clickContinue(dialog)

		// ── Step 2: organisation ─────────────────────────────────────────
		await expect(stepHeading('Organisation type')).toBeVisible()
		await expect(dialog.getByText('Municipality', { exact: true })).toBeVisible()
		await expect(dialog.getByText('Province', { exact: true })).toBeVisible()
		await expect(
			dialog.getByText('Water authority', { exact: true }),
		).toBeVisible()
		// Jurisdiction-specific legal-entity acronyms must remain unglossed
		// (ADR-007 proper-noun/acronym exception) — see REQ-SWE-001.
		await expect(dialog.getByText('ZZP', { exact: true })).toBeVisible()
		await expect(dialog.getByText('MKB', { exact: true })).toBeVisible()
		await assertNoDutchToken(dialog)
		await selectOption(dialog, 'Municipality')
		await clickContinue(dialog)

		// ── Step 3: rgs-template ─────────────────────────────────────────
		await expect(stepHeading('Chart of accounts (RGS)')).toBeVisible()
		await expect(
			dialog.getByText(
				'the standardised layout of ledger accounts your balance sheet',
				{ exact: false },
			),
		).toBeVisible()
		await expect(
			dialog.getByText('BBV (government)', { exact: true }),
		).toBeVisible()
		await assertNoDutchToken(dialog)
		await selectOption(dialog, 'BBV (government)')
		await clickContinue(dialog)

		// ── Step 4: administration (run-action) ──────────────────────────
		await expect(stepHeading('Create administration')).toBeVisible()
		await expect(
			dialog.getByText(
				'This registers your organisation as an administration in OpenRegister',
				{ exact: false },
			),
		).toBeVisible()
		await assertNoDutchToken(dialog)
		await clickRun(dialog)
		await waitForActionComplete(dialog)
		await clickContinue(dialog)

		// ── Step 5: seed (run-action) ─────────────────────────────────────
		await expect(
			stepHeading('Load chart of accounts and reference data'),
		).toBeVisible()
		await expect(
			dialog.getByText(
				'Load the chosen chart of accounts (ledger accounts), the VAT rates',
				{ exact: false },
			),
		).toBeVisible()
		await assertNoDutchToken(dialog)
		await clickRun(dialog)
		await waitForActionComplete(dialog)
		await clickContinue(dialog)

		// ── Step 6: done (summary) ────────────────────────────────────────
		await expect(stepHeading('Done')).toBeVisible()
		await expect(
			dialog.getByText(
				'Review your choices below and complete the installation.',
				{ exact: false },
			),
		).toBeVisible()
		await assertNoDutchToken(dialog)

		// Finish — the wizard's own "complete" action. Wording is a chrome
		// string from `@conduction/nextcloud-vue` (not migrated by this
		// change), so match broadly.
		const finishButton = dialog
			.getByRole('button', { name: /finish|complete|done|close/i })
			.last()
		await finishButton.click({ timeout: 10_000 }).catch(() => {
			// eslint-disable-next-line no-console
			console.warn(
				'[setup-wizard-english] no explicit finish button matched; the wizard may auto-close on `completed: true`.',
			)
		})

		// ── The shell is unblocked ────────────────────────────────────────
		await expect(
			dialog,
			'the setup dialog must not still gate the shell once all required steps are done',
		).not.toBeVisible({ timeout: 15_000 })
		await expect(page.locator('main, [role="main"]')).toBeVisible({
			timeout: 15_000,
		})
	})
})

/** Assert none of the pre-change Dutch strings appear inside `scope`'s text. */
async function assertNoDutchToken(
	scope: ReturnType<Page['getByRole']>,
): Promise<void> {
	const text = await scope.innerText()
	for (const dutch of PRE_CHANGE_DUTCH_STRINGS) {
		expect(
			text,
			`residual pre-change Dutch string "${dutch}" found in the setup dialog`,
		).not.toContain(dutch)
	}
}

/**
 * Click a step's Next/Continue action. `CnSetupWizard`'s own chrome strings
 * are NOT migrated by this change (they already route through
 * `t('nextcloud-vue', …)` per design.md), so match broadly rather than pin
 * an exact label that could be "Next" or "Continue" depending on version.
 */
async function clickContinue(scope: ReturnType<Page['getByRole']>): Promise<void> {
	await scope
		.getByRole('button', { name: /next|continue/i })
		.first()
		.click({ timeout: 10_000 })
}

/** Click a `run-action` step's Run button (per design.md: "Klik op 'Run' om te starten" -> "Run"). */
async function clickRun(scope: ReturnType<Page['getByRole']>): Promise<void> {
	await scope
		.getByRole('button', { name: /run/i })
		.first()
		.click({ timeout: 10_000 })
}

/** Select a choice-step option by its (English) label text. */
async function selectOption(
	scope: ReturnType<Page['getByRole']>,
	label: string,
): Promise<void> {
	await scope.getByText(label, { exact: true }).first().click({ timeout: 10_000 })
}

/**
 * `run-action` steps (`administration`, `seed`) run a privileged server call
 * and report their own completion; wait for the Continue action to become
 * available rather than a fixed sleep.
 */
async function waitForActionComplete(
	scope: ReturnType<Page['getByRole']>,
): Promise<void> {
	await expect(
		scope.getByRole('button', { name: /next|continue/i }).first(),
	).toBeEnabled({ timeout: 60_000 })
}
