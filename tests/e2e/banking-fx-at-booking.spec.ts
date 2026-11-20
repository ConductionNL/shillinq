/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Foreign-currency posting at the booking date's rate, end to end over HTTP.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * FxAtBookingResolverTest and the MaterialiseGlTransactionAction tests cover
 * the precedence, the age rule and the rounding against doubles. What they
 * cannot see is the real `postDirect` transition running the real handler
 * on real objects: that the GL line of a Sunday USD entry carries Friday's
 * rate, that a stale GBP rate stops the transition and leaves the entry a
 * draft, and that a typed rate wins and is recorded as manual.
 *
 * SET-UP
 * ------
 * The rates are created by this file, scoped to the administration the admin
 * works in, so a feed or other rates on the instance cannot win (a scoped
 * rate beats a shared one, design.md D1). A scoped rate for the same pair
 * dated between the scenario's dates, left by someone else, would change the
 * outcome; the scenario dates (2026-09) keep that unlikely.
 *
 * @spec openspec/changes/banking-fx-at-booking/specs/banking-fx-at-booking/spec.md
 * @e2e banking-fx-at-booking/requirement-a-foreign-currency-line-is-booked-at-the-rate-of-its-date-req-bfx-001/a-bookkeeper-posts-a-usd-supplier-invoice-on-a-sunday
 * @e2e banking-fx-at-booking/requirement-no-foreign-currency-line-posts-without-a-usable-rate-req-bfx-002/a-gbp-entry-with-a-stale-rate-is-refused
 * @e2e banking-fx-at-booking/requirement-a-rate-typed-on-the-entry-wins-and-is-recorded-as-manual-req-bfx-003/a-bookkeeper-books-at-the-rate-agreed-with-the-bank
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const HEADERS = { 'OCS-APIRequest': 'true' }
const RUN = `${Date.now()}`

/**
 * The administration the admin works in, from shillinq's settings.
 *
 * @param request The admin's request context.
 * @return The administration id, or '' when none is configured.
 */
async function administration(request: APIRequestContext): Promise<string> {
	const response = await request.get('/index.php/apps/shillinq/api/settings', { headers: HEADERS })
	if (!response.ok()) {
		return ''
	}
	return String((await response.json()).administration_id ?? '')
}

/**
 * Create one object and answer it.
 *
 * @param request The request context.
 * @param schema  The schema slug.
 * @param data    The object.
 * @return The saved object.
 */
async function create(request: APIRequestContext, schema: string, data: object): Promise<Record<string, unknown>> {
	const response = await request.post(`${OBJECTS}/shillinq/${schema}`, { headers: HEADERS, data })
	expect(response.ok(), `create ${schema}: ${await response.text()}`).toBeTruthy()
	return response.json()
}

/**
 * The id OpenRegister answers an object under.
 *
 * @param object The object.
 * @return Its id.
 */
function idOf(object: Record<string, unknown>): string {
	const self = (object['@self'] ?? {}) as Record<string, unknown>
	return String(object.id ?? self.id ?? object.uuid ?? '')
}

/**
 * A journal entry in a foreign currency, debit 4340, credit 1600.
 *
 * @param administrationId The administration.
 * @param currency         The entry currency.
 * @param amount           The amount in that currency.
 * @param typed            An optional typed rate and reason on both lines.
 * @return The entry.
 */
function entry(administrationId: string, currency: string, amount: number, typed: { fxRate?: number, fxRateReason?: string } = {}): object {
	return {
		journalNumber: `E2E-FX-${currency}-${RUN}-${amount}`,
		entryDate: '2026-09-27',
		description: `e2e ${currency} entry on a Sunday`,
		currency,
		journalType: 'manual',
		approvalState: 'not-required',
		state: 'draft',
		administrationId,
		lines: [
			{ accountNumber: '4340', side: 'debit', amount, ...typed },
			{ accountNumber: '1600', side: 'credit', amount, ...typed },
		],
	}
}

/**
 * Run postDirect on an entry.
 *
 * @param request The request context.
 * @param id      The entry id.
 * @return The transition answer.
 */
async function post(request: APIRequestContext, id: string) {
	return request.post(`${OBJECTS}/${id}/transition`, { headers: HEADERS, data: { action: 'postDirect' } })
}

/**
 * The GL lines of a posted entry.
 *
 * @param request The request context.
 * @param entryId The entry id.
 * @return The lines.
 */
async function glLines(request: APIRequestContext, entryId: string): Promise<Array<Record<string, unknown>>> {
	const saved = await (await request.get(`${OBJECTS}/shillinq/JournalEntry/${entryId}`, { headers: HEADERS })).json()
	const transactionId = String(saved.glTransactionId ?? '')
	expect(transactionId, 'the posted entry names its GL transaction').not.toBe('')
	const response = await request.get(`${OBJECTS}/shillinq/GLLine?transactionId=${encodeURIComponent(transactionId)}&_limit=20`, { headers: HEADERS })
	const body = await response.json()
	return (body.results ?? body.items ?? []) as Array<Record<string, unknown>>
}

test.describe('banking-fx-at-booking — posting at the rate of the booking date', () => {
	let administrationId = ''

	test.beforeAll(async ({ request }) => {
		administrationId = await administration(request)
		if (administrationId === '') {
			return
		}
		await create(request, 'FxRate', { transactionCurrency: 'USD', baseCurrency: 'EUR', date: '2026-09-25', source: 'manual', rate: 0.915, manualOverrideReason: `e2e ${RUN}`, administrationId })
		await create(request, 'FxRate', { transactionCurrency: 'GBP', baseCurrency: 'EUR', date: '2026-09-18', source: 'manual', rate: 1.152, manualOverrideReason: `e2e ${RUN}`, administrationId })
	})

	test('a Sunday USD entry books at Friday\'s rate', async ({ request }) => {
		test.skip(administrationId === '', 'no administration is configured for the admin')
		const saved = await create(request, 'JournalEntry', entry(administrationId, 'USD', 1000))

		const response = await post(request, idOf(saved))
		expect(response.ok(), await response.text()).toBeTruthy()

		const debit = (await glLines(request, idOf(saved))).find((line) => line.side === 'debit')
		expect(debit, 'a debit GL line').toBeTruthy()
		expect(Number(debit!.amount)).toBeCloseTo(915, 2)
		expect(Number(debit!.fxRate)).toBeCloseTo(0.915, 4)
		expect(debit!.fxRateSource).toBe('manual')
		expect(String(debit!.fxRateDate).slice(0, 10)).toBe('2026-09-25')
	})

	test('a GBP entry with only a stale rate is refused and stays a draft', async ({ request }) => {
		test.skip(administrationId === '', 'no administration is configured for the admin')
		const saved = await create(request, 'JournalEntry', entry(administrationId, 'GBP', 500))

		const response = await post(request, idOf(saved))
		expect(response.ok(), 'the post must be refused').toBeFalsy()
		const message = await response.text()
		expect(message).toContain('GBP')
		expect(message).toContain('2026-09-27')

		const after = await (await request.get(`${OBJECTS}/shillinq/JournalEntry/${idOf(saved)}`, { headers: HEADERS })).json()
		expect(after.state).toBe('draft')
		expect(after.glTransactionId ?? null).toBeFalsy()
	})

	test('a typed rate wins and is recorded as manual', async ({ request }) => {
		test.skip(administrationId === '', 'no administration is configured for the admin')
		const saved = await create(request, 'JournalEntry', entry(administrationId, 'USD', 20000, { fxRate: 0.9102, fxRateReason: 'Termijncontract ING 2026-114' }))

		const response = await post(request, idOf(saved))
		expect(response.ok(), await response.text()).toBeTruthy()

		const debit = (await glLines(request, idOf(saved))).find((line) => line.side === 'debit')
		expect(Number(debit!.amount)).toBeCloseTo(18204, 2)
		expect(Number(debit!.fxRate)).toBeCloseTo(0.9102, 4)
		expect(debit!.fxRateSource).toBe('manual')
	})
})
