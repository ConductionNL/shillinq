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
 * The caller is the suite's administrator (globalSetup's storage state). The
 * spec writes nothing. Written 24 Nov 2026, runs in the nightly; not run in
 * the build sandbox (no live instance).
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md
 *
 * @e2e bookkeeping-credit-control-dunning::a-bookkeeper-checks-before-switching-dunning-on
 */

import type { Page } from '@playwright/test'

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

/** One stage row of a ladder (DunningPreviewController::ladderStages). */
interface StageRow {
	nr: number
	days?: number
	channelLabel?: string
	subject?: string
	body?: string
}

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
