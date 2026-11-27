/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the dunning Next run preview and a ladder's stage texts
 * (receivables-automatic-dunning task 4.1, REQ-RAD-008).
 *
 *   1. `GET /api/dunning/next-run` answers one entry per administration the
 *      caller holds, each with its on/off state, the last run report and the
 *      rows the next run would send, and the preview writes nothing: asking
 *      twice gives the same rows;
 *   2. the Dunning runs page has a Next run header action that opens the
 *      preview modal over the page, showing either an administration block
 *      or the "no administration" line;
 *   3. a ladder's page shows the Stages table with the subject and body a
 *      letter uses, read from `GET /api/dunning/ladders/{id}/stages`. An
 *      instance without a ladder skips this step and says so.
 *
 * The live check of the job (task 5.1, REQ-RAD-001, REQ-RAD-003,
 * REQ-RAD-006), through the HTTP surface the change has:
 *
 *   4. dunning is switched on for a seeded administration the way the
 *      administration page's Switch on reminders action does it (a PATCH of
 *      `dunningEnabled` on the Administration object), and the Next run
 *      answer then shows it on, with the stage and channel each invoice would
 *      get; the rows are recorded in the report;
 *   5. running `DunningTickJob` with `occ background-job:execute` is not
 *      reachable from Playwright: test.fixme with that reason;
 *   6. once the job has run for an administration with reminders on (that
 *      occ step, or the instance's daily cron), its stored run report, read
 *      from the Next run answer, counts at least one sent and one manual
 *      stage, and the DunningRun objects of that run hold a stage-1 EMAIL run
 *      DELIVERED and a stage-4 run MANUAL; their ids go in the report for the
 *      PR body. When no administration with reminders on has a run report
 *      yet, the test skips and says so;
 *   7. the mail arriving in the customer's mailbox has no surface in the
 *      suite (no mail catcher; DELIVERED means the mail server accepted it):
 *      test.fixme with that reason.
 *
 * The administration of step 4 is `E2E_ADMINISTRATION_ID` when set, else the
 * first one the Next run answer lists with invoices to chase, else the first.
 * Step 4 puts the switch back as it found it afterwards, unless
 * `E2E_DUNNING_KEEP_ENABLED=1`, which leaves reminders on for the occ step
 * of the live pass.
 *
 * The caller is the suite's administrator (globalSetup's storage state).
 * Steps 1 to 3 and 6 write nothing. Written 24 Nov 2026, runs in the
 * nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/archive/2026-11-24-receivables-automatic-dunning/specs/bookkeeping-credit-control-dunning/spec.md
 * @spec openspec/changes/archive/2026-11-24-receivables-automatic-dunning/tasks.md
 *
 * @e2e bookkeeping-credit-control-dunning::a-bookkeeper-checks-before-switching-dunning-on
 * @e2e bookkeeping-credit-control-dunning::the-first-reminder-goes-out-without-anyone-acting
 * @e2e bookkeeping-credit-control-dunning::the-notice-of-default-must-be-posted
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** One administration of the Next run answer (DunningPreviewController::nextRun). */
interface NextRunEntry {
	administrationId: string
	id: string
	name: string
	enabled: boolean
	lastRun: unknown
	rows: Array<Record<string, unknown>>
}

/** The stored report of one job run (DunningTickRunner::runAdministration). */
interface RunReport {
	administrationId: string
	ranAt: string
	markedOverdue: number
	sent: number
	failed: number
	manual: number
	skipped: number
	errors: number
	locked: boolean
}

/** One DunningRun object as the object API answers it. */
interface DunningRunRow {
	id?: string
	'@self'?: { id?: string }
	invoiceId?: string
	stageNr?: number | string
	channel?: string
	deliveryStatus?: string
	deliveryNote?: string
	executedOn?: string
}

/** One stage row of a ladder (DunningPreviewController::ladderStages). */
interface StageRow {
	nr: number
	days?: number
	channelLabel?: string
	subject?: string
	body?: string
}

/**
 * Close the first-run wizard when Nextcloud shows it over the page.
 *
 * @param page The page.
 */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('receivables-automatic-dunning: the Next run preview', () => {
	test('the preview lists each administration and writes nothing @spec REQ-RAD-008', async ({ request }) => {
		const first = await request.get(`${API}/dunning/next-run`, { headers: HEADERS })
		expect(first.status(), await first.text()).toBe(200)
		const entries = ((await first.json()) as { administrations: NextRunEntry[] }).administrations
		expect(Array.isArray(entries)).toBe(true)
		for (const entry of entries) {
			expect(typeof entry.id).toBe('string')
			expect(typeof entry.enabled).toBe('boolean')
			expect(Array.isArray(entry.rows)).toBe(true)
		}

		const second = await request.get(`${API}/dunning/next-run`, { headers: HEADERS })
		expect(second.status()).toBe(200)
		const again = ((await second.json()) as { administrations: NextRunEntry[] }).administrations
		expect(again.map((entry) => [entry.id, entry.rows.length]))
			.toEqual(entries.map((entry) => [entry.id, entry.rows.length]))
	})

	test('an unknown administration is refused @spec REQ-RAD-008', async ({ request }) => {
		const res = await request.get(`${API}/dunning/next-run?administrationId=${encodeURIComponent('no such administration')}`, { headers: HEADERS })
		expect([400, 404]).toContain(res.status())
	})

	test('the Dunning runs page opens the Next run preview @spec REQ-RAD-008', async ({ page }) => {
		await page.goto(`/index.php${APP}/bookkeeping/dunning/runs`)
		await dismissOverlays(page)
		await page.getByRole('button', { name: 'Next run' }).first().click()

		const modal = page.getByTestId('dunning-next-run-modal')
		await expect(modal).toBeVisible()
		await expect(page.getByTestId('dunning-next-run-loading')).toBeHidden({ timeout: 15_000 })
		await expect(modal.getByTestId('dunning-next-run-administration').first()
			.or(modal.getByText('You have no administration to show.'))).toBeVisible()
		// The page underneath stays the Dunning runs page.
		expect(page.url()).toContain('/bookkeeping/dunning/runs')
	})

	test('a ladder shows the subject and body of each stage @spec REQ-RAD-008', async ({ page, request }) => {
		const list = await request.get(`${OBJECTS}/DunningLadder?_limit=1`, { headers: HEADERS })
		expect(list.ok(), await list.text()).toBe(true)
		const ladder = (((await list.json()) as { results?: Array<{ id?: string, '@self'?: { id?: string } }> }).results ?? [])[0]
		test.skip(ladder === undefined, 'the instance holds no dunning ladder')
		const id = String(ladder?.id ?? ladder?.['@self']?.id ?? '')

		const stages = await request.get(`${API}/dunning/ladders/${encodeURIComponent(id)}/stages`, { headers: HEADERS })
		expect(stages.status(), await stages.text()).toBe(200)
		const rows = ((await stages.json()) as { rows: StageRow[] }).rows
		const numbers = rows.map((row) => Number(row.nr))
		expect(numbers).toEqual([...numbers].sort((a, b) => a - b))

		await page.goto(`/index.php${APP}/bookkeeping/dunning/ladders/${encodeURIComponent(id)}`)
		await dismissOverlays(page)
		await expect(page.getByText('Stages').first()).toBeVisible()
		const firstWithSubject = rows.find((row) => typeof row.subject === 'string' && row.subject !== '')
		if (firstWithSubject !== undefined) {
			await expect(page.getByText(String(firstWithSubject.subject)).first()).toBeVisible()
		} else {
			await expect(page.getByText('This ladder has no stages.').or(page.locator('table'))).toBeVisible()
		}
	})

	test('an unknown ladder is answered not found @spec REQ-RAD-008', async ({ request }) => {
		const res = await request.get(`${API}/dunning/ladders/00000000-0000-0000-0000-000000000000/stages`, { headers: HEADERS })
		expect(res.status()).toBe(404)
	})
})

test.describe('receivables-automatic-dunning: the daily job on a seeded administration', () => {
	let switched: { id: string, before: boolean } | null = null

	/**
	 * The Next run answer, for one administration or all of them.
	 *
	 * @param request          The request context.
	 * @param administrationId The administration, or '' for every one the caller holds.
	 * @return The entries.
	 */
	async function nextRun(request: APIRequestContext, administrationId = ''): Promise<NextRunEntry[]> {
		const query = administrationId === '' ? '' : `?administrationId=${encodeURIComponent(administrationId)}`
		const res = await request.get(`${API}/dunning/next-run${query}`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		return ((await res.json()) as { administrations: NextRunEntry[] }).administrations
	}

	/**
	 * Set `dunningEnabled` on an Administration, as the Switch on reminders action does.
	 *
	 * @param request The request context.
	 * @param id      The Administration record id.
	 * @param enabled The value.
	 */
	async function setEnabled(request: APIRequestContext, id: string, enabled: boolean): Promise<void> {
		const html = await (await request.get('/index.php/apps/shillinq/')).text()
		const token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		const res = await request.patch(`${OBJECTS}/Administration/${encodeURIComponent(id)}`, {
			headers: { ...HEADERS, requesttoken: token, 'Content-Type': 'application/json' },
			data: { dunningEnabled: enabled },
		})
		expect(res.ok(), await res.text()).toBe(true)
	}

	/**
	 * The DunningRun objects filed under one administration key.
	 *
	 * @param request The request context.
	 * @param key     The administration code or record id.
	 * @return The runs.
	 */
	async function runsOf(request: APIRequestContext, key: string): Promise<DunningRunRow[]> {
		const res = await request.get(`${OBJECTS}/DunningRun?administrationId=${encodeURIComponent(key)}&_limit=500`, { headers: HEADERS })
		expect(res.ok(), await res.text()).toBe(true)
		const body = await res.json()
		return (body.results ?? body.items ?? []) as DunningRunRow[]
	}

	test.afterAll(async ({ playwright, baseURL }) => {
		if (switched === null || process.env.E2E_DUNNING_KEEP_ENABLED === '1') {
			return
		}
		const api = await playwright.request.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		await setEnabled(api, switched.id, switched.before).catch(() => undefined)
		await api.dispose()
	})

	test('dunning is switched on for a seeded administration and the next run shows what the job will send @spec REQ-RAD-001', async ({ request }) => {
		const entries = await nextRun(request)
		const wanted = process.env.E2E_ADMINISTRATION_ID ?? ''
		const entry = wanted !== ''
			? entries.find((e) => e.administrationId === wanted || e.id === wanted)
			: (entries.find((e) => e.rows.length > 0) ?? entries[0])
		test.skip(entry === undefined, wanted !== '' ? `the administrator holds no membership of ${wanted}` : 'the administrator holds no administration')

		switched = { id: String(entry?.id), before: entry?.enabled === true }
		await setEnabled(request, switched.id, true)

		const [after] = await nextRun(request, switched.id)
		expect(after.enabled, 'reminders are on').toBe(true)
		for (const row of after.rows) {
			expect(Number(row.stageNr)).toBeGreaterThanOrEqual(1)
			expect(typeof row.channel).toBe('string')
		}
		test.info().annotations.push({
			type: 'next run',
			description: `${after.administrationId}: ${after.rows.map((row) => `${String(row.invoiceNumber ?? row.invoiceId)} stage ${String(row.stageNr)} ${String(row.channel)}`).join(', ') || 'nothing due'}`,
		})
	})

	test('the job runs with occ background-job:execute @spec REQ-RAD-001', async () => {
		test.fixme(true, 'DunningTickJob runs from cron or `occ background-job:execute <job id>` on the instance; no HTTP route starts it, so Playwright cannot run it. The test below reads what the run left behind.')
	})

	test('after the job ran, its report and runs show a delivered stage-1 mail and a manual stage-4 run @spec REQ-RAD-001 @spec REQ-RAD-003 @spec REQ-RAD-006', async ({ request }) => {
		const wanted = process.env.E2E_ADMINISTRATION_ID ?? ''
		const entry = (await nextRun(request))
			.filter((e) => wanted === '' || e.administrationId === wanted || e.id === wanted)
			.find((e) => e.enabled && e.lastRun !== null && typeof e.lastRun === 'object')
		test.skip(entry === undefined, 'no administration with reminders on has a job run report yet; run `occ background-job:execute` for DunningTickJob after the switch-on test (with E2E_DUNNING_KEEP_ENABLED=1), or wait for the daily cron')

		const report = entry?.lastRun as RunReport
		expect(report.locked, 'the run was not blocked by another one').toBe(false)
		test.skip(
			report.sent + report.manual + report.failed === 0,
			`the last run (${report.ranAt}) had nothing to send: the administration holds no overdue invoice due for a stage`,
		)
		expect(report.sent, 'the run sent at least one stage').toBeGreaterThanOrEqual(1)
		expect(report.manual, 'the run handed at least one stage to a person').toBeGreaterThanOrEqual(1)

		const day = String(report.ranAt).slice(0, 10)
		const keys = [...new Set([entry?.administrationId ?? '', entry?.id ?? ''].filter((key) => key !== ''))]
		const runs = (await Promise.all(keys.map((key) => runsOf(request, key)))).flat()
			.filter((run) => String(run.executedOn ?? '').slice(0, 10) === day)

		const delivered = runs.find((run) => Number(run.stageNr) === 1 && run.channel === 'EMAIL' && run.deliveryStatus === 'DELIVERED')
		const manual = runs.find((run) => Number(run.stageNr) === 4 && run.deliveryStatus === 'MANUAL')
		expect(delivered, `a stage-1 EMAIL run DELIVERED on ${day}`).toBeDefined()
		expect(manual, `a stage-4 run MANUAL on ${day}`).toBeDefined()
		expect(runs.some((run) => run.deliveryStatus === 'DELIVERED' && run.channel !== 'EMAIL' && run.channel !== 'eMAILPostRegistration'), 'no non-mail stage is recorded delivered').toBe(false)

		for (const [label, run] of [['stage 1 delivered', delivered], ['stage 4 manual', manual]] as const) {
			test.info().annotations.push({ type: 'DunningRun', description: `${label}: ${String(run?.id ?? run?.['@self']?.id ?? '')} (invoice ${String(run?.invoiceId ?? '')})` })
		}
	})

	test('the stage-1 mail arrives in the customer\'s mailbox @spec REQ-RAD-003', async () => {
		test.fixme(true, 'the suite has no mail catcher, and DELIVERED only records that the mail server accepted the message; the arrival is checked by hand in the live pass')
	})
})
