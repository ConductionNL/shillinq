/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a pipelinq POS sale that settles decrements shillinq's stock
 * and posts COGS (inventory-pos-decrement, the open live check of §3).
 *
 * The live pass on an instance with pipelinq and shillinq:
 *
 *   1. an administration of the test's own (a unique code with a membership
 *      for the suite's administrator) is made the administrator's active
 *      administration, because pipelinq stamps a sale with the active
 *      administration shillinq reports; 10 of SKU-1001 on hand at WINKEL with
 *      a moving-average valuation of EUR 4, and the COGS and inventory
 *      accounts set (app config `cogs_account`, `inventory_account`);
 *   2. a pipelinq sale of 3 units of SKU-1001 is rung up through pipelinq's own
 *      routes: product, sale, line, cash tender, then
 *      `POST /apps/pipelinq/api/pos-transactions/{id}/confirm` and `/settle`.
 *      Settling dispatches `OCA\Pipelinq\Event\PosStockMovedEvent`, and
 *      shillinq's PosStockDecrementListener issues the line through
 *      SalesDispatchStockIssueService with the synthetic delivery
 *      `pos-{sale uuid}`;
 *   3. the stock reads 7 on hand, one posted StockMove of 3 units carries the
 *      reference `shillinq://delivery/pos-{sale uuid}#line-0`, and
 *      CogsPosterService has written the COGS GL transaction for that move;
 *   4. a sold line whose SKU shillinq does not stock is raised as a
 *      notification to the administrators.
 *
 * A redelivery of the same event stays test.fixme: pipelinq dispatches it once
 * per settle and refuses a second settle, so nothing outside pipelinq can send
 * it twice (the unit suite covers it). The spec skips, and says why, when
 * pipelinq is not installed. Everything written here is removed afterwards and
 * the active administration and account settings are restored.
 *
 * On an OpenRegister without openregister#11277 pipelinq's confirm finds no
 * lines (it scopes its read under `filters.@self`) and refuses every sale;
 * the confirm assertion names that.
 *
 * Written 24 Nov 2026; sale legs added and run live 25 Nov 2026 on a sandbox
 * Nextcloud 34 + OpenRegister main (with #11277) + shillinq and pipelinq
 * development.
 *
 * The requirement carries no REQ id, so the test titles name its anchor.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/archive/2026-11-25-inventory-pos-decrement/specs/pos-stock-decrement/spec.md
 * @spec openspec/changes/archive/2026-11-25-inventory-pos-decrement/tasks.md
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
const POS_TXN = `${UNIQUE_PREFIX}-POS-0001`
const NO_REDELIVERY = 'pipelinq dispatches PosStockMovedEvent once per settle and refuses a second settle, so a redelivery cannot be caused from outside pipelinq'
const PIPELINQ_OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'
const PIPELINQ_API = '/index.php/apps/pipelinq/api'
const SHILLINQ_API = '/index.php/apps/shillinq/api'
const APP_CONFIG = '/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/shillinq'
const NEEDED = ['Administration', 'AdministrationMembership', 'InventoryStock', 'InventoryValuation', 'StockMove', 'GLTransaction']

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
	/** The administrator's active administration before the run, restored afterwards. */
	let activeBefore = ''
	/** The account settings before the run, restored afterwards. */
	const accountsBefore: Record<string, string> = {}
	/** The pipelinq objects written, removed afterwards. */
	const pipelinqObjects: Array<[string, string]> = []
	/** Whether the test's administration and stock exist. */
	let ready = false

	/**
	 * The request token of the administrator's session.
	 *
	 * @return The token.
	 */
	async function requestToken(): Promise<string> {
		const res = await api.get('/csrftoken')
		expect(res.ok(), `csrftoken: HTTP ${res.status()}`).toBeTruthy()
		return String((await res.json()).token ?? '')
	}

	/**
	 * The headers of a write in the administrator's session.
	 *
	 * @return The headers.
	 */
	async function writeHeaders(): Promise<Record<string, string>> {
		return { ...HEADERS, requesttoken: await requestToken(), 'Content-Type': 'application/json' }
	}

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
	 * The id of an OpenRegister object.
	 *
	 * @param row The object.
	 * @return Its id.
	 */
	function idOf(row: Record<string, unknown>): string {
		return String((row['@self'] as Record<string, unknown> | undefined)?.id ?? row.id ?? '')
	}

	/**
	 * Create a pipelinq object; it is removed afterwards.
	 *
	 * @param schema The pipelinq schema slug.
	 * @param data   The object.
	 * @return Its id.
	 */
	async function pipelinqCreate(schema: string, data: Record<string, unknown>): Promise<string> {
		const res = await api.post(`${PIPELINQ_OBJECTS}/${schema}`, { headers: await writeHeaders(), data })
		expect(res.ok(), `create pipelinq ${schema}: HTTP ${res.status()} ${await res.text()}`).toBeTruthy()
		const id = idOf(await res.json())
		pipelinqObjects.push([schema, id])
		return id
	}

	/**
	 * Ring up and settle a pipelinq sale of one line.
	 *
	 * @param reference The sale's reference.
	 * @param sku       The product's SKU.
	 * @param quantity  The quantity sold.
	 * @return The sale's uuid.
	 */
	async function settleSale(reference: string, sku: string, quantity: number): Promise<string> {
		const total = quantity * 10
		const product = await pipelinqCreate('product', { name: `Artikel ${sku}`, sku, unit: 'stuk', unitPrice: 10, type: 'product', status: 'active' })
		const sale = await pipelinqCreate('posTransaction', {
			cashier: ADMIN_USER, status: 'draft', reference, priceMode: 'incl', subtotal: total, total, totalTax: 0, discountTotal: 0,
		})
		await pipelinqCreate('posTransactionLine', {
			transaction: sale, product, description: `Artikel ${sku}`, quantity, unitPrice: 10, discount: 0, taxRate: 0, taxAmount: 0, lineTotal: total, sortOrder: 1,
		})
		const tenderType = await pipelinqCreate('posTenderType', { name: 'Contant', code: `cash-${Math.random().toString(36).slice(2, 10)}`, glAccount: '1000', isActive: true })
		await pipelinqCreate('posTender', { transaction: sale, tenderType, amount: total, glAccount: '1000' })

		const confirmed = await api.post(`${PIPELINQ_API}/pos-transactions/${sale}/confirm`, { headers: await writeHeaders() })
		expect(confirmed.ok(), `confirm ${reference}: HTTP ${confirmed.status()} ${await confirmed.text()} (an OpenRegister without openregister#11277 finds no lines here)`).toBeTruthy()
		const settled = await api.post(`${PIPELINQ_API}/pos-transactions/${sale}/settle`, { headers: await writeHeaders() })
		expect(settled.ok(), `settle ${reference}: HTTP ${settled.status()} ${await settled.text()}`).toBeTruthy()
		return sale
	}

	/**
	 * The stock moves a sale issued.
	 *
	 * @param sale The sale's uuid.
	 * @return The moves whose reference names the sale's synthetic delivery.
	 */
	async function saleMoves(sale: string): Promise<Array<Record<string, unknown>>> {
		return (await list('StockMove', { administrationId: ADMINISTRATION }))
			.filter((move) => String(move.referenceDocumentUri ?? '').startsWith(`shillinq://delivery/pos-${sale}#`))
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

	/**
	 * Set one of shillinq's app config values.
	 *
	 * @param key   The key.
	 * @param value The value.
	 * @return Nothing.
	 */
	async function setAppConfig(key: string, value: string): Promise<void> {
		const res = await api.post(`${APP_CONFIG}/${key}`, { headers: { ...HEADERS, requesttoken: await requestToken() }, form: { value } })
		expect(res.ok(), `set ${key}: HTTP ${res.status()}`).toBeTruthy()
	}

	/**
	 * The test's administration with ten of SKU-1001 at EUR 4, made active.
	 *
	 * @return Nothing.
	 */
	async function administrationWithStock(): Promise<void> {
		if (ready) {
			return
		}
		await fx.create('Administration', { administrationCode: ADMINISTRATION, name: `POS ${UNIQUE_PREFIX}`, legalForm: 'bv', status: 'actief', fiscalYearStartMonth: 1, fiscalYearStartDay: 1 })
		await fx.create('AdministrationMembership', {
			userId: ADMIN_USER,
			administrationId: ADMINISTRATION,
			role: 'controller',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		await fx.create('InventoryStock', {
			productId: SKU,
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
		await fx.create('InventoryValuation', {
			productId: SKU, quantity: 10, unitCost: 4, totalValue: 40, valuationMethod: 'average', date: '2026-01-01T00:00:00Z', warehouse: 'WINKEL', status: 'active', administrationId: ADMINISTRATION,
		})

		const context = await api.get(`${SHILLINQ_API}/administrations/context`, { headers: HEADERS })
		expect(context.ok(), `administration context: HTTP ${context.status()}`).toBeTruthy()
		activeBefore = String((await context.json()).activeAdministrationId ?? '')
		const switched = await api.post(`${SHILLINQ_API}/administrations/switch`, { headers: await writeHeaders(), data: { administrationId: ADMINISTRATION } })
		expect(switched.ok(), `switch to ${ADMINISTRATION}: HTTP ${switched.status()} ${await switched.text()}`).toBeTruthy()
		for (const [key, value] of [['cogs_account', '7000'], ['inventory_account', '3000']]) {
			const before = await api.get(`${APP_CONFIG}/${key}?format=json`, { headers: HEADERS })
			accountsBefore[key] = before.ok() ? String((await before.json())?.ocs?.data?.data ?? '') : ''
			await setAppConfig(key, value)
		}
		ready = true
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
		pipelinq = await appInstalled(api, 'pipelinq')
	})

	test.afterAll(async () => {
		if (api !== undefined) {
			for (const [schema, id] of pipelinqObjects.reverse()) {
				await api.delete(`${PIPELINQ_OBJECTS}/${schema}/${id}`, { headers: await writeHeaders() }).catch(() => undefined)
			}
			if (ready) {
				if (activeBefore !== '') {
					await api.post(`${SHILLINQ_API}/administrations/switch`, { headers: await writeHeaders(), data: { administrationId: activeBefore } }).catch(() => undefined)
				}
				for (const [key, value] of Object.entries(accountsBefore)) {
					await setAppConfig(key, value).catch(() => undefined)
				}
			}
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('a POS sale of 3 units of SKU-1001 leaves 7 on hand and posts COGS for the 3 @spec pos-stock-decrement#requirement-a-completed-pipelinq-pos-sale-shall-decrement-shillinq-stock-and-post-cogs', async () => {
		test.skip(!pipelinq, 'pipelinq is not installed: the sale is rung up and settled in pipelinq\'s POS')
		const missing = await fx.missingSchema(NEEDED)
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		// 1. Ten on hand in the test's administration, made active.
		await administrationWithStock()
		expect(Number((await stock()).quantityOnHand)).toBe(10)

		// 2. The sale settles in pipelinq.
		const sale = await settleSale(POS_TXN, SKU, 3)

		// 3. Decrement, the posted move and its COGS.
		await expect.poll(async () => Number((await stock()).quantityOnHand), { timeout: 30_000 }).toBe(7)
		const moves = await saleMoves(sale)
		expect(moves, 'one stock move for the one line').toHaveLength(1)
		expect(money(Number(moves[0].quantity))).toBe(3)
		expect(String(moves[0].lifecycleState)).toBe('posted')
		const cogs = await list('GLTransaction', { administrationId: ADMINISTRATION, sourceReference: idOf(moves[0]) })
		expect(cogs, 'one COGS transaction for the move').toHaveLength(1)
		test.info().annotations.push({ type: 'COGS transaction', description: String(cogs[0].transactionNumber ?? '') })
	})

	test('the same PosStockMovedEvent delivered again changes nothing @spec pos-stock-decrement#requirement-a-completed-pipelinq-pos-sale-shall-decrement-shillinq-stock-and-post-cogs', async () => {
		test.skip(!pipelinq, 'pipelinq is not installed: the sale is rung up and settled in pipelinq\'s POS')
		test.fixme(true, NO_REDELIVERY)
	})

	test('a sold line whose SKU shillinq does not stock is raised to the administrators @spec pos-stock-decrement#requirement-a-completed-pipelinq-pos-sale-shall-decrement-shillinq-stock-and-post-cogs', async () => {
		test.skip(!pipelinq, 'pipelinq is not installed: the sale is rung up and settled in pipelinq\'s POS')
		const missing = await fx.missingSchema(NEEDED)
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		await administrationWithStock()

		const onHandBefore = Number((await stock()).quantityOnHand)
		const unknownSku = `${UNIQUE_PREFIX}-NOT-STOCKED`
		const sale = await settleSale(`${POS_TXN}-X`, unknownSku, 1)

		// PosStockUnmatchedLineNotifier: "POS sale <uuid>: product <sku> could not be matched to inventory".
		await expect.poll(async () => {
			const res = await api.get('/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', { headers: HEADERS })
			if (!res.ok()) {
				return false
			}
			const rows = ((await res.json())?.ocs?.data ?? []) as Array<Record<string, unknown>>
			return rows.some((row) => row.app === 'shillinq' && String(row.subject ?? '').includes(sale) && String(row.subject ?? '').includes(unknownSku))
		}, { timeout: 30_000, message: 'the administrators get a notification naming the sale and the SKU' }).toBe(true)
		expect(await saleMoves(sale), 'no stock move for a SKU shillinq does not stock').toHaveLength(0)
		expect(Number((await stock()).quantityOnHand), 'the stocked SKU is untouched').toBe(onHandBefore)
	})
})
