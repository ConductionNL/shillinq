/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the seed fuel invoice posts in two parts
 * (tax-vat-rates-and-deductibility task 4.1, REQ-TVRD-004).
 *
 * The live check of the change with ledger-posting-path merged, driven through
 * the route the change built on: the APTransaction lifecycle (`receive`, then
 * `issue`), whose `issue` transition runs MaterialiseGlTransactionAction with
 * the DeductibleShareSplitter.
 *
 *   1. the test makes an administration of its own (a unique id with a
 *      membership for the suite's administrator, as ledger-group-close does)
 *      and its chart: 4520 Autokosten at 70 percent deductible with its
 *      private share to 1810, 1810 Privé-opnames, the input VAT account and
 *      the creditors control account;
 *   2. the design's seed fuel invoice, EUR 100.00 plus EUR 21.00 VAT
 *      (taxCode BTW21) on 4520, is created as a draft APTransaction for an
 *      e2e payee of that administration, received and issued;
 *   3. the issued invoice names its GL transaction, and that transaction has
 *      exactly the four lines of the scenario: 4520 debit 70.00, input VAT
 *      debit 14.70, 1810 debit 36.30 marked nonDeductible, and the creditors
 *      control account credit 121.00;
 *   4. a second fuel invoice whose line carries deductiblePercentage 100 posts
 *      in full: 4520 debit 100.00, input VAT debit 21.00, creditors credit
 *      121.00, and nothing on 1810.
 *
 * The input VAT and creditors accounts are the ones the handler books on: the
 * app settings `ledger_input_vat_account` and `ledger_ap_control_account`,
 * else the shipped 1230 and 2000. No shared administration is touched: the
 * seed's chart gives 4520 and 1810 other meanings, and a posted entry cannot
 * be removed. Every object written here is removed afterwards, except what the
 * ledger keeps once posted (the issued invoices, their GL transactions and
 * the accounts and payee they name), which stays in this run's throwaway
 * administration.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/tax-vat-rates-and-deductibility/specs/bookkeeping-journal-entries/spec.md
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md
 *
 * @e2e bookkeeping-journal-entries::a-fuel-bill-is-split-70-to-30
 * @e2e bookkeeping-journal-entries::a-line-override-wins-over-the-account
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const OR = '/index.php/apps/openregister/api/objects'
const OBJECTS = `${OR}/shillinq`
const APP_CONFIG = '/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/shillinq'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMINISTRATION_ID = `${UNIQUE_PREFIX}-tvrd`
const COST_ACCOUNT = '4520'
const PRIVATE_ACCOUNT = '1810'
const NEEDED = ['AdministrationMembership', 'Account', 'Payee', 'APTransaction', 'GLTransaction', 'GLLine', 'VatTariff']

/** One GL line as the object API answers it. */
interface GlLine {
	accountNumber: string
	side: string
	amount: number | string
	taxTreatment?: string | null
}

test.describe('tax-vat-rates-and-deductibility: a mixed cost posts in two parts', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	let inputVatAccount = '1230'
	let creditorsAccount = '2000'
	let payeeId = ''
	const drafts = new Set<string>()

	/**
	 * The CSRF token OpenRegister's write routes need beside the session cookie.
	 *
	 * @return The request token of an authenticated app page.
	 */
	async function requestToken(): Promise<string> {
		if (token === '') {
			const html = await (await api.get('/index.php/apps/shillinq/')).text()
			token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		}
		return token
	}

	/**
	 * The rows of an object list answer.
	 *
	 * @param path The list URL below the shillinq register.
	 * @return The rows.
	 */
	async function list(path: string): Promise<Array<Record<string, unknown>>> {
		const res = await api.get(`${OBJECTS}/${path}`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.ok(), await res.text()).toBe(true)
		const body = await res.json()
		return (body.results ?? body.items ?? []) as Array<Record<string, unknown>>
	}

	/**
	 * Fire a lifecycle transition on an object.
	 *
	 * @param id     The object id.
	 * @param action The transition.
	 * @return Whether it succeeded, and the body of the answer.
	 */
	async function transition(id: string, action: string): Promise<{ ok: boolean, text: string }> {
		const res = await api.post(`${OR}/${encodeURIComponent(id)}/transition`, {
			headers: { requesttoken: await requestToken(), 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { action },
		})
		return { ok: res.ok(), text: await res.text() }
	}

	/**
	 * An app setting the posting handler reads, or its shipped default.
	 *
	 * @param key      The app config key.
	 * @param fallback The handler's default.
	 * @return The account number.
	 */
	async function ledgerAccount(key: string, fallback: string): Promise<string> {
		const res = await api.get(`${APP_CONFIG}/${key}`, { headers: { 'OCS-APIRequest': 'true' } })
		if (!res.ok()) {
			return fallback
		}
		const value = String((await res.json().catch(() => ({})))?.ocs?.data?.data ?? '').trim()
		return value === '' ? fallback : value
	}

	/**
	 * One account of the test's administration.
	 *
	 * @param accountNumber The account number.
	 * @param name          The account name.
	 * @param accountType   The account type.
	 * @param extra         Further fields.
	 */
	async function account(accountNumber: string, name: string, accountType: string, extra: Record<string, unknown> = {}): Promise<void> {
		await fx.create('Account', {
			administrationId: ADMINISTRATION_ID,
			accountNumber,
			name: `${name} ${UNIQUE_PREFIX}`,
			accountType,
			currency: 'EUR',
			status: 'active',
			lifecycleState: 'active',
			...extra,
		})
	}

	/**
	 * The test's administration: the administrator's membership, the chart and the payee.
	 */
	async function administration(): Promise<void> {
		await fx.create('AdministrationMembership', {
			userId: ADMIN_USER,
			administrationId: ADMINISTRATION_ID,
			role: 'controller',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		await account(PRIVATE_ACCOUNT, 'Privé-opnames', 'equity')
		await account(COST_ACCOUNT, 'Autokosten', 'expenses', { deductiblePercentage: 70, privateShareAccountNumber: PRIVATE_ACCOUNT })
		await account(inputVatAccount, 'Te vorderen btw', 'assets')
		await account(creditorsAccount, 'Crediteuren', 'liabilities')

		const payee = await fx.create('Payee', {
			vendorNumber: `${UNIQUE_PREFIX}-TANK`,
			name: `Tankstation ${UNIQUE_PREFIX}`,
			paymentTermDays: 30,
			administrationId: ADMINISTRATION_ID,
			lifecycleState: 'active',
		})
		payeeId = payee.id
	}

	/**
	 * Create the fuel invoice as a draft, receive it and issue it.
	 *
	 * @param suffix Makes the invoice number unique within this run.
	 * @param line   Fields added to the one cost line.
	 * @return The GL lines of the transaction the issue posted.
	 */
	async function postFuelInvoice(suffix: string, line: Record<string, unknown> = {}): Promise<GlLine[]> {
		const today = new Date().toISOString().slice(0, 10)
		const due = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10)
		const { id } = await fx.create('APTransaction', {
			invoiceNumber: `${UNIQUE_PREFIX}-TANK-${suffix}`,
			vendorId: payeeId,
			invoiceDate: today,
			dueDate: due,
			currency: 'EUR',
			totalAmount: 121,
			taxAmount: 21,
			administrationId: ADMINISTRATION_ID,
			state: 'draft',
			lines: [{ accountNumber: COST_ACCOUNT, amount: 100, description: 'Brandstof', taxCode: 'BTW21', ...line }],
		})
		drafts.add(id)

		const received = await transition(id, 'receive')
		expect(received.ok, `receive: ${received.text}`).toBe(true)
		const issued = await transition(id, 'issue')
		expect(issued.ok, `issue: ${issued.text}`).toBe(true)
		// An issued invoice is sealed with its posting; it stays in the throwaway administration.
		drafts.delete(id)

		const invoice = await fx.get('APTransaction', id)
		expect(invoice.state).toBe('issued')
		const transactionId = String(invoice.glTransactionId ?? '')
		expect(transactionId, 'the issued invoice names its GL transaction').not.toBe('')
		// The transaction id goes in the report, for the PR body task 4.1 asks for.
		test.info().annotations.push({ type: 'GL transaction', description: `${UNIQUE_PREFIX}-TANK-${suffix}: ${transactionId}` })

		const transaction = await fx.get('GLTransaction', transactionId)
		expect(transaction.state).toBe('posted')
		return (await list(`GLLine?transactionId=${encodeURIComponent(transactionId)}&_limit=50`)) as unknown as GlLine[]
	}

	/**
	 * A line's tax treatment, with the default `normal` read as none.
	 *
	 * @param value The stored treatment.
	 * @return The treatment, or ''.
	 */
	function treatment(value: unknown): string {
		const text = String(value ?? '')
		return text === 'normal' ? '' : text
	}

	/**
	 * The lines as comparable rows: account, side, amount in cents, treatment.
	 * An omitted treatment and `normal` are the same (the GLLine default).
	 *
	 * @param lines The GL lines.
	 * @return The rows, sorted.
	 */
	function rows(lines: GlLine[]): Array<[string, string, number, string]> {
		return lines
			.map((l): [string, string, number, string] => [String(l.accountNumber), String(l.side), Math.round(Number(l.amount) * 100), treatment(l.taxTreatment)])
			.sort((a, b) => a.join('|').localeCompare(b.join('|')))
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
		inputVatAccount = await ledgerAccount('ledger_input_vat_account', inputVatAccount)
		creditorsAccount = await ledgerAccount('ledger_ap_control_account', creditorsAccount)
	})

	test.afterAll(async () => {
		for (const id of drafts) {
			await fx?.remove('APTransaction', id)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('the seed fuel invoice posts 4520 70.00, input VAT 14.70, 1810 36.30 non-deductible and creditors 121.00 @spec REQ-TVRD-004', async () => {
		const missing = await fx.missingSchema(NEEDED)
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		const tariffs = await list('VatTariff?code=high&_limit=5')
		test.skip(tariffs.length === 0, 'the VatTariff register holds no `high` tariff; the seed (task 1.1) has not run on this instance')

		await administration()

		const lines = await postFuelInvoice('70')
		expect(lines, 'four ledger lines').toHaveLength(4)
		expect(rows(lines)).toEqual(rows([
			{ accountNumber: COST_ACCOUNT, side: 'debit', amount: 70, taxTreatment: null },
			{ accountNumber: inputVatAccount, side: 'debit', amount: 14.7, taxTreatment: null },
			{ accountNumber: PRIVATE_ACCOUNT, side: 'debit', amount: 36.3, taxTreatment: 'nonDeductible' },
			{ accountNumber: creditorsAccount, side: 'credit', amount: 121, taxTreatment: null },
		]))

		const debit = lines.filter((l) => l.side === 'debit').reduce((sum, l) => sum + Math.round(Number(l.amount) * 100), 0)
		const credit = lines.filter((l) => l.side === 'credit').reduce((sum, l) => sum + Math.round(Number(l.amount) * 100), 0)
		expect(debit, 'the transaction balances').toBe(credit)
	})

	test('a line at 100 percent posts in full on the same account @spec REQ-TVRD-004', async () => {
		test.skip(payeeId === '', 'the fuel invoice test did not set up the administration and the payee')

		const lines = await postFuelInvoice('100', { deductiblePercentage: 100 })
		expect(lines, 'three ledger lines, none on the private-share account').toHaveLength(3)
		expect(rows(lines)).toEqual(rows([
			{ accountNumber: COST_ACCOUNT, side: 'debit', amount: 100, taxTreatment: null },
			{ accountNumber: inputVatAccount, side: 'debit', amount: 21, taxTreatment: null },
			{ accountNumber: creditorsAccount, side: 'credit', amount: 121, taxTreatment: null },
		]))
	})
})
