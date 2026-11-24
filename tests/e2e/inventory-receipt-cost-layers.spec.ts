/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: goods receipts add their cost layers, issues are costed
 * from them, and the stock valuation has a page (inventory-receipt-cost-layers
 * task 3.2, REQ-IRCL-001, REQ-IRCL-002, REQ-IRCL-003, REQ-IRCL-004).
 *
 *   1. flour is received: every posted receipt move is locked and carries its
 *      unit cost, and on 2026-09-02 the design's Tarwebloem 25 kg stands at
 *      40 bags worth EUR 740 (REQ-IRCL-001);
 *   2. selling 50 bags across two layers: the stock value drops by EUR 932.50
 *      over the 2026-09-20 issue, which is 40 x 18.50 + 10 x 19.25
 *      (REQ-IRCL-002);
 *   3. the backfill lists an undercosted issue: test.fixme, the repair's list
 *      is the upgrade output and the log, which no page or endpoint shows
 *      (REQ-IRCL-003);
 *   4. a controller reads the September valuation: on 2026-09-30 the report
 *      shows 30 bags worth EUR 577.50, its lines add up to its total, and the
 *      Stock valuation page shows the table for a typed date (REQ-IRCL-004).
 *
 * The report is read for the caller's active administration (the endpoint
 * falls back to it when none is named). The figures of the design's
 * Bakkerij Jansen seed are asserted only when that stock position exists,
 * and skipped otherwise; the totals invariant holds on any data. The GR/IR
 * entry of a receipt is posted by GRIRClearingListener, which this change
 * did not touch (tasks.md 1.1), and is not asserted here. The caller is the
 * suite's administrator (globalSetup's storage state). The spec writes
 * nothing.
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md
 * @spec openspec/changes/archive/2026-11-24-inventory-receipt-cost-layers/tasks.md#task-3.2
 *
 * @e2e inventory-valuation-fifo-avg::flour-is-received
 * @e2e inventory-valuation-fifo-avg::selling-50-bags-across-two-layers
 * @e2e inventory-valuation-fifo-avg::the-backfill-lists-an-undercosted-issue
 * @e2e inventory-valuation-fifo-avg::a-controller-reads-the-september-valuation
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api/inventory/valuation-report'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** The design's FIFO product. */
const FLOUR = /tarwebloem/i

/** One valued stock position. */
interface ValuationLine {
	sku: string
	warehouse: string
	method: string
	quantity: number
	unitCost: number
	totalValue: number
}

/** The part of the report answer this spec reads. */
interface ValuationAnswer {
	valuation: {
		asOfDate: string
		totalValue: number
		totalQuantity: number
		lines: ValuationLine[]
	}
}

/** A stock move, as far as this spec reads it. */
interface StockMove {
	movementType: string
	lifecycleState: string
	locked?: boolean
	unitCost?: number
	itemId: string
}

/** Compare two euro amounts to the cent. */
function cents(value: number): number {
	return Math.round(value * 100)
}

/**
 * The valuation of the active administration on a date, or null when the
 * caller has no administration to read.
 *
 * @param request The request context.
 * @param asOf    The date, yyyy-mm-dd.
 * @return The valuation, or null.
 */
async function valuation(request: APIRequestContext, asOf: string): Promise<ValuationAnswer['valuation'] | null> {
	const res = await request.get(`${API}?as_of=${asOf}`, { headers: HEADERS })
	if (res.status() === 400 || res.status() === 404) {
		return null
	}
	expect(res.status(), await res.text()).toBe(200)
	return ((await res.json()) as ValuationAnswer).valuation
}

/**
 * The flour's stock positions on a date, summed over locations.
 *
 * @param lines The report lines.
 * @return The quantity and value, or null when there is no flour.
 */
function flour(lines: ValuationLine[]): { quantity: number, value: number } | null {
	const rows = lines.filter((line) => FLOUR.test(line.sku))
	if (rows.length === 0) {
		return null
	}
	return {
		quantity: rows.reduce((sum, row) => sum + row.quantity, 0),
		value: rows.reduce((sum, row) => sum + row.totalValue, 0),
	}
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('inventory-receipt-cost-layers: receipt layers and the stock valuation', () => {
	test('every posted receipt move is locked and carries its unit cost @spec REQ-IRCL-001', async ({ request }) => {
		const res = await request.get(`${OBJECTS}/StockMove?movementType=receipt&lifecycleState=posted&_limit=50`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const moves = ((await res.json()).results ?? []) as StockMove[]
		test.skip(moves.length === 0, 'the instance holds no posted receipt stock move')

		for (const move of moves) {
			expect(move.movementType).toBe('receipt')
			expect(move.locked, `${move.itemId}: the post transition locks the move`).toBe(true)
			expect(typeof move.unitCost, `${move.itemId}: the PO line's unit cost`).toBe('number')
		}
	})

	test('the first flour receipt shows 40 bags worth EUR 740 @spec REQ-IRCL-001', async ({ request }) => {
		const answer = await valuation(request, '2026-09-02')
		test.skip(answer === null, 'the caller has no active administration with inventory')
		const position = flour(answer!.lines)
		test.skip(position === null, 'no Tarwebloem 25 kg stock position (the design\'s Bakkerij Jansen seed) in the active administration')

		expect(position!.quantity).toBe(40)
		expect(cents(position!.value)).toBe(74_000)
	})

	test('the 50-bag issue is costed EUR 932.50 from the two receipt layers @spec REQ-IRCL-002', async ({ request }) => {
		const before = await valuation(request, '2026-09-19')
		const after = await valuation(request, '2026-09-20')
		test.skip(before === null || after === null, 'the caller has no active administration with inventory')
		const held = flour(before!.lines)
		const left = flour(after!.lines)
		test.skip(held === null || left === null, 'no Tarwebloem 25 kg stock position (the design\'s Bakkerij Jansen seed) in the active administration')

		// Both layers on hand before the issue: 40 at 18.50 and 40 at 19.25.
		expect(held!.quantity).toBe(80)
		expect(cents(held!.value)).toBe(151_000)
		expect(held!.quantity - left!.quantity, 'the issue takes 50 bags').toBe(50)
		expect(cents(held!.value) - cents(left!.value), 'the cost of the issue').toBe(93_250)
	})

	// Missing: a page or endpoint for BackfillReceiptCostLayers' list; it is the upgrade output and the log only (tasks.md 2.1).
	test.fixme('the backfill lists an undercosted issue with its old and its layer-based cost @spec REQ-IRCL-003', async () => {})

	test('the September valuation shows 30 bags worth EUR 577.50 and adds up @spec REQ-IRCL-004', async ({ request }) => {
		const answer = await valuation(request, '2026-09-30')
		test.skip(answer === null, 'the caller has no active administration with inventory')

		expect(answer!.asOfDate.slice(0, 10)).toBe('2026-09-30')
		const sum = answer!.lines.reduce((total, line) => total + cents(line.totalValue), 0)
		expect(sum, 'the lines add up to the stock value').toBe(cents(answer!.totalValue))
		for (const line of answer!.lines) {
			// unitCost is rounded to four decimals, so allow that on the product.
			expect(Math.abs(line.quantity * line.unitCost - line.totalValue), `${line.sku} at ${line.warehouse}`)
				.toBeLessThanOrEqual(0.01 + Math.abs(line.quantity) * 0.00005)
		}

		const position = flour(answer!.lines)
		test.skip(position === null, 'no Tarwebloem 25 kg stock position (the design\'s Bakkerij Jansen seed) in the active administration')
		expect(position!.quantity).toBe(30)
		expect(cents(position!.value)).toBe(57_750)
	})

	test('the Stock valuation page shows the value per product and location on a typed date @spec REQ-IRCL-004', async ({ page }) => {
		await page.goto(`${APP}/inventory/valuation-report`)
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)

		await expect(page.getByText(/^(Stock valuation|Voorraadwaardering)$/i).first()).toBeVisible({ timeout: 20_000 })
		const input = page.locator('[data-testid="cn-workspace-filter-input"]').first()
		await expect(input).toBeVisible({ timeout: 20_000 })
		await input.fill('2026-09-30')
		await page.locator('[data-testid="cn-workspace-filter-submit"]').first().click()

		await expect(page.getByText(/Value per product and location|Waarde per product en locatie/i).first()).toBeVisible({ timeout: 20_000 })
		const rows = page.locator('table tbody tr')
		const empty = page.getByText(/No stock on this date\.|Geen voorraad op deze datum\./i).first()
		await expect.poll(async () => (await rows.count()) > 0 || await empty.isVisible().catch(() => false), { timeout: 20_000 })
			.toBe(true)

		const flourRow = rows.filter({ hasText: FLOUR }).first()
		if (await flourRow.isVisible().catch(() => false)) {
			await expect(flourRow).toContainText(/577[.,]50/)
			await expect(flourRow).toContainText(/\b30\b/)
		}
	})
})
