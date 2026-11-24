/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the close checklist, its templates, deferrals and accruals
 * (ledger-period-end task 4.1, REQ-LPE-001 to REQ-LPE-006).
 *
 *   1. start close: a period that is closing or closed carries well formed
 *      checklist items, and its close page lists them; an open period offers
 *      Start close (REQ-LPE-001);
 *   2. resolve: Resolve on an open item of an open or closing period shows it
 *      resolved with the user's name and a time; the spec reopens the item
 *      afterwards (REQ-LPE-002);
 *   3. read-only: a closed or audit-locked period's close page offers no
 *      Resolve, Reopen or Add item, and the server refuses a new item
 *      (REQ-LPE-002);
 *   4. templates: the close checklist templates page lists the templates and
 *      opens one; adding a task to Maandafsluiting makes it one task longer,
 *      and the spec puts the template back afterwards (REQ-LPE-003);
 *   5. spread: the preview of the licence of design.md (EUR 1,200 over 2026)
 *      gives twelve releases of EUR 100, the seeded schedule's opening entry
 *      moves the total from 4300 to 1900, and Spread over periods on a
 *      supplier invoice line shows the releases (REQ-LPE-004). The preview
 *      writes nothing; the dialog is cancelled before Spread the line;
 *   6. release: every release a schedule recorded names a posted entry moving
 *      its amount from the balance account back to the result account, the
 *      releases add up to the total, and a completed schedule has no release
 *      left (REQ-LPE-005);
 *   7. accrual: an accrual is booked once per rule, period and version, the
 *      entry it names debits the target and credits the contra account, and a
 *      reversed accrual names its posted mirror entry (REQ-LPE-006).
 *
 * The nightly run itself (task 4.2) is driven by occ, not by this spec, so
 * REQ-LPE-005 and REQ-LPE-006 are checked on what earlier runs left behind.
 * Invariants are asserted on whatever the instance holds, with the seed
 * records of design.md (the Maandafsluiting template, the licence schedule)
 * used where the design names them; a missing record skips its test. The
 * caller is the suite's administrator (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance.
 *
 * Known gap seen while writing: PeriodCloseDetail.vue calls
 * /api/period-close/{id} without `administration_id`, which
 * PeriodCloseController requires, so the page tests below fail until the
 * page sends it.
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-period-close/spec.md
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md
 * @spec openspec/changes/ledger-period-end/tasks.md#task-4.1
 *
 * @e2e bookkeeping-period-close::a-controller-starts-closing-september
 * @e2e bookkeeping-period-close::a-bookkeeper-resolves-the-bank-item
 * @e2e bookkeeping-period-close::a-closed-period-s-items-are-read-only
 * @e2e bookkeeping-period-close::an-administrator-adds-a-task-to-the-template
 * @e2e bookkeeping-continuous-close::a-bookkeeper-spreads-an-annual-licence
 * @e2e bookkeeping-continuous-close::january-s-share-is-released-after-january-ends
 * @e2e bookkeeping-continuous-close::the-energy-accrual-reaches-the-ledger
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const CATEGORIES = ['ap', 'ar', 'bank', 'expense-claims', 'vat', 'accruals', 'other']

/** One checklist item on a period. */
interface ChecklistItem {
	id: string
	category: string
	description: string
	resolved: boolean
	resolvedAt: string | null
	resolvedBy: string | null
}

/** The part of a FiscalPeriod this spec reads. */
interface FiscalPeriod {
	id?: string
	periodId?: string
	administrationId?: string
	state?: string
	taskChecklistItems?: ChecklistItem[]
	'@self'?: { id?: string }
}

/** One task of a CloseChecklistTemplate. */
interface TemplateTask {
	taskId?: string
	taskName: string
	category?: string
	taskOwner?: string
	dueBefore?: string
}

/** A CloseChecklistTemplate. */
interface ChecklistTemplate {
	id?: string
	templateName: string
	administrationTypeId: string
	tasks: TemplateTask[]
	'@self'?: { id?: string }
}

/** One release of a DeferralSchedule. */
interface Release {
	periodId: string
	periodEnd?: string
	amountCents: number
	journalEntryId: string | null
}

/** A DeferralSchedule, stored or previewed. */
interface DeferralSchedule {
	id?: string
	administrationId: string
	kind: 'prepaid-cost' | 'deferred-revenue'
	resultAccount: string
	balanceAccount: string
	totalCents: number
	openingJournalEntryId: string | null
	releases: Release[]
	state: string
	'@self'?: { id?: string, slug?: string }
}

/** One line of a JournalEntry. */
interface JournalLine {
	accountNumber: string
	side: 'debit' | 'credit'
	amount: number
}

/** The part of a JournalEntry this spec reads. */
interface JournalEntry {
	id?: string
	journalNumber: string
	state: string
	lines: JournalLine[]
	'@self'?: { id?: string }
}

/** An AutoAccrualRule. */
interface AccrualRule {
	id?: string
	ruleName: string
	targetGLAccount: string
	contraGLAccount: string
	'@self'?: { id?: string }
}

/** An AutoAccrualPosting. */
interface AccrualPosting {
	ruleId: string
	ruleVersion: number
	periodId: string
	amountCents: number
	journalEntryId: string
	reversalId: string | null
	reversalState: string
}

/** The part of the administration context this spec reads. */
interface AdministrationContext {
	activeAdministrationId?: string
}

/** A list answer of OpenRegister. */
interface ObjectList<T> {
	results?: T[]
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
		const rows = ((await res.json()) as ObjectList<T>).results ?? []
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
 * The signed-in user's id.
 *
 * @param request The request context.
 * @return The uid.
 */
async function currentUser(request: APIRequestContext): Promise<string> {
	const res = await request.get('/ocs/v2.php/cloud/user?format=json', { headers: HEADERS })
	expect(res.ok()).toBe(true)
	const body = (await res.json()) as { ocs: { data: { id: string } } }
	return body.ocs.data.id
}

/**
 * The period as the period close endpoint answers it.
 *
 * @param request          The request context.
 * @param periodId         The period's id.
 * @param administrationId The administration.
 * @return The period.
 */
async function periodClose(request: APIRequestContext, periodId: string, administrationId: string): Promise<FiscalPeriod> {
	const res = await request.get(`${API}/period-close/${encodeURIComponent(periodId)}?administration_id=${encodeURIComponent(administrationId)}`, { headers: HEADERS })
	expect(res.status(), await res.text()).toBe(200)
	return ((await res.json()) as { data: FiscalPeriod }).data
}

/**
 * A journal entry by its id, or null when there is none.
 *
 * @param request The request context.
 * @param id      The entry id.
 * @return The entry.
 */
async function journalEntry(request: APIRequestContext, id: string): Promise<JournalEntry | null> {
	const res = await request.get(`${OBJECTS}/JournalEntry/${encodeURIComponent(id)}`, { headers: HEADERS })
	return res.ok() ? (await res.json()) as JournalEntry : null
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

/**
 * Assert an entry moves an amount from one account to another.
 *
 * @param entry  The entry.
 * @param debit  The debited account.
 * @param credit The credited account.
 * @param amount The amount in cents.
 * @param label  What the entry is, for the message.
 */
function expectMove(entry: JournalEntry, debit: string, credit: string, amount: number, label: string): void {
	expect(entry.state, `${label} is posted`).toBe('posted')
	const debits = entry.lines.filter((line) => line.side === 'debit')
	const credits = entry.lines.filter((line) => line.side === 'credit')
	expect(debits.map((line) => line.accountNumber), `${label} debits ${debit}`).toEqual([debit])
	expect(credits.map((line) => line.accountNumber), `${label} credits ${credit}`).toEqual([credit])
	expect(cents(debits[0].amount), `${label} moves the amount`).toBe(amount)
	expect(cents(credits[0].amount)).toBe(amount)
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

test.describe('ledger-period-end: the close checklist', () => {
	test('a closing period carries its checklist and its close page lists it @spec REQ-LPE-001', async ({ page, request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const periods = await listAll<FiscalPeriod>(request, 'FiscalPeriod', { administrationId })
		const started = periods.filter((period) => ['closing', 'closed', 'audit-locked'].includes(period.state ?? '') && (period.taskChecklistItems ?? []).length > 0)
		test.skip(started.length === 0, 'no period of the administration has started closing with a checklist')

		for (const period of started) {
			for (const item of period.taskChecklistItems ?? []) {
				const label = `${period.periodId}: ${item.description}`
				expect(item.id, `${label} has an id`).not.toBe('')
				expect(CATEGORIES, `${label} has a known category`).toContain(item.category)
				expect(item.description.trim(), `${label} has a description`).not.toBe('')
				if (item.resolved) {
					expect(item.resolvedBy, `${label} names who resolved it`).toBeTruthy()
					expect(item.resolvedAt, `${label} says when`).toBeTruthy()
				} else {
					expect(item.resolvedBy ?? null, `${label} is open, so nobody resolved it`).toBeNull()
				}
			}
		}

		const period = started.find((candidate) => candidate.state === 'closing') ?? started[0]
		const answered = await periodClose(request, idOf(period), administrationId)
		const items = answered.taskChecklistItems ?? []
		await open(page, `/bookkeeping/period-close/${encodeURIComponent(idOf(period))}`)
		await expect(page.locator('[data-testid="period-close-detail-checklist"]')).toBeVisible({ timeout: 20_000 })
		for (const item of items) {
			const row = page.locator(`[data-testid="period-close-detail-checklist-${item.id}"]`)
			await expect(row, `row ${item.description}`).toBeVisible()
			await expect(row).toContainText(item.description)
			await expect(row).toContainText(item.resolved ? /Resolved|Opgelost/ : /Open/)
		}
	})

	test('an open period offers Start close on its close page @spec REQ-LPE-001', async ({ page, request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const period = (await listAll<FiscalPeriod>(request, 'FiscalPeriod', { administrationId })).find((candidate) => (candidate.state ?? 'open') === 'open')
		test.skip(period === undefined, 'the administration has no open period')

		await open(page, `/bookkeeping/period-close/${encodeURIComponent(idOf(period!))}`)
		await expect(page.locator('[data-testid="period-close-detail-start-close"]')).toBeVisible({ timeout: 20_000 })
	})

	test('Resolve shows the item resolved with who and when @spec REQ-LPE-002', async ({ page, request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const periods = await listAll<FiscalPeriod>(request, 'FiscalPeriod', { administrationId })
		const period = periods.find((candidate) => ['open', 'closing'].includes(candidate.state ?? 'open')
			&& (candidate.taskChecklistItems ?? []).some((item) => !item.resolved))
		test.skip(period === undefined, 'no open or closing period has an open checklist item')
		const periodId = idOf(period!)
		const item = (period!.taskChecklistItems ?? []).find((candidate) => !candidate.resolved)!
		const user = await currentUser(request)

		try {
			await open(page, `/bookkeeping/period-close/${encodeURIComponent(periodId)}`)
			await page.locator(`[data-testid="period-close-detail-checklist-resolve-${item.id}"]`).click({ timeout: 20_000 })
			const row = page.locator(`[data-testid="period-close-detail-checklist-${item.id}"]`)
			await expect(row).toContainText(/Resolved|Opgelost/, { timeout: 20_000 })
			await expect(row).toContainText(user)

			const stored = (await periodClose(request, periodId, administrationId)).taskChecklistItems?.find((candidate) => candidate.id === item.id)
			expect(stored?.resolved).toBe(true)
			expect(stored?.resolvedBy).toBe(user)
			expect(Number.isNaN(Date.parse(String(stored?.resolvedAt))), 'resolvedAt is a time').toBe(false)
		} finally {
			// Put the item back the way it was: open, nobody, no time.
			await request.post(`${API}/period-close/${encodeURIComponent(periodId)}/checklist/${encodeURIComponent(item.id)}/reopen?administration_id=${encodeURIComponent(administrationId)}`, { headers: HEADERS })
		}
		const restored = (await periodClose(request, periodId, administrationId)).taskChecklistItems?.find((candidate) => candidate.id === item.id)
		expect(restored?.resolved).toBe(false)
		expect(restored?.resolvedBy ?? null).toBeNull()
	})

	test('a closed period offers no item actions and refuses a new item @spec REQ-LPE-002', async ({ page, request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const period = (await listAll<FiscalPeriod>(request, 'FiscalPeriod', { administrationId })).find((candidate) => ['closed', 'audit-locked'].includes(candidate.state ?? ''))
		test.skip(period === undefined, 'the administration has no closed or audit-locked period')
		const periodId = idOf(period!)
		const before = (await periodClose(request, periodId, administrationId)).taskChecklistItems ?? []

		const refused = await request.post(`${API}/period-close/${encodeURIComponent(periodId)}/checklist?administration_id=${encodeURIComponent(administrationId)}`, {
			headers: HEADERS,
			data: { category: 'other', description: 'e2e: must be refused on a closed period' },
		})
		expect(refused.status(), await refused.text()).toBe(409)
		expect((await periodClose(request, periodId, administrationId)).taskChecklistItems ?? []).toEqual(before)

		await open(page, `/bookkeeping/period-close/${encodeURIComponent(periodId)}`)
		await expect(page.locator('[data-testid="period-close-detail-checklist"]')).toBeVisible({ timeout: 20_000 })
		await expect(page.locator('[data-testid^="period-close-detail-checklist-resolve-"]')).toHaveCount(0)
		await expect(page.locator('[data-testid^="period-close-detail-checklist-reopen-"]')).toHaveCount(0)
		await expect(page.locator('[data-testid="period-close-detail-checklist-add"]')).toHaveCount(0)
	})
})

test.describe('ledger-period-end: close checklist templates', () => {
	test('the templates page lists the templates and opens one @spec REQ-LPE-003', async ({ page, request }) => {
		const templates = await listAll<ChecklistTemplate>(request, 'CloseChecklistTemplate')
		test.skip(templates.length === 0, 'the instance has no close checklist template')
		const template = templates.find((candidate) => candidate.templateName === 'Maandafsluiting') ?? templates[0]

		await open(page, '/settings/close-checklist-templates')
		const row = page.getByRole('row').filter({ hasText: template.templateName }).first()
		await expect(row).toBeVisible({ timeout: 20_000 })
		await row.click()
		await expect(page).toHaveURL(/\/settings\/close-checklist-templates\/.+/)
		await expect(page.getByText(template.tasks[0]?.taskName ?? template.templateName).first()).toBeVisible({ timeout: 20_000 })
	})

	test('adding a task to Maandafsluiting makes the template one task longer @spec REQ-LPE-003', async ({ request }) => {
		const template = (await listAll<ChecklistTemplate>(request, 'CloseChecklistTemplate', { templateName: 'Maandafsluiting' }))[0]
		test.skip(template === undefined, 'the seeded template Maandafsluiting is absent')
		const id = idOf(template)
		const original = template.tasks
		const body = (tasks: TemplateTask[]): Omit<ChecklistTemplate, '@self' | 'id'> => ({
			templateName: template.templateName,
			administrationTypeId: template.administrationTypeId,
			tasks,
		})

		try {
			const saved = await request.put(`${OBJECTS}/CloseChecklistTemplate/${encodeURIComponent(id)}`, {
				headers: HEADERS,
				data: body([...original, { taskId: 'e2e-payroll', taskName: 'loonjournaal geboekt', category: 'other' }]),
			})
			expect(saved.ok(), await saved.text()).toBe(true)
			const reread = (await (await request.get(`${OBJECTS}/CloseChecklistTemplate/${encodeURIComponent(id)}`, { headers: HEADERS })).json()) as ChecklistTemplate
			expect(reread.tasks).toHaveLength(original.length + 1)
			expect(reread.tasks.at(-1)?.taskName).toBe('loonjournaal geboekt')
			expect(reread.tasks.at(-1)?.category).toBe('other')
		} finally {
			await request.put(`${OBJECTS}/CloseChecklistTemplate/${encodeURIComponent(id)}`, { headers: HEADERS, data: body(original) })
		}
	})
})

test.describe('ledger-period-end: deferrals and accruals', () => {
	test('the licence spreads into twelve releases of EUR 100 @spec REQ-LPE-004', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')

		const res = await request.post(`${API}/deferral-schedules/preview`, {
			headers: HEADERS,
			data: {
				administrationId,
				sourceReference: `ap-invoice:E2E-${Date.now()}#line-1`,
				description: 'e2e annual software licence',
				kind: 'prepaid-cost',
				resultAccount: '4300',
				balanceAccount: '1900',
				totalCents: 120000,
				periodFrom: '2026-01-01',
				periodTo: '2026-12-31',
			},
		})
		expect(res.status(), await res.text()).toBe(200)
		const schedule = ((await res.json()) as { data: DeferralSchedule }).data

		expect(schedule.releases.map((release) => release.periodId)).toEqual(Array.from({ length: 12 }, (_, month) => `2026-${String(month + 1).padStart(2, '0')}`))
		expect(schedule.releases.map((release) => release.amountCents)).toEqual(Array(12).fill(10000))
		expect(schedule.openingJournalEntryId, 'a preview posts nothing').toBeNull()
	})

	test('the seeded licence schedule moved EUR 1,200 from 4300 to 1900 @spec REQ-LPE-004', async ({ request }) => {
		const schedule = (await listAll<DeferralSchedule>(request, 'DeferralSchedule', { sourceReference: 'ap-invoice:INK-2026-0007#line-1' }))[0]
		test.skip(schedule === undefined, 'the seeded licence schedule is absent')
		test.skip(!schedule.openingJournalEntryId, 'the seeded schedule was imported without its opening entry')

		const opening = await journalEntry(request, schedule.openingJournalEntryId!)
		expect(opening, 'the opening entry exists').not.toBeNull()
		expectMove(opening!, '1900', '4300', 120000, 'the opening entry')
		expect(schedule.releases).toHaveLength(12)
	})

	test('Spread over periods on a supplier invoice line shows the releases @spec REQ-LPE-004', async ({ page, request }) => {
		const invoice = (await listAll<{ id?: string, lines?: Array<{ lineNumber: number }>, '@self'?: { id?: string } }>(request, 'SupplierInvoice'))
			.find((candidate) => (candidate.lines ?? []).length > 0)
		test.skip(invoice === undefined, 'no supplier invoice with lines')
		const line = invoice!.lines![0]

		await open(page, `/inkoop/supplier-invoices/${encodeURIComponent(idOf(invoice!))}`)
		await page.locator(`[data-testid="si-detail-spread-${line.lineNumber}"]`).click({ timeout: 20_000 })
		const dialog = page.locator('[data-testid="spread-periods-modal"]')
		await expect(dialog).toBeVisible()
		await dialog.locator('input[data-testid="spread-periods-from"], [data-testid="spread-periods-from"] input').first().fill('2026-01-01')
		await dialog.locator('input[data-testid="spread-periods-to"], [data-testid="spread-periods-to"] input').first().fill('2026-12-31')
		await page.locator('[data-testid="spread-periods-preview"]').click()

		const releases = page.locator('[data-testid="spread-periods-releases"] tbody tr')
		await expect(releases).toHaveCount(12, { timeout: 20_000 })
		await expect(releases.first()).toContainText('2026-01')
		await expect(releases.last()).toContainText('2026-12')

		// Leave without Spread the line: nothing is written.
		await page.locator('[data-testid="spread-periods-cancel"]').click()
		await expect(dialog).toBeHidden()
	})

	test('each recorded release is a posted entry back to the result account @spec REQ-LPE-005', async ({ request }) => {
		const schedules = await listAll<DeferralSchedule>(request, 'DeferralSchedule')
		test.skip(schedules.length === 0, 'the instance has no deferral schedule')

		let released = 0
		for (const schedule of schedules) {
			const label = idOf(schedule)
			const total = schedule.releases.reduce((sum, release) => sum + release.amountCents, 0)
			expect(total, `${label}: the releases add up to the total`).toBe(schedule.totalCents)

			const ids = schedule.releases.map((release) => release.journalEntryId).filter((id): id is string => Boolean(id))
			expect(new Set(ids).size, `${label}: no release is posted twice`).toBe(ids.length)
			if (schedule.state === 'completed') {
				expect(ids.length, `${label}: a completed schedule has released every period`).toBe(schedule.releases.length)
			}

			const [debit, credit] = schedule.kind === 'prepaid-cost'
				? [schedule.resultAccount, schedule.balanceAccount]
				: [schedule.balanceAccount, schedule.resultAccount]
			for (const release of schedule.releases.filter((candidate) => candidate.journalEntryId)) {
				const entry = await journalEntry(request, release.journalEntryId!)
				expect(entry, `${label} ${release.periodId}: the release entry exists`).not.toBeNull()
				expectMove(entry!, debit, credit, release.amountCents, `${label} ${release.periodId}`)
				released++
			}
		}
		test.skip(released === 0, 'no nightly run has released a period yet')
	})

	test('an accrual is booked once per period and reversed by its mirror entry @spec REQ-LPE-006', async ({ request }) => {
		const postings = await listAll<AccrualPosting>(request, 'AutoAccrualPosting')
		test.skip(postings.length === 0, 'the instance has no accrual posting')
		const rules = await listAll<AccrualRule>(request, 'AutoAccrualRule')
		const ruleOf = (ruleId: string): AccrualRule | undefined => rules.find((rule) => idOf(rule) === ruleId || rule.ruleName === ruleId)

		const keys = postings.map((posting) => `${posting.ruleId}|${posting.periodId}|${posting.ruleVersion}`)
		expect(new Set(keys).size, 'one posting per rule, period and version').toBe(keys.length)

		let booked = 0
		for (const posting of postings) {
			const rule = ruleOf(posting.ruleId)
			const entries = await listAll<JournalEntry>(request, 'JournalEntry', { journalNumber: posting.journalEntryId })
			// Postings written before this change name an entry that was never written (design.md, Migration Plan).
			if (rule === undefined || entries.length === 0) {
				continue
			}
			expect(entries, `${posting.journalEntryId}: one entry`).toHaveLength(1)
			expectMove(entries[0], rule.targetGLAccount, rule.contraGLAccount, posting.amountCents, posting.journalEntryId)
			if (posting.reversalState === 'reversed') {
				expect(posting.reversalId).toBe(`${posting.journalEntryId}-rev`)
				const mirror = await listAll<JournalEntry>(request, 'JournalEntry', { journalNumber: posting.reversalId! })
				expect(mirror, `${posting.reversalId}: the reversal exists`).toHaveLength(1)
				expectMove(mirror[0], rule.contraGLAccount, rule.targetGLAccount, posting.amountCents, posting.reversalId!)
			}
			booked++
		}
		test.skip(booked === 0, 'no accrual posting names an entry in the ledger yet')
	})
})
