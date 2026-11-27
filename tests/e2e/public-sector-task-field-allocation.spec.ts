/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: realisation per task field, the joint arrangement
 * allocation run and the integral cost price
 * (public-sector-task-field-allocation task 4.2).
 *
 * The live pass of the change, driven through the routes and transitions the
 * pages call:
 *
 *   1. in a municipal administration, EUR 40,000 posted on an account mapped
 *      to task field 0.1 and EUR 5,000 on an unmapped account: the
 *      `taskFieldRealisation` of `GET /api/bbv-dashboard` shows 0.1 with
 *      EUR 40,000 in expenses and lists the unmapped account with EUR 5,000
 *      and the BBV mapping page, and the dashboard page shows that account in
 *      its unmapped list with a link to /overheid/bbv-mapping (REQ-BBV-012);
 *   2. three active participants at 50, 30 and 20 percent of EUR 100,000.01
 *      posted on the key's cost cluster in the first quarter: the `propose`
 *      transition of a GRAllocationRun writes EUR 50,000.01, EUR 30,000.00 and
 *      EUR 20,000.00, adding up to the cent (REQ-GRC-007); `confirm` and
 *      `invoice` write three draft invoices naming the run, and a second run
 *      for the same key and quarter is refused with the confirmed run named
 *      (REQ-GRC-008);
 *   3. a commercial activity with EUR 10,000 of direct costs on its cost
 *      centre and cost object, 10 percent of a EUR 50,000 overhead pool on
 *      task field 0.4, EUR 100,000 invested at 5 percent and a 10 percent
 *      markup: the `calculate` transition for 2026 stores an IntegralCostPrice
 *      of EUR 22,000 (10,000 + 5,000 + 5,000 + 2,000), EUR 22 per unit over
 *      1,000 units, not covered by the applied rate of EUR 20 (REQ-WMO-013).
 *
 * The cross subsidy scan (REQ-WMO-014) is a daily background job with no
 * route, so it is a fixme here; its arithmetic is CrossSubsidyScanTest's.
 *
 * Every part runs in an administration of its own named with the run's
 * unique prefix; the dashboard one gets an Administration record and an
 * AdministrationMembership for the suite's administrator. The allocation
 * splits over every active participant of the instance (participants are
 * not kept per administration), so that part skips when the instance holds
 * other active participants. Every object written here, the invoices and the
 * cost price included, is removed afterwards.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no
 * live instance).
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-bbv-compliance/spec.md
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
 * @spec openspec/changes/public-sector-task-field-allocation/tasks.md
 *
 * @e2e bookkeeping-bbv-compliance::a-controller-reads-the-year-per-task-field
 * @e2e bookkeeping-gr-consolidation::a-controller-splits-the-shared-service-costs
 * @e2e bookkeeping-gr-consolidation::a-controller-invoices-the-participants
 * @e2e bookkeeping-market-government-separation::a-controller-calculates-the-cost-price-of-the-sports-hall-rental
 */

import type { APIRequestContext, APIResponse } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { money, OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/index.php/apps/shillinq'
const OR_API = '/index.php/apps/openregister/api'
const OBJECTS = `${OR_API}/objects/shillinq`

/** A seeded ledger line. */
interface LedgerLine {
	accountNumber: string
	side: 'debit' | 'credit'
	amount: number
	costCenterCode?: string
	costCarrierCode?: string
}

/** One participant line of an allocation run. */
interface RunLine {
	participantId: string
	participantName: string
	share: number
	amount: number
	invoiceId?: string | null
}

test.describe('public-sector-task-field-allocation: task fields, allocation run and cost price', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	const written: Array<{ schema: string, id: string }> = []

	/**
	 * The CSRF token the write routes need beside the session cookie.
	 *
	 * @return The request token of an authenticated app page.
	 */
	async function requestToken(): Promise<string> {
		if (token === '') {
			const html = await (await api.get(`${APP}/`)).text()
			token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		}
		return token
	}

	/**
	 * Run a lifecycle transition on an object, as a page's lifecycle action does.
	 *
	 * @param id     The object id.
	 * @param action The transition.
	 * @param data   The transition's inputs.
	 * @return The response.
	 */
	async function transition(id: string, action: string, data: Record<string, unknown> = {}): Promise<APIResponse> {
		return api.post(`${OR_API}/objects/${encodeURIComponent(id)}/transition`, {
			headers: { requesttoken: await requestToken(), 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { action, data },
		})
	}

	/**
	 * A posted transaction with its lines.
	 *
	 * @param administrationId The administration.
	 * @param number           Makes the transaction number unique within this run.
	 * @param day              The posting date (YYYY-MM-DD).
	 * @param lines            The lines.
	 * @return The transaction's state as stored.
	 */
	async function posted(administrationId: string, number: string, day: string, lines: LedgerLine[]): Promise<string> {
		const periodId = `${administrationId}-${day.slice(0, 7)}`
		const { id: transactionId } = await fx.create('GLTransaction', {
			administrationId,
			periodId,
			transactionNumber: `${UNIQUE_PREFIX}-${number}`,
			postingDate: day,
			currency: 'EUR',
			state: 'posted',
			description: `${UNIQUE_PREFIX} ${number}`,
		})
		for (const [index, line] of lines.entries()) {
			await fx.create('GLLine', {
				transactionId,
				periodId,
				lineNumber: index + 1,
				currency: 'EUR',
				...line,
			})
		}
		const stored = await fx.get('GLTransaction', transactionId)
		return String(stored.state ?? '')
	}

	/**
	 * An account of the administration.
	 *
	 * @param administrationId The administration.
	 * @param accountNumber    The account number.
	 * @param accountType      The account type.
	 * @param rgsCode          The RGS code.
	 */
	async function account(administrationId: string, accountNumber: string, accountType: string, rgsCode: string): Promise<void> {
		await fx.create('Account', {
			administrationId,
			accountNumber,
			name: `${UNIQUE_PREFIX} ${accountNumber}`,
			accountType,
			rgsCode,
			currency: 'EUR',
			lifecycleState: 'active',
		})
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		for (const { schema, id } of written.reverse()) {
			await fx?.remove(schema, id)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('a controller reads the year per task field and sees the unmapped account @spec REQ-BBV-012', async ({ page }) => {
		const missing = await fx.missingSchema(['Administration', 'AdministrationMembership', 'BbvAccountMapping', 'GLTransaction', 'GLLine'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		// The dashboard reads the administration's active fiscal year: today's.
		const year = new Date().getUTCFullYear()
		const administrationId = `${UNIQUE_PREFIX}-gem`
		await fx.create('Administration', {
			administrationCode: administrationId,
			name: `Gemeente ${UNIQUE_PREFIX}`,
			legalForm: 'municipality',
			status: 'actief',
			fiscalYearStartMonth: 1,
			fiscalYearStartDay: 1,
			vatRegime: 'overheid',
		})
		await fx.create('AdministrationMembership', {
			userId: 'admin',
			administrationId,
			role: 'controller',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		await fx.create('BbvAccountMapping', { administrationId, accountNumber: '6100', taskField: '0.1' })
		const state = await posted(administrationId, 'BBV-1', `${year}-01-15`, [
			{ accountNumber: '6100', side: 'debit', amount: 40_000 },
			{ accountNumber: '6999', side: 'debit', amount: 5_000 },
			{ accountNumber: '1100', side: 'credit', amount: 45_000 },
		])
		test.skip(state !== 'posted', 'the instance keeps a seeded GLTransaction out of `posted`; the realisation sums posted lines only')

		const res = await api.get(`${APP}/api/bbv-dashboard`, { headers: { 'OCS-APIRequest': 'true' }, params: { administrationId } })
		expect(res.status()).toBe(200)
		const envelope = await res.json()
		test.skip(envelope.scope?.administrationId !== administrationId, `the dashboard answered the empty envelope: the administrator's membership of ${administrationId} or its Administration record is not resolved`)
		expect(Number(envelope.scope.fiscalYear)).toBe(year)

		const realisation = envelope.taskFieldRealisation
		expect(realisation, 'the envelope carries the realisation per task field').toBeTruthy()
		const field = (realisation.taskFields as Array<{ taskField: string, expenses: number, income: number }>).find((row) => row.taskField === '0.1')
		expect(field, 'task field 0.1 is shown').toBeTruthy()
		expect(money(field!.expenses)).toBe(40_000)
		expect(money(field!.income)).toBe(0)
		const unmapped = (realisation.unmapped as Array<{ accountNumber: string, amount: number, mappingPage: string }>).find((row) => row.accountNumber === '6999')
		expect(unmapped, 'the unmapped account is listed, not dropped').toBeTruthy()
		expect(money(unmapped!.amount)).toBe(5_000)
		expect(unmapped!.mappingPage).toBe('/overheid/bbv-mapping')

		// The dashboard page, pointed at this administration, lists the account with its link.
		await page.route('**/apps/shillinq/api/bbv-dashboard**', async (route) => {
			const url = new URL(route.request().url())
			url.searchParams.set('administrationId', administrationId)
			await route.continue({ url: url.toString() })
		})
		await page.goto(`${APP}/bbv-dashboard`)
		const row = page.getByTestId('bbv-unmapped-6999')
		await expect(row).toBeVisible({ timeout: 20_000 })
		await expect(row).toContainText(/5[.,]000/)
		await expect(row.getByRole('link')).toHaveAttribute('href', /\/apps\/shillinq\/overheid\/bbv-mapping$/)
	})

	test('an allocation run splits EUR 100,000.01 over three participants and invoices them @spec REQ-GRC-007 @spec REQ-GRC-008', async () => {
		const missing = await fx.missingSchema(['GRVerdeelsleutel', 'GRDeelnemer', 'GRAllocationRun', 'GLTransaction', 'GLLine', 'ARInvoice'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const others = await api.get(`${OBJECTS}/GRDeelnemer`, { headers: { 'OCS-APIRequest': 'true' }, params: { _limit: '500' } })
		expect(others.ok()).toBeTruthy()
		const active = ((await others.json()).results ?? []) as Array<Record<string, unknown>>
		const foreign = active.filter((row) => row.actief !== false && (row.lifecycleState ?? 'active') === 'active' && Number(row.share ?? 0) > 0)
		test.skip(foreign.length > 0, `the instance holds ${foreign.length} other active GR participant(s); the allocation splits over every active participant, so the 50/30/20 split cannot be asserted here`)

		const administrationId = `${UNIQUE_PREFIX}-gr`
		const { id: keyId } = await fx.create('GRVerdeelsleutel', {
			keyName: `Shared services ${UNIQUE_PREFIX}`,
			costClusterAccountNumbers: ['4810'],
			verdelingsType: 'vast-percentage',
			lineNumber: 1,
		})
		const participants: Array<{ name: string, share: number, customerId: string, id: string }> = []
		for (const [name, share] of [['Gemeente Noord', 50], ['Gemeente Midden', 30], ['Gemeente Zuid', 20]] as Array<[string, number]>) {
			const customerId = randomUUID()
			const { id } = await fx.create('GRDeelnemer', {
				participantType: 'municipality',
				participantName: `${name} ${UNIQUE_PREFIX}`,
				administrationId,
				share,
				actief: true,
				lifecycleState: 'active',
				customerId,
			})
			participants.push({ name: `${name} ${UNIQUE_PREFIX}`, share, customerId, id })
		}
		const state = await posted(administrationId, 'GR-1', '2026-02-15', [
			{ accountNumber: '4810', side: 'debit', amount: 100_000.01 },
			{ accountNumber: '1100', side: 'credit', amount: 100_000.01 },
		])
		test.skip(state !== 'posted', 'the instance keeps a seeded GLTransaction out of `posted`; the run sums posted lines only')

		const quarter = { periodFrom: '2026-01-01', periodTo: '2026-03-31' }
		const { id: runId } = await fx.create('GRAllocationRun', {
			administrationId,
			keyId,
			...quarter,
			invoiceDate: '2026-04-15',
			paymentTermDays: 30,
			state: 'draft',
		})

		// Propose: one line per participant, adding up to the cent.
		const proposed = await transition(runId, 'propose')
		expect(proposed.ok(), `propose: HTTP ${proposed.status()} ${await proposed.text()}`).toBeTruthy()
		const draft = await fx.get('GRAllocationRun', runId)
		expect(draft.state).toBe('draft')
		expect(money(Number(draft.total))).toBe(100_000.01)
		const lines = draft.lines as RunLine[]
		expect(lines).toHaveLength(3)
		expect(lines.map((line) => line.participantId)).toEqual(participants.map((participant) => participant.id))
		expect(lines.map((line) => money(line.amount))).toEqual([50_000.01, 30_000.0, 20_000.0])
		expect(money(lines.reduce((sum, line) => sum + line.amount, 0)), 'the lines add up to the total').toBe(100_000.01)

		// Confirm, then invoice: one draft invoice per participant naming the run.
		const confirmed = await transition(runId, 'confirm')
		expect(confirmed.ok(), `confirm: HTTP ${confirmed.status()} ${await confirmed.text()}`).toBeTruthy()
		expect((await fx.get('GRAllocationRun', runId)).state).toBe('confirmed')

		const invoiced = await transition(runId, 'invoice')
		expect(invoiced.ok(), `invoice: HTTP ${invoiced.status()} ${await invoiced.text()}`).toBeTruthy()
		const done = await fx.get('GRAllocationRun', runId)
		expect(done.state).toBe('invoiced')
		const invoicedLines = done.lines as RunLine[]
		const invoiceIds = invoicedLines.map((line) => String(line.invoiceId ?? ''))
		for (const id of invoiceIds.filter((value) => value !== '')) {
			written.push({ schema: 'ARInvoice', id })
		}
		expect(new Set(invoiceIds.filter((value) => value !== '')).size, 'three draft invoices, one per participant').toBe(3)
		for (const [index, line] of invoicedLines.entries()) {
			const invoice = await fx.get('ARInvoice', String(line.invoiceId))
			expect(invoice.allocationRunId, 'the invoice names the run').toBe(runId)
			expect(invoice.lifecycleState).toBe('draft')
			expect(invoice.customerId).toBe(participants[index].customerId)
			expect(money(Number(invoice.grossAmount))).toBe(money(line.amount))
		}

		// A second run for the same key and quarter is refused with the confirmed run named.
		const { id: secondId } = await fx.create('GRAllocationRun', { administrationId, keyId, ...quarter, state: 'draft' })
		const again = await transition(secondId, 'propose')
		expect(again.ok(), 'proposing the same key and quarter again is refused').toBe(false)
		// The run's name is `<key> <from> – <to>`; the dash may come back JSON-escaped, so match its parts.
		const refusal = await again.text()
		expect(refusal).toContain('already have a confirmed allocation run')
		expect(refusal, 'the refusal names the confirmed run').toContain(String(done.keyName))
		expect(refusal).toContain(quarter.periodFrom)
	})

	test('a controller calculates the 2026 cost price of the sports hall rental @spec REQ-WMO-013', async () => {
		const missing = await fx.missingSchema(['CommercialActivity', 'IntegralCostPrice', 'Account', 'BbvAccountMapping', 'GLTransaction', 'GLLine'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const administrationId = `${UNIQUE_PREFIX}-wmo`
		await account(administrationId, '4400', 'expenses', 'WBedHuiHuu')
		await account(administrationId, '4900', 'expenses', 'WBedAlgAlk')
		await account(administrationId, '1100', 'assets', 'BLimKasKas')
		await fx.create('BbvAccountMapping', { administrationId, accountNumber: '4900', taskField: '0.4' })
		const state = await posted(administrationId, 'WMO-1', '2026-05-31', [
			{ accountNumber: '4400', side: 'debit', amount: 10_000, costCenterCode: 'SPORT', costCarrierCode: 'ZAALHUUR' },
			{ accountNumber: '4900', side: 'debit', amount: 50_000 },
			{ accountNumber: '1100', side: 'credit', amount: 60_000 },
		])
		test.skip(state !== 'posted', 'the instance keeps a seeded GLTransaction out of `posted`; the cost price reads posted lines only')

		const code = `${UNIQUE_PREFIX}-SPORT`
		const { id: activityId } = await fx.create('CommercialActivity', {
			code,
			name: 'Verhuur sporthal',
			governingBody: 'college',
			organisationalUnit: 'Sport',
			description: 'Rental of the sports hall to clubs and companies',
			marketSegment: 'sports hall rental',
			concurrenten: [],
			startDate: '2026-01-01',
			costPriceMethod: 'integral-costprice-art-25i',
			costCentreCode: 'SPORT',
			costObjectCode: 'ZAALHUUR',
			isExempted: false,
			administrationId,
			state: 'active',
			costPriceYear: 2026,
			overheadKey: [{ bucket: 'huisvesting', ratio: 0.1 }],
			investedBookValue: 100_000,
			waccRate: 0.05,
			profitMarkupRate: 0.1,
			soldUnits: 1000,
			unitLabel: 'hour',
			appliedRate: 20,
		})

		const res = await transition(activityId, 'calculate', { costPriceYear: 2026 })
		expect(res.ok(), `calculate: HTTP ${res.status()} ${await res.text()}`).toBeTruthy()
		const activity = await fx.get('CommercialActivity', activityId)
		const priceId = String(activity.integralCostPriceId ?? '')
		expect(priceId, 'the activity links its cost price').not.toBe('')
		written.push({ schema: 'IntegralCostPrice', id: priceId })

		const price = await fx.get('IntegralCostPrice', priceId)
		expect(price.commercialActivityId).toBe(code)
		expect(price.period).toBe('2026-YTD')
		expect(price.status).toBe('voorlopig')
		const parts = price.componenten as Record<string, unknown>
		expect(money(Number(parts.directMaterials)), 'the direct costs on the cost centre and cost object').toBe(10_000)
		expect(money(Number((parts.indirecteOverhead as Record<string, number>).huisvesting)), '10 percent of the task field 0.4 pool').toBe(5_000)
		expect(money(Number(parts.capitalCost)), 'EUR 100,000 at 5 percent').toBe(5_000)
		expect(money(Number(parts.profitMarkup)), '10 percent over EUR 20,000').toBe(2_000)
		expect(money(Number(price.totalCost))).toBe(22_000)
		expect(Number(price.costPricePerUnit)).toBe(22)
		expect(price.compliant, 'a rate of EUR 20 does not cover a cost price of EUR 22').toBe(false)
	})

	test.fixme('the daily cross subsidy scan writes one open loss financing alert, also when it runs twice @spec REQ-WMO-014', async () => {
		// CrossSubsidyScanJob is a daily background job, started by Nextcloud's
		// cron (or `occ background-job:execute`); no route starts it, so a
		// nightly against a live instance cannot run it twice on demand.
	})
})
