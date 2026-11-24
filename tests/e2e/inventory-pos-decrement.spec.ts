/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a pipelinq POS sale that settles decrements shillinq's stock
 * and posts COGS (inventory-pos-decrement, the open live check of §3).
 *
 * The live pass on an instance with pipelinq and shillinq:
 *
 *   1. shillinq stock of SKU-1001 with 10 on hand in an administration of the
 *      test's own (a unique id with a membership for the suite's
 *      administrator), so the sale never touches seed stock;
 *   2. a pipelinq POS sale POS-2026-0001 of 3 units of SKU-1001 settles:
 *      pipelinq's `PosTransactionService::settleTransaction()` dispatches
 *      `OCA\Pipelinq\Event\PosStockMovedEvent` in-process, and shillinq's
 *      PosStockDecrementListener issues the line through
 *      SalesDispatchStockIssueService with the synthetic delivery `pos-{posTxnId}`;
 *   3. the stock reads 7 on hand, one StockMove of 3 units carries the
 *      reference `shillinq://delivery/pos-POS-2026-0001#line-...`, and
 *      CogsPosterService has written a GL transaction for that move;
 *   4. the same event again changes nothing; a line whose SKU shillinq does
 *      not stock is raised as a notification to the administrators.
 *
 * Step 2 has no reachable surface from this repo: no pipelinq route or
 * transition that runs `settleTransaction()` is named here, and the event is a
 * typed PHP event, not a CloudEvent saved in a register a test could write. So
 * the sale and everything that follows it are test.fixme with that reason; the
 * stock precondition runs, and the assertions after the sale are written out
 * for when the pipelinq route is confirmed. The spec skips, and says why,
 * when pipelinq is not installed. Every object written here is removed
 * afterwards.
 *
 * The requirement carries no REQ id, so the test titles name its anchor.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
 * @spec openspec/changes/inventory-pos-decrement/tasks.md
 *
 * @e2e pos-stock-decrement::a-pos-sale-decrements-the-matching-inventory-item-and-posts-cogs
 * @e2e pos-stock-decrement::a-redelivered-event-does-not-double-decrement
 * @e2e pos-stock-decrement::an-unmatched-product-line-is-audited-not-dropped
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { money, OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMINISTRATION = `${UNIQUE_PREFIX}-pos`
const SKU = 'SKU-1001'
const POS_TXN = 'POS-2026-0001'
const NO_SALE = 'a pipelinq POS sale settles in PosTransactionService::settleTransaction(), which dispatches PosStockMovedEvent in-process; no pipelinq route or transition that runs it is named in this repo'
const NEEDED = ['AdministrationMembership', 'InventoryStock', 'StockMove', 'GLTransaction']

/**
 * Whether an app is enabled: listed among the enabled apps, or, when the OCS
 * list cannot be read, its app route answering.
 *
 * @param api   The administrator's request context.
 * @param appId The app id.
 * @return True when the app is there.
 */
async function appInstalled(api: APIRequestContext, appId: string): Promise<boolean> {
	const apps = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json', {
		headers: { ...HEADERS, Accept: 'application/json' },
	})
	if (apps.ok()) {
		return (await apps.text()).includes(`"${appId}"`)
	}
	const route = await api.get(`/index.php/apps/${appId}/`, { maxRedirects: 0 })
	return route.status() === 200
}

test.describe('inventory-pos-decrement: a pipelinq POS sale moves shillinq stock', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let pipelinq = false

	/**
	 * The shillinq objects of a schema whose fields equal the filters.
	 *
	 * @param schema  The schema slug.
	 * @param filters Field filters.
	 * @return The objects.
	 */
	async function list(schema: string, filters: Record<string, string>): Promise<Array<Record<string, unknown>>> {
		const query = new URLSearchParams({ ...filters, _limit: '100' })
		const res = await api.get(`${OBJECTS}/${schema}?${query.toString()}`, { headers: HEADERS })
		expect(res.ok(), `list ${schema}: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		const rows = (body.results ?? body.items ?? []) as Array<Record<string, unknown>>
		return rows.filter((row) => Object.entries(filters).every(([field, value]) => String(row[field] ?? '') === value))
	}

	/**
	 * The stock moves the POS sale issued.
	 *
	 * @return The moves whose reference names the sale's synthetic delivery.
	 */
	async function saleMoves(): Promise<Array<Record<string, unknown>>> {
		return (await list('StockMove', { administrationId: ADMINISTRATION }))
			.filter((move) => String(move.referenceDocumentUri ?? '').startsWith(`shillinq://delivery/pos-${POS_TXN}#`))
	}

	/**
	 * The stock row of SKU-1001 in the test's administration.
	 *
	 * @return The row.
	 */
	async function stock(): Promise<Record<string, unknown>> {
		const [row] = await list('InventoryStock', { administrationId: ADMINISTRATION, productSku: SKU })
		expect(row, `the ${SKU} stock row`).toBeTruthy()
		return row
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
		pipelinq = await appInstalled(api, 'pipelinq')
	})

	test.afterAll(async () => {
		await fx?.cleanup()
		await api?.dispose()
	})

	test('a POS sale of 3 units of SKU-1001 leaves 7 on hand and posts COGS for the 3 @spec pos-stock-decrement#requirement-a-completed-pipelinq-pos-sale-shall-decrement-shillinq-stock-and-post-cogs', async () => {
		test.skip(!pipelinq, 'pipelinq is not installed: the sale is rung up and settled in pipelinq\'s POS')
		const missing = await fx.missingSchema(NEEDED)
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		// 1. Ten on hand in the test's administration.
		await fx.create('AdministrationMembership', {
			userId: ADMIN_USER,
			administrationId: ADMINISTRATION,
			role: 'controller',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		await fx.create('InventoryStock', {
			productId: `${UNIQUE_PREFIX}-product-1001`,
			productSku: SKU,
			locationCode: 'WINKEL',
			locationName: 'Winkel',
			quantityOnHand: 10,
			quantityReserved: 0,
			quantityAvailable: 10,
			unitCost: 4,
			status: 'active',
			administrationId: ADMINISTRATION,
		})
		expect(Number((await stock()).quantityOnHand)).toBe(10)

		// 2. The sale settles in pipelinq.
		test.fixme(true, NO_SALE)

		// 3. Decrement and COGS, once the sale can be settled from here.
		await expect.poll(async () => Number((await stock()).quantityOnHand), { timeout: 30_000 }).toBe(7)
		const moves = await saleMoves()
		expect(moves, 'one stock move for the one line').toHaveLength(1)
		expect(money(Number(moves[0].quantity))).toBe(3)
		const moveId = String((moves[0]['@self'] as Record<string, unknown> | undefined)?.id ?? moves[0].id ?? '')
		const cogs = await list('GLTransaction', { administrationId: ADMINISTRATION, sourceReference: moveId })
		expect(cogs, 'one COGS transaction for the move').toHaveLength(1)
		test.info().annotations.push({ type: 'COGS transaction', description: String(cogs[0].transactionNumber ?? '') })
	})

	test('the same PosStockMovedEvent delivered again changes nothing @spec pos-stock-decrement#requirement-a-completed-pipelinq-pos-sale-shall-decrement-shillinq-stock-and-post-cogs', async () => {
		test.skip(!pipelinq, 'pipelinq is not installed: the sale is rung up and settled in pipelinq\'s POS')
		// A redelivery is pipelinq dispatching the event a second time for the
		// same posTxnId; only pipelinq can do that, in-process.
		test.fixme(true, NO_SALE)
		expect(Number((await stock()).quantityOnHand)).toBe(7)
		expect(await saleMoves()).toHaveLength(1)
	})

	test('a sold line whose SKU shillinq does not stock is raised to the administrators @spec pos-stock-decrement#requirement-a-completed-pipelinq-pos-sale-shall-decrement-shillinq-stock-and-post-cogs', async () => {
		test.skip(!pipelinq, 'pipelinq is not installed: the sale is rung up and settled in pipelinq\'s POS')
		// The audit surface is PosStockUnmatchedLineNotifier's notification to
		// the admin group, readable through Nextcloud's notifications API once a
		// sale with an unknown SKU can be settled.
		test.fixme(true, NO_SALE)
	})
})
