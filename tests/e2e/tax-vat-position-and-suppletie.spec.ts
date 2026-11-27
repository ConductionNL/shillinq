/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the running VAT position and the supplementary return
 * (tax-vat-position-and-suppletie task 3.2, REQ-VPS-001, REQ-VPS-002,
 * REQ-VPS-003).
 *
 *   1. the position mid-quarter: the position of 2026-Q4 answers per return
 *      box the base and VAT of the lines posted so far, box 1a at 21% of its
 *      base, and the payable amount as the VAT collected minus the VAT paid;
 *      the VAT reports page shows the payable tile and the boxes
 *      (REQ-VPS-001);
 *   2. the lines behind a box: a box's lines add up to its base and VAT, and
 *      "Show lines" on the page lists them with their entries (REQ-VPS-001);
 *   3. a late purchase invoice for a filed quarter: "Check for corrections"
 *      on a filed return writes a correction only when the books drifted
 *      from what was filed, and it shows on the corrections page; a prepared
 *      correction carries its per-box differences, the EUR 1,000 threshold
 *      decision, its deadline and the staged correction posting, and its
 *      supplementary return downloads (REQ-VPS-002);
 *   4. closing September: drift found after a period close is not run here,
 *      because closing a period cannot be undone on a shared instance
 *      (REQ-VPS-003).
 *
 * Invariants are asserted on whatever the caller's active administration
 * holds; the scenario's amounts (EUR 20,000 sales at 21%, EUR 1,500 input
 * VAT, EUR 1,800 late input VAT on Q2) are not seeded. The only write is the
 * correction "Check for corrections" makes when a filed return drifted,
 * which the spec deletes again; preparing a correction stages a ledger
 * posting, so the spec reads an already prepared one instead. The caller is
 * the suite's administrator (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-tax-vat-position-and-suppletie/specs/bookkeeping-vat-btw-filing/spec.md
 * @spec openspec/changes/archive/2026-11-24-tax-vat-position-and-suppletie/tasks.md#task-3.2
 *
 * @e2e bookkeeping-vat-btw-filing::a-bookkeeper-checks-the-position-mid-quarter
 * @e2e bookkeeping-vat-btw-filing::the-lines-behind-a-box
 * @e2e bookkeeping-vat-btw-filing::a-late-purchase-invoice-for-a-filed-quarter
 * @e2e bookkeeping-vat-btw-filing::closing-september-finds-a-q2-drift
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const PERIOD = '2026-Q4'

/** An OpenRegister object as the list and item endpoints answer it. */
interface RegisterObject {
	id?: string
	'@self'?: { id?: string }
	[field: string]: unknown
}

/** One posted line behind a box. */
interface PositionLine {
	glAccountNumber: string
	glTransactionId: string
	taxableAmount: number
	vatAmount: number
	description: string
}

/** One box of the position. */
interface PositionBox {
	id: string
	returnBox: string
	type: string
	taxRate: number
	base: number
	vat: number
	lineCount: number
	lines: PositionLine[]
}

/** GET /api/vat-position, under `data`. */
interface Position {
	administrationId: string
	period: string
	startDate: string
	endDate: string
	boxes: PositionBox[]
	totalVATCollected: number
	totalVATPaid: number
	payable: number
	direction: 'payable' | 'refundable' | 'nil'
}

/** One box row of a filed or current snapshot on a VatCorrection. */
interface SnapshotRow {
	returnBox: string
	totalVATAmount: number
	totalTaxableAmount: number
}

/** One per-box difference of a prepared correction. */
interface CategoryDelta {
	returnBox: string
	deltaVATAmount: number
	deltaTaxableAmount: number
}

/** The id of a register object, wherever OpenRegister put it. */
function idOf(object: RegisterObject): string {
	return String(object.id ?? object['@self']?.id ?? '')
}

/** Compare two euro amounts to the cent. */
function cents(value: number): number {
	return Math.round(value * 100)
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/** Run a detail page's header action: a button when it stands alone, else an entry of its Actions menu. */
async function runHeaderAction(page: Page, name: RegExp): Promise<void> {
	const button = page.getByRole('button', { name }).first()
	if (await button.isVisible().catch(() => false)) {
		await button.click()
		return
	}
	await page.getByTestId('cn-detail-page').getByRole('button', { name: /^(Actions|Acties)$/i }).first().click()
	await page.getByRole('menuitem', { name }).first().click()
}

/** The caller's active administration, or '' when there is none. */
async function activeAdministration(request: APIRequestContext): Promise<string> {
	const context = await request.get(`${API}/administrations/context`, { headers: HEADERS })
	if (!context.ok()) {
		return ''
	}
	return String((await context.json() as { activeAdministrationId?: string | null }).activeAdministrationId ?? '')
}

/** List register objects matching the given fields. */
async function list(request: APIRequestContext, schema: string, filters: Record<string, string>): Promise<RegisterObject[]> {
	const query = new URLSearchParams({ ...filters, _limit: '500' })
	const res = await request.get(`${OBJECTS}/${schema}?${query.toString()}`, { headers: HEADERS })
	expect(res.ok(), `list ${schema}: ${res.status()}`).toBe(true)
	return ((await res.json()) as { results?: RegisterObject[] }).results ?? []
}

/** The position of the period for the caller's active administration. */
async function position(request: APIRequestContext, administrationId: string): Promise<Position> {
	const res = await request.get(`${API}/vat-position?administrationId=${encodeURIComponent(administrationId)}&period=${PERIOD}`, { headers: HEADERS })
	expect(res.status(), await res.text()).toBe(200)
	return ((await res.json()) as { data: Position }).data
}

test.describe('tax-vat-position-and-suppletie: VAT position and supplementary return', () => {
	let administrationId = ''

	test.beforeAll(async ({ request }) => {
		administrationId = await activeAdministration(request)
	})

	test('the 2026-Q4 position gives base and VAT per box and the payable amount @spec REQ-VPS-001', async ({ request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		const body = await position(request, administrationId)

		expect(body.period).toBe(PERIOD)
		expect([body.startDate, body.endDate]).toEqual(['2026-10-01', '2026-12-31'])
		expect(cents(body.payable), 'payable is the VAT collected minus the VAT paid').toBe(cents(body.totalVATCollected) - cents(body.totalVATPaid))
		const direction = body.payable > 0 ? 'payable' : (body.payable < 0 ? 'refundable' : 'nil')
		expect(body.direction).toBe(direction)

		const box1a = body.boxes.find((box) => box.returnBox === '1a')
		if (box1a !== undefined) {
			// Rounded per line, so within a cent per line of 21% of the base.
			expect(Math.abs(cents(box1a.vat) - Math.round(cents(box1a.base) * 0.21)), 'box 1a is 21% of its base').toBeLessThanOrEqual(box1a.lineCount)
		}
		for (const box of body.boxes) {
			expect(box.id, 'each box row has an id').toBe(`${box.returnBox}-${box.type}-${box.taxRate}`)
		}
	})

	test('the VAT reports page shows the position tile and the boxes for the quarter @spec REQ-VPS-001', async ({ page }) => {
		await page.goto(`${APP}/vat-reports`)
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)
		for (const title of [/^(BTW position|Btw-positie)$/i, /^(BTW position per box|Btw-positie per rubriek)$/i]) {
			await expect(page.getByText(title).first(), `widget ${title}`).toBeVisible({ timeout: 20_000 })
		}
	})

	test('the lines behind a box add up to it and "Show lines" lists them with their entries @spec REQ-VPS-001', async ({ page, request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		const body = await position(request, administrationId)
		for (const box of body.boxes) {
			expect(box.lines.length, `${box.returnBox}: line count`).toBe(box.lineCount)
			expect(box.lines.reduce((sum, line) => sum + cents(line.vatAmount), 0), `${box.returnBox}: the lines make up its VAT`).toBe(cents(box.vat))
			expect(box.lines.reduce((sum, line) => sum + cents(line.taxableAmount), 0), `${box.returnBox}: the lines make up its base`).toBe(cents(box.base))
			for (const line of box.lines) {
				expect(line.glTransactionId, `${box.returnBox}: each line names its entry`).not.toBe('')
			}
		}

		const box = body.boxes.find((candidate) => candidate.lines.length > 0)
		test.skip(box === undefined, `nothing is posted in ${PERIOD} yet`)

		await page.goto(`${APP}/vat-reports`)
		await dismissOverlays(page)
		const row = page.locator('tr', { hasText: box!.returnBox }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		const show = row.getByRole('button', { name: /^(Show lines|Regels tonen)$/i })
		if (await show.isVisible().catch(() => false)) {
			await show.click()
		} else {
			await row.getByRole('button', { name: /actions|acties/i }).first().click()
			await page.getByRole('menuitem', { name: /^(Show lines|Regels tonen)$/i }).click()
		}
		const dialog = page.getByTestId('vat-position-lines-modal')
		await expect(dialog).toBeVisible({ timeout: 10_000 })
		await expect(dialog.getByTestId('vat-position-lines').locator('tbody tr')).toHaveCount(box!.lines.length)
	})

	test('"Check for corrections" on a filed return writes a correction only when the books drifted @spec REQ-VPS-002', async ({ page, request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		const filed = (await list(request, 'BtwAangifte', { administrationId }))
			.filter((candidate) => ['submitted', 'verified', 'filed'].includes(String(candidate.statusCode)))
		// The scenario's Q2 2026 return first, else any filed return.
		const vatReturn = filed.find((candidate) => Number(candidate.periodYear) === 2026 && Number(candidate.periodNumber) === 2) ?? filed[0]
		test.skip(vatReturn === undefined, 'the administration has no filed VAT return')
		const returnId = idOf(vatReturn!)
		const before = new Set((await list(request, 'VatCorrection', { originalVatReturnId: returnId })).map(idOf))

		await page.goto(`${APP}/vat-returns/${returnId}`)
		await dismissOverlays(page)
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 20_000 })
		await runHeaderAction(page, /^(Check for corrections|Controleren op correcties)$/i)

		// The page answers either way; give the written correction a moment to be listed.
		let created: RegisterObject | undefined
		for (let attempt = 0; attempt < 10 && created === undefined; attempt++) {
			await page.waitForTimeout(1_500)
			created = (await list(request, 'VatCorrection', { originalVatReturnId: returnId })).find((candidate) => !before.has(idOf(candidate)))
		}
		test.skip(created === undefined, 'the filed return matches the books: no drift to correct')

		try {
			expect(created!.state).toBe('draft')
			expect(String(created!.detectedAt ?? '')).not.toBe('')
			const filedRows = (created!.filedSnapshot as SnapshotRow[] | undefined) ?? []
			const currentRows = (created!.currentSnapshot as SnapshotRow[] | undefined) ?? []
			const boxes = new Set([...filedRows, ...currentRows].map((row) => row.returnBox))
			const differs = [...boxes].some((box) => {
				const vat = (rows: SnapshotRow[]) => rows.filter((row) => row.returnBox === box).reduce((sum, row) => sum + cents(row.totalVATAmount), 0)
				return vat(filedRows) !== vat(currentRows)
			})
			expect(differs, 'the correction records a box whose VAT differs from what was filed').toBe(true)

			await page.goto(`${APP}/belastingen/btw-correcties/${idOf(created!)}`)
			await dismissOverlays(page)
			await expect(page.getByTestId('cn-detail-page'), 'the correction shows on the corrections page').toBeVisible({ timeout: 20_000 })
		} finally {
			await request.delete(`${OBJECTS}/VatCorrection/${idOf(created!)}`, { headers: HEADERS }).catch(() => {})
		}
	})

	test('a prepared correction carries its per-box differences, the threshold decision and the staged posting @spec REQ-VPS-002', async ({ page, request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		const prepared = (await list(request, 'VatCorrection', { administrationId }))
			.find((candidate) => String(candidate.preparedAt ?? '') !== '')
		test.skip(prepared === undefined, 'the administration has no prepared supplementary return')
		const correctionId = idOf(prepared!)

		const deltas = (prepared!.categoryDeltas as CategoryDelta[] | undefined) ?? []
		expect(deltas.length, 'the differences per box').toBeGreaterThan(0)
		for (const delta of deltas) {
			expect(delta.returnBox).not.toBe('')
			expect(typeof delta.deltaVATAmount).toBe('number')
		}
		if (typeof prepared!.fiscalYearCorrectionTotal !== 'number') {
			expect(prepared!.thresholdExceeded, 'the EUR 1,000 threshold').toBe(Math.abs(Number(prepared!.correctionAmount)) >= 1000)
		}
		expect(String(prepared!.filingDeadline ?? ''), 'a filing deadline').not.toBe('')
		expect(String(prepared!.glCorrectionTransactionId ?? ''), 'the correction posting is staged').not.toBe('')

		// A prepared correction is not prepared twice.
		const again = await request.post(`${API}/vat-corrections/${correctionId}/prepare`, { headers: HEADERS })
		expect(again.status(), await again.text()).toBe(409)

		const download = await request.get(`${API}/vat-corrections/${correctionId}/supplementary-return`, { headers: HEADERS })
		expect(download.status(), await download.text()).toBe(200)
		const document = await download.json() as { boxes: unknown[], netCorrection: number, thresholdExceeded: boolean }
		expect(document.thresholdExceeded).toBe(Boolean(prepared!.thresholdExceeded))
		expect(cents(document.netCorrection)).toBe(cents(Number(prepared!.correctionAmount ?? prepared!.adjustmentAmount ?? 0)))

		await page.goto(`${APP}/belastingen/btw-correcties/${correctionId}`)
		await dismissOverlays(page)
		await expect(page.getByText(/^(Above EUR 1,000|Boven € 1\.000)$/i).first()).toBeVisible({ timeout: 20_000 })
	})

	// Not run here: the scenario closes September 2026, which cannot be undone on a shared instance; PeriodClosedDriftListenerTest covers the listener.
	test.fixme('closing September 2026 lists the Q2 correction without anyone running a check @spec REQ-VPS-003', async () => {})
})
