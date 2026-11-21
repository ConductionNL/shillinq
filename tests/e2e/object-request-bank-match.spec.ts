/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a request on an object, its transfer reference and the bank
 * statement that pays it (receivables-object-request-settlement task 2.3,
 * REQ-ORS-001 to REQ-ORS-006).
 *
 *   1. event fee: the revenue account mapping offers event-fee, and a settled
 *      event-fee request was booked on the account it maps to (REQ-ORS-001);
 *   2. reference: no two open requests carry the same transfer reference, and
 *      no reference is shorter than six characters (REQ-ORS-002);
 *   3. a quoted reference settles the request: a CAMT.053 line of the full
 *      open amount whose remittance quotes the reference gives a confirmed
 *      payment-request match, and the request then reads settled by
 *      bank-transfer with one settlement for the line (REQ-ORS-003,
 *      REQ-ORS-004);
 *   4. a partial amount waits for the bookkeeper: a line of less than the
 *      open amount gives a candidate match and leaves the request pending
 *      (REQ-ORS-003);
 *   5. receipt: a request with receiptSentAt was settled and had an email
 *      address to mail (REQ-ORS-005);
 *   6. invoice: a request created with invoiceRequested names an issued
 *      ARInvoice for its amount (REQ-ORS-006).
 *
 * Tests 3 and 4 write. Each creates its own pending object request with a
 * reference unique to the run, then imports a one-line CAMT.053 statement
 * through the import endpoint the bank statement wizard uses. Test 4 removes
 * everything it made. Test 3 leaves its request, statement and match in
 * place, because the confirmed match books a receipt that a posted entry
 * keeps; its description starts with "e2e". The leaf that raises requests
 * for other apps is called in-process, not over HTTP, so the refusals of
 * REQ-ORS-002 and REQ-ORS-006 are checked as invariants on stored requests.
 * The caller is the suite's administrator (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md
 * @spec openspec/changes/receivables-object-request-settlement/tasks.md#task-2.3
 *
 * @e2e object-payment-requests::larpinq-asks-for-an-event-fee
 * @e2e object-payment-requests::a-reference-in-use-is-refused
 * @e2e object-payment-requests::a-transfer-with-the-reference-and-the-full-amount-is-matched
 * @e2e object-payment-requests::a-transfer-for-less-waits-for-the-bookkeeper
 * @e2e object-payment-requests::larpinq-sees-the-registration-paid
 * @e2e object-payment-requests::anna-gets-a-receipt-after-paying-online
 * @e2e object-payment-requests::a-player-who-asked-for-an-invoice-gets-one
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const RUN = `${Date.now()}`

/** One settlement on a request. */
interface Settlement {
	method: string
	amount: number
	reference?: string
}

/** The part of a PaymentRequest this spec reads and writes. */
interface PaymentRequest {
	id?: string
	state: string
	subjectKind?: 'invoice' | 'object'
	subject?: { app?: string, type?: string, register?: string, schema?: string, id?: string }
	requestType?: string
	description?: string
	amount: number
	currency?: string
	paymentGateway?: 'mollie' | 'stripe'
	paymentReference?: string
	debtor?: { customerMasterId?: string, name?: string, email?: string }
	administrationId?: string
	revenueAccount?: string | null
	invoiceRequested?: boolean
	invoiceReference?: string | null
	settledAt?: string | null
	settledVia?: string | null
	receiptSentAt?: string | null
	settlements?: Settlement[]
	'@self'?: { id?: string }
}

/** The part of a BankStatementLine this spec reads. */
interface BankLine {
	id?: string
	statementId: string
	amount: number
	remittanceInfo?: string
	matchState?: string
	'@self'?: { id?: string }
}

/** The part of a ReconciliationMatch this spec reads. */
interface ReconciliationMatch {
	id?: string
	matchType: string
	targetType: string
	targetRefs: string[]
	bankStatementLineId: string
	matchedAmount: number
	status: string
	state: string
	'@self'?: { id?: string }
}

/** The part of an ARInvoice this spec reads. */
interface ARInvoice {
	grossAmount: number
	lifecycleState: string
}

/** The import answer (BankStatementImportController::import). */
interface ImportAnswer {
	statementId: string
	transactionCount: number
}

/** The part of the administration context this spec reads. */
interface AdministrationContext {
	activeAdministrationId?: string
}

/**
 * The id OpenRegister answers an object under.
 *
 * @param object The object.
 * @return Its id.
 */
function idOf(object: { id?: string, '@self'?: { id?: string } }): string {
	return String(object.id ?? object['@self']?.id ?? '')
}

/**
 * Every object of a schema that matches the filters, page by page.
 *
 * @param request The request context.
 * @param schema  The schema slug.
 * @param filters Field filters.
 * @return The objects.
 */
async function listAll<T>(request: APIRequestContext, schema: string, filters: Record<string, string> = {}): Promise<T[]> {
	const all: T[] = []
	for (let page = 1; page <= 20; page++) {
		const query = new URLSearchParams({ ...filters, _limit: '200', _page: String(page) })
		const res = await request.get(`${OBJECTS}/${schema}?${query.toString()}`, { headers: HEADERS })
		expect(res.ok(), `list ${schema}: ${await res.text()}`).toBe(true)
		const rows = ((await res.json()) as { results?: T[] }).results ?? []
		all.push(...rows)
		if (rows.length < 200) {
			break
		}
	}
	return all
}

/**
 * One object by id.
 *
 * @param request The request context.
 * @param schema  The schema slug.
 * @param id      The id.
 * @return The object.
 */
async function getObject<T>(request: APIRequestContext, schema: string, id: string): Promise<T> {
	const res = await request.get(`${OBJECTS}/${schema}/${encodeURIComponent(id)}`, { headers: HEADERS })
	expect(res.ok(), `read ${schema} ${id}: ${await res.text()}`).toBe(true)
	return (await res.json()) as T
}

/**
 * The administration the caller works in.
 *
 * @param request The request context.
 * @return Its id, or '' without one.
 */
async function activeAdministration(request: APIRequestContext): Promise<string> {
	const res = await request.get(`${API}/administrations/context`, { headers: HEADERS })
	if (!res.ok()) {
		return ''
	}
	return String(((await res.json()) as AdministrationContext).activeAdministrationId ?? '')
}

/**
 * Create a pending event-fee request on an object, as larpinq raises one.
 *
 * @param request          The request context.
 * @param administrationId The administration.
 * @param reference        The transfer reference.
 * @return The stored request's id.
 */
async function pendingRequest(request: APIRequestContext, administrationId: string, reference: string): Promise<string> {
	const data: PaymentRequest = {
		subjectKind: 'object',
		subject: { app: 'shillinq-e2e', type: 'registration', register: 'e2e', schema: 'Registration', id: `reg-${reference}` },
		requestType: 'event-fee',
		description: `e2e Winter Court 2026 ${reference}`,
		amount: 85,
		currency: 'EUR',
		paymentGateway: 'mollie',
		paymentReference: reference,
		debtor: { name: 'Anna e2e' },
		administrationId,
		state: 'pending',
	}
	const res = await request.post(`${OBJECTS}/PaymentRequest`, { headers: HEADERS, data })
	expect(res.ok(), `create the request: ${await res.text()}`).toBe(true)
	return idOf((await res.json()) as PaymentRequest)
}

/**
 * Import a one-line CAMT.053 credit statement.
 *
 * @param request    The request context.
 * @param amount     The credited amount.
 * @param remittance The unstructured remittance text.
 * @return The import answer.
 */
async function importCamt(request: APIRequestContext, amount: string, remittance: string): Promise<ImportAnswer> {
	const contents = '<?xml version="1.0" encoding="UTF-8"?>'
		+ '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>'
		+ `<Id>E2E-${RUN}-${amount}</Id>`
		+ `<Ntry><Amt Ccy="EUR">${amount}</Amt><CdtDbtInd>CRDT</CdtDbtInd><ValDt><Dt>2026-10-03</Dt></ValDt>`
		+ `<NtryDtls><TxDtls><Refs><EndToEndId>E2E-${RUN}-${amount}</EndToEndId></Refs>`
		+ `<RmtInf><Ustrd>${remittance}</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>`
		+ '</Stmt></BkToCstmrStmt></Document>'
	const res = await request.post(`${API}/v1/bank-statements/import`, { headers: HEADERS, data: { format: 'camt053', contents } })
	expect(res.status(), await res.text()).toBe(200)
	const answer = (await res.json()) as ImportAnswer
	expect(answer.transactionCount, 'one line imported').toBe(1)
	return answer
}

/**
 * The payment-request matches of a statement's one line, once they exist.
 *
 * @param request     The request context.
 * @param statementId The statement.
 * @return The line and its matches.
 */
async function matchesOf(request: APIRequestContext, statementId: string): Promise<{ line: BankLine, matches: ReconciliationMatch[] }> {
	const lines = await listAll<BankLine>(request, 'BankStatementLine', { statementId })
	expect(lines, 'the statement has its line').toHaveLength(1)
	const lineId = idOf(lines[0])
	let matches: ReconciliationMatch[] = []
	await expect.poll(async () => {
		matches = (await listAll<ReconciliationMatch>(request, 'ReconciliationMatch', { bankStatementLineId: lineId }))
			.filter((match) => match.matchType === 'payment-request')
		return matches.length
	}, { timeout: 20_000 }).toBeGreaterThan(0)
	return { line: lines[0], matches }
}

/**
 * Remove objects, ignoring the ones already gone.
 *
 * @param request The request context.
 * @param objects Schema and id pairs, removed in order.
 */
async function remove(request: APIRequestContext, objects: Array<{ schema: string, id: string }>): Promise<void> {
	for (const { schema, id } of objects) {
		if (id !== '') {
			await request.delete(`${OBJECTS}/${schema}/${encodeURIComponent(id)}`, { headers: HEADERS }).catch(() => undefined)
		}
	}
}

/**
 * Compare a euro amount to the cent.
 *
 * @param value The amount.
 * @return The cents.
 */
function cents(value: number): number {
	return Math.round(Number(value) * 100)
}

test.describe('receivables-object-request-settlement: request type and reference', () => {
	test('an event fee books on the account its type maps to @spec REQ-ORS-001', async ({ request }) => {
		const res = await request.get(`${API}/settings/payment-revenue-accounts`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const mapping = (await res.json()) as Record<string, string>
		expect(Object.keys(mapping), 'the mapping offers event-fee').toContain('event-fee')

		const settled = (await listAll<PaymentRequest>(request, 'PaymentRequest', { requestType: 'event-fee', subjectKind: 'object' }))
			.filter((candidate) => candidate.settledAt && candidate.revenueAccount && !candidate.invoiceReference)
		test.skip(mapping['event-fee'] === '' || settled.length === 0, 'no event fee was booked against a mapped account')
		for (const paymentRequest of settled) {
			expect(paymentRequest.revenueAccount, `${idOf(paymentRequest)} booked on the mapped account`).toBe(mapping['event-fee'])
		}
	})

	test('no two open requests carry the same reference @spec REQ-ORS-002', async ({ request }) => {
		const open = (await listAll<PaymentRequest>(request, 'PaymentRequest', { subjectKind: 'object' }))
			.filter((candidate) => ['pending', 'authorized'].includes(candidate.state) && (candidate.paymentReference ?? '') !== '')
		test.skip(open.length === 0, 'no open request carries a reference')

		const seen = new Map<string, string>()
		for (const paymentRequest of open) {
			const reference = String(paymentRequest.paymentReference).trim()
			expect(reference.length, `${reference} has at least six characters`).toBeGreaterThanOrEqual(6)
			expect(seen.get(reference.toLowerCase()), `${reference} is carried once`).toBeUndefined()
			seen.set(reference.toLowerCase(), idOf(paymentRequest))
		}
	})
})

test.describe('receivables-object-request-settlement: bank match', () => {
	test('a quoted reference settles the request @spec REQ-ORS-003 @spec REQ-ORS-004', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const reference = `WC26-E2E${RUN.slice(-6)}`
		const requestId = await pendingRequest(request, administrationId, reference)

		const imported = await importCamt(request, '85.00', `Winter Court ${reference} Anna`)
		const { line, matches } = await matchesOf(request, imported.statementId)
		expect(matches, 'one payment-request match').toHaveLength(1)
		expect(matches[0].targetType).toBe('payment-request')
		expect(matches[0].targetRefs).toEqual([requestId])
		expect(matches[0].status, 'confirmed at once').toBe('confirmed')
		expect(matches[0].state).toBe('confirmed')
		expect(cents(matches[0].matchedAmount)).toBe(8500)

		await expect.poll(async () => (await getObject<PaymentRequest>(request, 'PaymentRequest', requestId)).settledVia, { timeout: 20_000 }).toBe('bank-transfer')
		const settled = await getObject<PaymentRequest>(request, 'PaymentRequest', requestId)
		expect(settled.settledAt, 'settledAt is stamped').toBeTruthy()
		const transfers = (settled.settlements ?? []).filter((settlement) => settlement.method === 'bank-transfer')
		expect(transfers, 'settled once').toHaveLength(1)
		expect(cents(transfers[0].amount)).toBe(8500)
		expect((await getObject<BankLine>(request, 'BankStatementLine', idOf(line))).matchState).toBe('confirmed')
	})

	test('a partial amount waits for the bookkeeper @spec REQ-ORS-003', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const reference = `WC26-P2E${RUN.slice(-6)}`
		const made: Array<{ schema: string, id: string }> = []
		try {
			const requestId = await pendingRequest(request, administrationId, reference)
			made.push({ schema: 'PaymentRequest', id: requestId })

			const imported = await importCamt(request, '50.00', `Winter Court ${reference} Anna`)
			made.unshift({ schema: 'BankStatement', id: imported.statementId })
			const { line, matches } = await matchesOf(request, imported.statementId)
			made.unshift({ schema: 'BankStatementLine', id: idOf(line) })
			made.unshift(...matches.map((match) => ({ schema: 'ReconciliationMatch', id: idOf(match) })))

			expect(matches, 'one candidate').toHaveLength(1)
			expect(matches[0].targetRefs).toEqual([requestId])
			expect(matches[0].status, 'a candidate is a pending match').toBe('pending')
			expect(matches[0].state).toBe('candidate')

			const untouched = await getObject<PaymentRequest>(request, 'PaymentRequest', requestId)
			expect(untouched.state).toBe('pending')
			expect(untouched.settledAt ?? null).toBeNull()
			expect(untouched.settlements ?? []).toHaveLength(0)
		} finally {
			await remove(request, made)
		}
	})

	test.fixme('the candidate shows on the unmatched items page @spec REQ-ORS-003', async () => {
		// Missing: the UnmatchedItems page filters resolutionStatus null, while a payment-request candidate is written with resolutionStatus pending.
	})
})

test.describe('receivables-object-request-settlement: receipt and invoice', () => {
	test('a receipt went only to a settled request with an email address @spec REQ-ORS-005', async ({ request }) => {
		const mailed = (await listAll<PaymentRequest>(request, 'PaymentRequest', { subjectKind: 'object' }))
			.filter((candidate) => candidate.receiptSentAt)
		test.skip(mailed.length === 0, 'no receipt was mailed on this instance')

		for (const paymentRequest of mailed) {
			const label = idOf(paymentRequest)
			expect(paymentRequest.settledAt, `${label} was settled`).toBeTruthy()
			expect(paymentRequest.debtor?.email ?? '', `${label} had an address`).toMatch(/@/)
			expect(Date.parse(String(paymentRequest.receiptSentAt)), `${label} was mailed after it settled`).toBeGreaterThanOrEqual(Date.parse(String(paymentRequest.settledAt)) - 1000)
		}
	})

	test.fixme('a replayed provider capture sends no second receipt @spec REQ-ORS-005', async () => {
		// Missing: a provider capture reaches shillinq only as a signed webhook, and the suite has no mailbox to count mails in.
	})

	test('a request that asked for an invoice names an issued invoice for its amount @spec REQ-ORS-006', async ({ request }) => {
		const asked = (await listAll<PaymentRequest>(request, 'PaymentRequest', { subjectKind: 'object' }))
			.filter((candidate) => candidate.invoiceRequested === true)
		test.skip(asked.length === 0, 'no request asked for an invoice')

		for (const paymentRequest of asked) {
			const label = idOf(paymentRequest)
			expect(paymentRequest.invoiceReference, `${label} names its invoice`).toBeTruthy()
			expect(paymentRequest.administrationId, `${label} names the administration that bills it`).toBeTruthy()
			const invoice = await getObject<ARInvoice>(request, 'ARInvoice', String(paymentRequest.invoiceReference))
			expect(cents(invoice.grossAmount), `${label}: the invoice is for the amount`).toBe(cents(paymentRequest.amount))
			expect(invoice.lifecycleState, `${label}: the invoice was issued`).not.toBe('draft')
		}
	})
})
