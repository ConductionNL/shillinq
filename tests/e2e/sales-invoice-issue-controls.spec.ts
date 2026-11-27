/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: numbering and approval at the moment a sales invoice is
 * issued (sales-invoice-issue-controls task 4.1, REQ-SIIC-001 to
 * REQ-SIIC-004).
 *
 *   1. two at once: two drafts issued at the same moment take two
 *      consecutive numbers of the sales series, and the series' last value is
 *      the higher one (REQ-SIIC-001);
 *   2. self-billed: an issued self-billed invoice keeps a number of its own,
 *      which the series check reports outside the series when it does not
 *      look like one (REQ-SIIC-001);
 *   3. deleted draft: deleting a draft does not move the series, so the next
 *      issued invoice follows the last issued one (REQ-SIIC-001);
 *   4. renumber: a change to the number of an issued invoice is refused and
 *      its detail page offers no field to type one (REQ-SIIC-002);
 *   5. approval: a draft above the administration's threshold is refused on
 *      issue, Request approval is offered and moves it to awaiting-approval,
 *      and its creator is refused on approve; a draft at or below the
 *      threshold issues directly (REQ-SIIC-003);
 *   6. gap report: the invoice number check lists exactly the missing and
 *      duplicate numbers of the year's issued invoices, and opens from its
 *      Reports card (REQ-SIIC-004).
 *
 * Tests 1 and the small-invoice case of 5 issue real invoices in the
 * caller's active administration and leave them: removing an issued invoice
 * would itself break the series these tests protect. Their drafts are made
 * through the quick-draft endpoint for a customer of the administration, and
 * a draft the instance's other issue rules refuse is deleted and the test
 * skipped. Tests 3 and 5 (above the threshold) remove what they make: the
 * approval request is rejected back to draft and the draft deleted. The
 * caller is the suite's administrator (globalSetup's storage state), the one
 * signed-in user, so a second person's approval is a fixme.
 *
 * Written, not run: it needs a live instance.
 *
 * Seen while writing: quick-draft gives a draft a provisional DRAFT-...
 * number, so not every draft is without a number as REQ-SIIC-001 says; the
 * issue transition replaces it.
 *
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-issue-controls/specs/bookkeeping-accounts-receivable-core/spec.md
 * @spec openspec/changes/archive/2026-11-24-sales-invoice-issue-controls/tasks.md#task-4.1
 *
 * @e2e bookkeeping-accounts-receivable-core::two-bookkeepers-issue-at-the-same-moment
 * @e2e bookkeeping-accounts-receivable-core::a-self-billed-invoice-keeps-the-customer-s-number
 * @e2e bookkeeping-accounts-receivable-core::a-deleted-draft-leaves-no-gap
 * @e2e bookkeeping-accounts-receivable-core::a-bookkeeper-tries-to-renumber-an-issued-invoice
 * @e2e bookkeeping-accounts-receivable-core::a-large-invoice-waits-for-approval
 * @e2e bookkeeping-accounts-receivable-core::the-creator-cannot-approve
 * @e2e bookkeeping-accounts-receivable-core::a-small-invoice-issues-directly
 * @e2e bookkeeping-accounts-receivable-core::a-controller-checks-2026
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OR = '/index.php/apps/openregister/api/objects'
const OBJECTS = `${OR}/shillinq`
const HEADERS = { 'OCS-APIRequest': 'true' }
const NUMBERED_STATES = ['issued', 'paid', 'overdue', 'disputed', 'written-off']
const YEAR = new Date().getFullYear()

/** The part of an ARInvoice this spec reads. */
interface ARInvoice {
	id?: string
	invoiceNumber?: string
	administrationId: string
	invoiceDate: string
	grossAmount: number
	lifecycleState: string
	selfBilled?: boolean
	invoiceType?: string
	'@self'?: { id?: string }
}

/** An InvoiceNumberSequence. */
interface NumberSequence {
	administrationId: string
	kind: 'sales' | 'billable' | 'credit'
	pattern: string
	fiscalYear: number
	lastValue: number
}

/** The part of an Administration this spec reads. */
interface Administration {
	salesInvoiceApprovalThreshold?: number | null
}

/** The invoice number check answer (InvoiceNumberCheckController::check). */
interface NumberCheck {
	year: number
	administrationId: string
	missing: string[]
	duplicates: string[]
	outsideSeries: string[]
	missingCount: number
	duplicateCount: number
	rows: Array<{ number: string, finding: string }>
}

/** The part of the administration context this spec reads. */
interface AdministrationContext {
	activeAdministrationId?: string
}

/** A transition answer: whether it went through, and what the server said. */
interface TransitionAnswer {
	ok: boolean
	status: number
	text: string
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
	for (let page = 1; page <= 50; page++) {
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
 * The administration's approval threshold, or null when none is set.
 *
 * @param request          The request context.
 * @param administrationId The administration.
 * @return The threshold.
 */
async function threshold(request: APIRequestContext, administrationId: string): Promise<number | null> {
	let res = await request.get(`${OBJECTS}/Administration/${encodeURIComponent(administrationId)}`, { headers: HEADERS })
	let administration: Administration | undefined
	if (res.ok()) {
		administration = (await res.json()) as Administration
	} else {
		res = await request.get(`${OBJECTS}/Administration?administrationCode=${encodeURIComponent(administrationId)}`, { headers: HEADERS })
		administration = ((await res.json()) as { results?: Administration[] }).results?.[0]
	}
	const value = administration?.salesInvoiceApprovalThreshold
	return value === null || value === undefined ? null : Number(value)
}

/**
 * The administration's sales series for this year, when it has one.
 *
 * @param request          The request context.
 * @param administrationId The administration.
 * @return The sequence.
 */
async function salesSequence(request: APIRequestContext, administrationId: string): Promise<NumberSequence | undefined> {
	return (await listAll<NumberSequence>(request, 'InvoiceNumberSequence', { administrationId, kind: 'sales' }))
		.find((sequence) => Number(sequence.fiscalYear) === YEAR)
}

/**
 * A regular expression for the numbers of a pattern in a year, capturing the counter.
 *
 * @param pattern The series pattern, such as `{year}-{seq:4}`.
 * @param year    The year.
 * @return The expression.
 */
function seriesRegex(pattern: string, year: number): RegExp {
	const source = pattern.split(/(\{year\}|\{seq:\d+\})/).map((part) => {
		if (part === '{year}') {
			return String(year)
		}
		const seq = /^\{seq:(\d+)\}$/.exec(part)
		if (seq !== null) {
			return `(\\d{${seq[1]},})`
		}
		return part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	}).join('')
	return new RegExp(`^${source}$`)
}

/**
 * A draft for the first customer of the administration, through quick-draft.
 *
 * @param request          The request context.
 * @param administrationId The administration.
 * @param unitPrice        The net price of its one line, at 21 % VAT.
 * @return The draft's id, or '' when the administration has no customer.
 */
async function draft(request: APIRequestContext, administrationId: string, unitPrice: number): Promise<string> {
	const customer = (await listAll<{ customerId?: string, id?: string, '@self'?: { id?: string } }>(request, 'CustomerMaster', { administrationId }))[0]
	if (customer === undefined) {
		return ''
	}
	const today = new Date().toISOString().slice(0, 10)
	const due = new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString().slice(0, 10)
	const res = await request.post(`${API}/ar-invoices/quick-drafts`, {
		headers: HEADERS,
		data: {
			administrationId,
			customerId: customer.customerId ?? idOf(customer),
			invoiceDate: today,
			dueDate: due,
			reference: `e2e issue controls ${Date.now()}`,
			lines: [{ description: 'e2e advisory work', quantity: 1, unitPrice, vatRate: 21 }],
		},
	})
	expect(res.status(), await res.text()).toBe(201)
	return idOf((await res.json()) as ARInvoice)
}

/**
 * Run a lifecycle transition on an invoice.
 *
 * @param request The request context.
 * @param id      The invoice id.
 * @param action  The transition.
 * @param data    Its inputs.
 * @return The answer.
 */
async function transition(request: APIRequestContext, id: string, action: string, data: Record<string, string> = {}): Promise<TransitionAnswer> {
	const res = await request.post(`${OR}/${encodeURIComponent(id)}/transition`, { headers: HEADERS, data: { action, data } })
	return { ok: res.ok(), status: res.status(), text: await res.text() }
}

/**
 * One invoice by id.
 *
 * @param request The request context.
 * @param id      The id.
 * @return The invoice.
 */
async function invoice(request: APIRequestContext, id: string): Promise<ARInvoice> {
	const res = await request.get(`${OBJECTS}/ARInvoice/${encodeURIComponent(id)}`, { headers: HEADERS })
	expect(res.ok(), await res.text()).toBe(true)
	return (await res.json()) as ARInvoice
}

/**
 * Delete an invoice that is still a draft.
 *
 * @param request The request context.
 * @param id      The id.
 */
async function removeDraft(request: APIRequestContext, id: string): Promise<void> {
	if (id === '') {
		return
	}
	const stored = await request.get(`${OBJECTS}/ARInvoice/${encodeURIComponent(id)}`, { headers: HEADERS })
	if (stored.ok() && ((await stored.json()) as ARInvoice).lifecycleState === 'awaiting-approval') {
		await transition(request, id, 'rejectApproval', { approvalRejectionReason: 'e2e clean-up' })
	}
	await request.delete(`${OBJECTS}/ARInvoice/${encodeURIComponent(id)}`, { headers: HEADERS }).catch(() => undefined)
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/**
 * Open an app route past a first-run wizard.
 *
 * @param page  The page.
 * @param route The route from the app root.
 */
async function open(page: Page, route: string): Promise<void> {
	await page.goto(APP + route)
	await page.waitForLoadState('domcontentloaded')
	await dismissOverlays(page)
}

test.describe('sales-invoice-issue-controls: numbering', () => {
	test('two drafts issued at once take two consecutive numbers @spec REQ-SIIC-001', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const limit = await threshold(request, administrationId)
		const price = limit === null || limit >= 1210 ? 1000 : 1

		const first = await draft(request, administrationId, price)
		test.skip(first === '', 'the administration has no customer to invoice')
		const second = await draft(request, administrationId, price)
		const answers = await Promise.all([transition(request, first, 'issue'), transition(request, second, 'issue')])
		if (!answers.every((answer) => answer.ok)) {
			await removeDraft(request, first)
			await removeDraft(request, second)
			test.skip(true, `the instance refuses to issue the e2e drafts: ${answers.map((answer) => answer.text).join(' / ').slice(0, 300)}`)
		}

		const sequence = await salesSequence(request, administrationId)
		expect(sequence, 'issuing made or used this year\'s sales series').toBeDefined()
		const regex = seriesRegex(sequence!.pattern, YEAR)
		const numbers = [await invoice(request, first), await invoice(request, second)].map((issued) => String(issued.invoiceNumber))
		const counters = numbers.map((number) => {
			const match = regex.exec(number)
			expect(match, `${number} follows ${sequence!.pattern}`).not.toBeNull()
			return Number(match![1])
		}).sort((a, b) => a - b)

		expect(numbers[0], 'two different numbers').not.toBe(numbers[1])
		expect(counters[1] - counters[0], 'consecutive numbers').toBe(1)
		expect(Number(sequence!.lastValue), 'the series stands at the higher one').toBeGreaterThanOrEqual(counters[1])
	})

	test('an issued self-billed invoice keeps its own number @spec REQ-SIIC-001', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const selfBilled = (await listAll<ARInvoice>(request, 'ARInvoice', { administrationId }))
			.filter((candidate) => candidate.selfBilled === true && NUMBERED_STATES.includes(candidate.lifecycleState) && candidate.invoiceDate.startsWith(String(YEAR)))
		test.skip(selfBilled.length === 0, 'no self-billed invoice was issued this year')
		const sequence = await salesSequence(request, administrationId)
		const regex = seriesRegex(sequence?.pattern ?? '{year}-{seq:4}', YEAR)

		const res = await request.get(`${API}/sales/invoice-number-check?administrationId=${encodeURIComponent(administrationId)}&year=${YEAR}`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const check = (await res.json()) as NumberCheck
		for (const issued of selfBilled) {
			const number = String(issued.invoiceNumber ?? '').trim()
			expect(number, `${idOf(issued)} keeps the customer's number`).not.toBe('')
			if (!regex.test(number) && number.includes(String(YEAR))) {
				expect(check.outsideSeries, `${number} is not one of the seller's`).toContain(number)
			}
		}
	})

	test('deleting a draft leaves the series where it was @spec REQ-SIIC-001', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const before = await salesSequence(request, administrationId)

		const id = await draft(request, administrationId, 1)
		test.skip(id === '', 'the administration has no customer to invoice')
		try {
			const drafted = await invoice(request, id)
			expect(drafted.lifecycleState).toBe('draft')
			if (before !== undefined && drafted.invoiceNumber) {
				expect(seriesRegex(before.pattern, YEAR).test(drafted.invoiceNumber), 'a draft takes no number of the series').toBe(false)
			}
		} finally {
			await removeDraft(request, id)
		}

		const after = await salesSequence(request, administrationId)
		expect(after?.lastValue ?? null, 'the series did not move').toBe(before?.lastValue ?? null)
	})

	test('the number of an issued invoice cannot be changed @spec REQ-SIIC-002', async ({ page, request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const issued = (await listAll<ARInvoice>(request, 'ARInvoice', { administrationId, lifecycleState: 'issued' }))
			.find((candidate) => Boolean(candidate.invoiceNumber))
		test.skip(issued === undefined, 'the administration has no issued invoice')
		const id = idOf(issued!)
		const stored = await invoice(request, id)
		const body: Partial<ARInvoice> = { ...stored }
		delete body['@self']

		try {
			const res = await request.put(`${OBJECTS}/ARInvoice/${encodeURIComponent(id)}`, { headers: HEADERS, data: { ...body, invoiceNumber: `${stored.invoiceNumber}-E2E` } })
			expect(res.ok(), 'the change is refused').toBe(false)
			expect(await res.text()).toMatch(/cannot be changed|kan niet worden gewijzigd/i)
		} finally {
			if ((await invoice(request, id)).invoiceNumber !== stored.invoiceNumber) {
				await request.put(`${OBJECTS}/ARInvoice/${encodeURIComponent(id)}`, { headers: HEADERS, data: body })
			}
		}
		expect((await invoice(request, id)).invoiceNumber).toBe(stored.invoiceNumber)

		await open(page, `/bookkeeping/accounts-receivable/${encodeURIComponent(id)}`)
		await expect(page.getByText(String(stored.invoiceNumber)).first()).toBeVisible({ timeout: 20_000 })
		const editable = page.locator('input:not([readonly]):not([disabled]), textarea:not([readonly]):not([disabled])')
		for (const field of await editable.all()) {
			expect(await field.inputValue(), 'no field holds the number for editing').not.toBe(stored.invoiceNumber)
		}
	})
})

test.describe('sales-invoice-issue-controls: approval', () => {
	test('a draft above the threshold is refused on issue and sent for approval @spec REQ-SIIC-003', async ({ page, request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const limit = await threshold(request, administrationId)
		test.skip(limit === null, 'the administration has no approval threshold')

		const id = await draft(request, administrationId, Math.ceil(limit!) + 100)
		test.skip(id === '', 'the administration has no customer to invoice')
		try {
			const refused = await transition(request, id, 'issue')
			expect(refused.ok, 'issuing from draft is refused').toBe(false)
			expect(refused.text).toMatch(/approval threshold|goedkeuringsdrempel/i)
			expect((await invoice(request, id)).lifecycleState).toBe('draft')

			await open(page, `/bookkeeping/accounts-receivable/${encodeURIComponent(id)}`)
			await expect(page.getByRole('button', { name: /^(Request approval|Goedkeuring aanvragen)$/i })).toBeVisible({ timeout: 20_000 })

			const sent = await transition(request, id, 'requestApproval')
			expect(sent.ok, sent.text).toBe(true)
			expect((await invoice(request, id)).lifecycleState).toBe('awaiting-approval')
		} finally {
			await removeDraft(request, id)
		}
	})

	test('the creator of an invoice cannot approve it @spec REQ-SIIC-003', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const limit = await threshold(request, administrationId)
		test.skip(limit === null, 'the administration has no approval threshold')

		const id = await draft(request, administrationId, Math.ceil(limit!) + 100)
		test.skip(id === '', 'the administration has no customer to invoice')
		try {
			const sent = await transition(request, id, 'requestApproval')
			expect(sent.ok, sent.text).toBe(true)

			const refused = await transition(request, id, 'approve')
			expect(refused.ok, 'the creator is refused').toBe(false)
			expect(refused.text).toMatch(/You created this invoice|cannot approve/i)
			expect((await invoice(request, id)).lifecycleState).toBe('awaiting-approval')
		} finally {
			await removeDraft(request, id)
		}
	})

	test.fixme('after another user approves it, Issue succeeds @spec REQ-SIIC-003', async () => {
		// Missing: the suite signs in one administrator only (globalSetup); approving needs a second user of the administration.
	})

	test('a draft at or below the threshold issues directly @spec REQ-SIIC-003', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const limit = await threshold(request, administrationId)
		const price = limit === null || limit >= 1210 ? 1000 : 1

		const id = await draft(request, administrationId, price)
		test.skip(id === '', 'the administration has no customer to invoice')
		const issued = await transition(request, id, 'issue')
		if (!issued.ok) {
			await removeDraft(request, id)
		}
		expect(issued.text, 'no approval was asked for').not.toMatch(/approval threshold/i)
		test.skip(!issued.ok, `the instance refuses to issue the e2e draft: ${issued.text.slice(0, 300)}`)

		const stored = await invoice(request, id)
		expect(stored.lifecycleState).toBe('issued')
		expect(stored.invoiceNumber, 'it took a number').toBeTruthy()
	})
})

test.describe('sales-invoice-issue-controls: invoice number check', () => {
	test('the check lists exactly the missing and duplicate numbers of the year @spec REQ-SIIC-004', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const res = await request.get(`${API}/sales/invoice-number-check?administrationId=${encodeURIComponent(administrationId)}&year=${YEAR}`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const check = (await res.json()) as NumberCheck
		expect(check.year).toBe(YEAR)
		expect(check.missingCount).toBe(check.missing.length)
		expect(check.duplicateCount).toBe(check.duplicates.length)
		expect(check.rows).toHaveLength(check.missing.length + check.duplicates.length)

		const sequence = await salesSequence(request, administrationId)
		const pattern = sequence?.pattern ?? '{year}-{seq:4}'
		const width = Number(/\{seq:(\d+)\}/.exec(pattern)?.[1] ?? 4)
		const regex = seriesRegex(pattern, YEAR)
		const format = (counter: number): string => pattern.replace('{year}', String(YEAR)).replace(/\{seq:\d+\}/, String(counter).padStart(width, '0'))

		const counts = new Map<number, number>()
		for (const issued of await listAll<ARInvoice>(request, 'ARInvoice', { administrationId })) {
			const match = NUMBERED_STATES.includes(issued.lifecycleState) ? regex.exec(String(issued.invoiceNumber ?? '').trim()) : null
			if (match !== null) {
				counts.set(Number(match[1]), (counts.get(Number(match[1])) ?? 0) + 1)
			}
		}
		const counters = [...counts.keys()].sort((a, b) => a - b)
		const missing: string[] = []
		for (let counter = 1; counter < (counters.at(-1) ?? 0); counter++) {
			if (!counts.has(counter)) {
				missing.push(format(counter))
			}
		}
		const duplicates = counters.filter((counter) => (counts.get(counter) ?? 0) > 1).map(format)

		expect(check.missing, 'the missing numbers').toEqual(missing)
		expect(check.duplicates, 'the duplicate numbers').toEqual(duplicates)
	})

	test('the invoice number check opens from its Reports card @spec REQ-SIIC-004', async ({ page }) => {
		await open(page, '/reports')
		await page.getByText(/^(Invoice number check|Controle factuurnummers)$/i).first().click({ timeout: 20_000 })
		await expect(page).toHaveURL(/\/reports\/invoice-number-check/)
		const findings = page.getByText(/^(Findings|Bevindingen)$/i).first()
		await expect(findings).toBeVisible({ timeout: 20_000 })
	})
})
