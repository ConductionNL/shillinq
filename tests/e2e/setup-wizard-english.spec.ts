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

import type { Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { execSync } from 'child_process'
import * as fs from 'fs'
import * as path from 'path'
import { resolveBaseURL } from './base-url.ts'

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
		// sibling spec files never inherit a blocking setup dialog. Playwright
		// runs `afterAll` regardless of the test's own pass/fail; that part
		// was already true. What was NOT already true: `restoreCiSeedBaseline`
		// drives the SAME `seed` action via direct API calls
		// (`POST /api/setup/action/seed`) that `waitForActionComplete` had to
		// wait up to 120s for when UI-driven — and this hook had no timeout
		// override, so it inherited the suite's default 60s HOOK timeout. A
		// restore that times out mid-flight leaves the instance un-set-up,
		// which is the exact contamination this file exists to prevent —
		// just reached via the cleanup path instead of the walk. `test.
		// setTimeout()` inside a hook sets THAT hook's own timeout.
		test.setTimeout(180_000)
		await restoreCiSeedBaseline(resolveBaseURL())
	})

	test('the wizard gates the shell and walks all 7 steps in English @e2e REQ-SWE-001 REQ-SWE-005', async ({
		page,
	}) => {
		// `playwright.config.ts`'s suite-wide per-test `timeout` is 60_000ms,
		// sized for the rest of the suite's single-page-load specs. This test
		// is not that shape: it walks 7 real wizard steps AND runs TWO
		// privileged server actions (`administration`, `seed`), each waited on
		// individually by `waitForActionComplete()` below. Live evidence
		// (partial, on a shared/contended dev box — see PR notes, NOT a clean
		// measurement) showed the `seed` action alone still running past the
		// 60s mark. A 60s WHOLE-TEST budget can never fit two actions each
		// individually budgeted that high, regardless of environment — this
		// override is a structural fix, not a tuning knob. 300s leaves room
		// for nav + all 7 steps + two ~120s-worst-case actions + the finish
		// assertions; CI's own history should confirm/tighten this number.
		test.setTimeout(300_000)

		await page.goto(APP, { waitUntil: 'domcontentloaded', timeout: 25_000 })
		await dismissFirstRunWizard(page)

		const dialog = page.getByRole('dialog').first()
		await expect(
			dialog,
			"shillinq's own ADR-042 setup dialog must gate the shell on a reset instance",
		).toBeVisible({ timeout: 15_000 })

		// `CnWizardDialog`'s own progress tablist (`.cn-wizard-dialog__progress`)
		// renders every step's `label` (= `step.title`) as a permanently-visible
		// tab, alongside `.cn-wizard-dialog__step-body` which renders the
		// CURRENT step's content. An `info` step's `NcNoteCard` heading and a
		// `choice` step's `NcSelect` `inputLabel` both echo `step.title` too —
		// so a title check scoped to the whole `dialog` matches BOTH the tab
		// chrome and the step content and fails Playwright's strict mode. Scope
		// title assertions that are genuinely duplicated (info + choice steps)
		// to `stepBody` so they prove the CONTENT renders English, not merely
		// that the (out-of-scope, unmigrated) tablist chrome does.
		const stepBody = dialog.locator('.cn-wizard-dialog__step-body')

		// ── Step 0: welcome ──────────────────────────────────────────────
		await expect(
			stepBody.getByText('Welcome to Shillinq', { exact: false }),
		).toBeVisible()
		await expect(
			dialog.getByText(
				'First choose the country (legal region) and organisation type, then the chart-of-accounts template',
				{ exact: false },
			),
		).toBeVisible()
		await assertNoDutchToken(dialog)
		await clickContinue(dialog)

		// ── Step 1: country ──────────────────────────────────────────────
		await expect(
			stepBody.getByText('Legal region (country)', { exact: false }),
		).toBeVisible()
		await expect(
			dialog.getByText(
				'In which country is this organisation legally established?',
				{ exact: false },
			),
		).toBeVisible()
		// `NcSelect` is a real combobox: its options are NOT in the DOM at all
		// until it is opened (verified live — a closed `NcSelect` shows only
		// the current/placeholder value; `getByRole('option')` has zero
		// matches beforehand). Open it before asserting option labels.
		//
		// ⚠️ OPTIONS ARE `appendToBody: true` — QUERY `page`, NOT `dialog`/
		// `stepBody`. `NcSelect`'s underlying vue-select defaults
		// `appendToBody` to `true` (confirmed in `@nextcloud/vue`'s NcSelect
		// props): the open listbox is teleported to a direct child of
		// `<body>`, not nested inside the dialog's own DOM subtree at all. A
		// browser accessibility-tree snapshot (`aria-owns`) SHOWS it nested
		// under the combobox for readability, which is a false trail — real
		// DOM-scoped locators (`dialog.getByRole('option')`,
		// `stepBody.getByRole('option')`) match nothing, open or closed,
		// confirmed live. `assertOptionVisible()` / `selectOption()` below
		// therefore take `page`.
		await openChoiceDropdown(stepBody)
		await assertOptionVisible(page, 'Netherlands')
		await assertOptionVisible(page, 'Belgium')
		await assertOptionVisible(page, 'Germany')
		await assertNoDutchToken(dialog)
		await selectOption(page, 'Netherlands')
		await clickContinue(dialog)

		// ── Step 2: organisation ─────────────────────────────────────────
		await expect(
			stepBody.getByText('Organisation type', { exact: false }),
		).toBeVisible()
		await openChoiceDropdown(stepBody)
		await assertOptionVisible(page, 'Municipality')
		await assertOptionVisible(page, 'Province')
		await assertOptionVisible(page, 'Water authority')
		// Jurisdiction-specific legal-entity acronyms must remain unglossed
		// (ADR-007 proper-noun/acronym exception) — see REQ-SWE-001.
		await assertOptionVisible(page, 'ZZP')
		await assertOptionVisible(page, 'MKB')
		await assertNoDutchToken(dialog)
		await selectOption(page, 'Municipality')
		await clickContinue(dialog)

		// ── Step 3: rgs-template ─────────────────────────────────────────
		await expect(
			stepBody.getByText('Chart of accounts (RGS)', { exact: false }),
		).toBeVisible()
		await expect(
			dialog.getByText(
				'the standardised layout of ledger accounts your balance sheet',
				{ exact: false },
			),
		).toBeVisible()
		await openChoiceDropdown(stepBody)
		await assertOptionVisible(page, 'BBV (government)')
		await assertNoDutchToken(dialog)
		await selectOption(page, 'BBV (government)')
		await clickContinue(dialog)

		// ── Step 4: administration (run-action) ──────────────────────────
		await expect(
			dialog.getByText('Create administration', { exact: false }),
		).toBeVisible()
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
			dialog.getByText('Load chart of accounts and reference data', {
				exact: false,
			}),
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
		await expect(dialog.getByText('Done', { exact: true })).toBeVisible()
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

/**
 * Open a `choice` step's `NcSelect` combobox so its options render.
 *
 * `NcSelect` (vue-select underneath) does not put its options in the DOM at
 * all while closed — only the current value / placeholder is rendered.
 * Verified live against a fresh wizard: `getByRole('option')` has ZERO
 * matches until the combobox itself is clicked. Every `choice` step's
 * option-label assertions (and `selectOption()` below) therefore depend on
 * this running first.
 *
 * RETRIES THE CLICK, NOT JUST THE WAIT
 * -------------------------------------
 * A single click right after `clickContinue()` intermittently misses
 * (verified live, twice, on the `organisation` step specifically — the new
 * step's DOM, including the combobox, mounts fresh on every step change, so
 * a click issued a beat too early can land on an element still transitioning
 * in). `toPass()` re-issues the click, not just the visibility check, until
 * the combobox reports itself open.
 *
 * WHY `aria-expanded`, NOT AN OPTION COUNT
 * -------------------------------------------
 * An early version gated the retry on `getByRole('option').count() === 0`
 * (skip re-clicking if options already exist). `appendToBody: true` (see
 * `findOptionByLabel()`) means those options are a `page`-level sibling of
 * the dialog, not scoped to the current step — a still-unmounting PRIOR
 * step's listbox can leave stale option nodes counted against the NEW
 * step's combobox for a beat, making the count look non-zero when the
 * current combobox is in fact still closed. The combobox's own
 * `aria-expanded` reflects only ITS state, so it can't be fooled by a
 * sibling step's leftovers.
 */
async function openChoiceDropdown(
	scope: ReturnType<Page['getByRole']>,
): Promise<void> {
	const page = scope.page()
	const combobox = scope.getByRole('combobox').first()
	await expect(combobox).toBeVisible({ timeout: 10_000 })
	await expect(async () => {
		if ((await combobox.getAttribute('aria-expanded')) !== 'true') {
			await combobox.click({ timeout: 5_000 })
		}
		await expect(combobox).toHaveAttribute('aria-expanded', 'true', {
			timeout: 2_000,
		})
		await expect(page.getByRole('option').first()).toBeVisible({
			timeout: 2_000,
		})
	}).toPass({ timeout: 20_000 })
}

/**
 * Find an OPEN dropdown's option whose label — once inter-element whitespace
 * collapses — equals `label`, waiting (like `expect(...).toBeVisible()`
 * would) for the DOM to catch up rather than reading it once.
 *
 * WHY NOT `getByText(label, { exact: true })`
 * ---------------------------------------------
 * `NcSelect`'s underlying option renderer splits a longer label across TWO
 * sibling elements at a wrap point — verified live: "Netherlands" renders as
 * `<span>Nether</span><span>lands</span>`, "Municipality" as `<span>Munici
 * </span><span>pality</span>`, "Water authority" as `<span>Water au</span>
 * <span>thority</span>` — a vendor (`@nextcloud/vue` `NcSelect`) rendering
 * choice, not anything shillinq's manifest controls, and not visually a gap
 * (a screenshot shows one unbroken word). Playwright's own text/accessible-
 * name computation, however, INSERTS a separating space between sibling
 * elements' text, so both `getByText(label, { exact: true })` and
 * `getByRole('option', { name: label })` see "Nether lands" and never match
 * "Netherlands" — not because the option is missing or mistranslated, but
 * because two DOM nodes concatenated with an extra space. Shorter labels
 * ("Belgium", "ZZP", …) are not split and would have matched either way, so
 * this same whitespace-stripped comparison is used uniformly for all of
 * them rather than special-casing the ones that happen to wrap.
 */
async function findOptionByLabel(
	page: Page,
	label: string,
): Promise<ReturnType<Page['getByRole']> | null> {
	const options = page.getByRole('option')
	const wanted = label.replace(/\s+/g, '')
	const deadline = Date.now() + 10_000
	do {
		const count = await options.count()
		for (let i = 0; i < count; i++) {
			const candidate = options.nth(i)
			const text = await candidate.innerText().catch(() => '')
			if (text.replace(/\s+/g, '') === wanted) {
				return candidate
			}
		}
		await new Promise((resolve) => setTimeout(resolve, 200))
	} while (Date.now() < deadline)
	return null
}

/** Assert an OPEN dropdown includes an option labelled (English) `label` — see `findOptionByLabel()`. */
async function assertOptionVisible(page: Page, label: string): Promise<void> {
	const option = await findOptionByLabel(page, label)
	expect(
		option,
		`option "${label}" not found among the open dropdown's options`,
	).not.toBeNull()
	await expect(option as ReturnType<Page['getByRole']>).toBeVisible()
}

/** Select a choice-step option by its (English) label text. Call `openChoiceDropdown()` first. */
async function selectOption(page: Page, label: string): Promise<void> {
	const option = await findOptionByLabel(page, label)
	if (option === null) {
		throw new Error(
			`[setup-wizard-english] option "${label}" not found among the open dropdown's options`,
		)
	}
	await option.click({ timeout: 10_000 })
}

/**
 * `run-action` steps (`administration`, `seed`) run a privileged server call
 * and report their own completion; wait for that, not a fixed sleep.
 *
 * WHY THE `RUN` BUTTON, NOT `NEXT`/`CONTINUE`
 * ---------------------------------------------
 * Verified live: `Next` is NOT disabled while the action is in flight —
 * clicking it early is caught by the wizard's own on-click `validate`
 * callback, which shows a "Please run this step before continuing." banner
 * and does not advance. Waiting for `Next` to become "enabled" therefore
 * resolves immediately (it was never disabled) and races ahead of the real
 * async action. `CnSetupWizard`'s own template binds the RUN button's
 * `:disabled="running[step.id]"` — that flag is what actually tracks the
 * in-flight action, so waiting for `Run` to re-enable is the real
 * completion signal.
 *
 * TIMEOUT: partial live evidence, not a clean measurement. On a shared,
 * contended dev box the `seed` action (chart of accounts + VAT rates + BBV
 * task fields) was still running past 60s. 120s is a generous margin over
 * that single data point, not a precise figure — CI's own history is the
 * real source of truth here and should tighten this once it has runs.
 */
async function waitForActionComplete(
	scope: ReturnType<Page['getByRole']>,
): Promise<void> {
	await expect(scope.getByRole('button', { name: /run/i }).first()).toBeEnabled({
		timeout: 120_000,
	})
}
