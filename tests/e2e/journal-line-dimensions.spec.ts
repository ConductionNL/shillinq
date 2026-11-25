/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a journal entry line carries a cost centre, a cost carrier
 * and a project onto the ledger, and posting refuses a code no active
 * dimension carries (ledger-journal-line-dimensions task 1.4, REQ-LJD-001,
 * REQ-LJD-002).
 *
 *   1. humaniq's payroll journal keeps its cost centres: on every posted
 *      journal entry whose lines carry codes, the GL lines of its transaction
 *      carry the same cost centres, cost carriers and projects (REQ-LJD-001);
 *   2. a cost centre humaniq knows but shillinq does not: posting a draft
 *      whose line 3 names cost centre KP-900 is refused naming the line and
 *      the code, and the entry stays a draft (REQ-LJD-002);
 *   3. a bookkeeper books a line on cost centre CC-100 in JournalDetail:
 *      the Lines field edits as a table (the library's sub-objects editor),
 *      the row dialog offers the active cost centres by name and stores the
 *      code (tasks.md 1.4).
 *
 * Scenario 1 reads whatever posted entries the instance holds and skips when
 * none carries a code; it does not post, since a posted entry cannot be
 * removed. The cost centre report half of that scenario needs humaniq's run
 * (tasks.md 1.5) and is not asserted. Scenario 2 creates one draft entry in
 * the caller's administration under this run's stamp and deletes it after.
 * Scenario 3 creates cost centre CC-100 when the administration has none and
 * a draft entry, and deletes both after.
 * The caller is the suite's administrator (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/ledger-journal-line-dimensions/specs/bookkeeping-general-ledger/spec.md
 * @spec openspec/changes/ledger-journal-line-dimensions/tasks.md#task-1.4
 *
 * @e2e bookkeeping-general-ledger::humaniq-s-payroll-journal-keeps-its-cost-centres
 * @e2e bookkeeping-general-ledger::a-cost-centre-humaniq-knows-but-shillinq-does-not
 * @e2e bookkeeping-general-ledger::a-bookkeeper-books-a-line-on-cost-centre-cc-100
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const OBJECTS = '/index.php/apps/openregister/api/objects'
const SHILLINQ = `${OBJECTS}/shillinq`
const HEADERS = { 'OCS-APIRequest': 'true' }
const RUN = `${Date.now()}`

/** The line fields posting copies onto the ledger line. */
const DIMENSION_FIELDS = ['costCenterCode', 'costCarrierCode', 'projectCode'] as const

type DimensionField = typeof DIMENSION_FIELDS[number]

/** A line of a journal entry or of the ledger, as far as this spec reads it. */
type DimensionLine = Partial<Record<DimensionField, string | null>> & {
	accountNumber: string
	side: string
	amount: number
}

/** An object as OpenRegister answers it. */
interface RegisterObject {
	id?: string
	uuid?: string
	'@self'?: { id?: string }
}

/** A journal entry. */
interface JournalEntry extends RegisterObject {
	journalNumber: string
	state: string
	glTransactionId?: string | null
	lines: DimensionLine[]
}

/** What this run created, removed afterwards. */
const created: string[] = []

/** Analytical dimensions this run created, removed afterwards. */
const createdDimensions: string[] = []

/**
 * The id OpenRegister answers an object under.
 *
 * @param object The object.
 * @return Its id.
 */
function idOf(object: RegisterObject): string {
	return String(object.id ?? object['@self']?.id ?? object.uuid ?? '')
}

/**
 * The rows of an OpenRegister listing.
 *
 * @param request The request context.
 * @param url     The listing url.
 * @return The rows.
 */
async function list<T>(request: APIRequestContext, url: string): Promise<T[]> {
	const res = await request.get(url, { headers: HEADERS })
	expect(res.status(), `${url}: ${await res.text()}`).toBe(200)
	const body = await res.json()
	return (body.results ?? body.items ?? []) as T[]
}

/**
 * The administration the admin works in, from shillinq's settings.
 *
 * @param request The request context.
 * @return The administration id, or '' when none is configured.
 */
async function administration(request: APIRequestContext): Promise<string> {
	const res = await request.get('/index.php/apps/shillinq/api/settings', { headers: HEADERS })
	if (!res.ok()) {
		return ''
	}
	return String((await res.json()).administration_id ?? '')
}

/**
 * The codes of one dimension on a set of lines, sorted, empty ones left out.
 *
 * @param lines The lines.
 * @param field The dimension field.
 * @return The codes.
 */
function codes(lines: DimensionLine[], field: DimensionField): string[] {
	return lines.map((line) => String(line[field] ?? '').trim()).filter((code) => code !== '').sort()
}

test.describe('ledger-journal-line-dimensions: dimension codes on journal lines', () => {
	test.afterAll(async ({ request }) => {
		for (const id of created) {
			await request.delete(`${SHILLINQ}/JournalEntry/${id}`, { headers: HEADERS }).catch(() => {})
		}
		for (const id of createdDimensions) {
			await request.delete(`${SHILLINQ}/AnalyticalDimension/${id}`, { headers: HEADERS }).catch(() => {})
		}
	})

	test('a posted entry keeps each line\'s cost centre, cost carrier and project on its ledger lines @spec REQ-LJD-001', async ({ request }) => {
		const posted = await list<JournalEntry>(request, `${SHILLINQ}/JournalEntry?state=posted&_limit=200`)
		const coded = posted
			.filter((entry) => String(entry.glTransactionId ?? '') !== '')
			.filter((entry) => DIMENSION_FIELDS.some((field) => codes(entry.lines ?? [], field).length > 0))
			.slice(0, 5)
		test.skip(coded.length === 0, 'no posted journal entry on this instance carries a dimension code on a line')

		for (const entry of coded) {
			const glLines = await list<DimensionLine>(request, `${SHILLINQ}/GLLine?transactionId=${encodeURIComponent(String(entry.glTransactionId))}&_limit=200`)
			for (const field of DIMENSION_FIELDS) {
				expect(codes(glLines, field), `${entry.journalNumber}: ${field} on the ledger`).toEqual(codes(entry.lines, field))
			}
		}
	})

	test('posting a line on an unknown cost centre is refused naming the line and the code @spec REQ-LJD-002', async ({ request }) => {
		const administrationId = await administration(request)
		test.skip(administrationId === '', 'no administration is configured for the admin')
		const known = await list<RegisterObject>(request, `${SHILLINQ}/AnalyticalDimension?code=KP-900&administrationId=${encodeURIComponent(administrationId)}&lifecycleState=active&_limit=5`)
		test.skip(known.length > 0, 'this administration has an active dimension KP-900, so the code is known here')

		const res = await request.post(`${SHILLINQ}/JournalEntry`, {
			headers: HEADERS,
			data: {
				journalNumber: `E2E-LJD-${RUN}`,
				entryDate: '2026-09-30',
				description: `e2e payroll journal with an unknown cost centre ${RUN}`,
				journalType: 'manual',
				approvalState: 'not-required',
				state: 'draft',
				administrationId,
				lines: [
					{ accountNumber: '1600', side: 'credit', amount: 100 },
					{ accountNumber: '4340', side: 'debit', amount: 60 },
					{ accountNumber: '4340', side: 'debit', amount: 40, costCenterCode: 'KP-900' },
				],
			},
		})
		expect(res.ok(), await res.text()).toBe(true)
		const id = idOf((await res.json()) as RegisterObject)
		created.push(id)

		const post = await request.post(`${OBJECTS}/${id}/transition`, { headers: HEADERS, data: { action: 'postDirect' } })
		expect(post.ok(), 'the post must be refused').toBe(false)
		const message = await post.text()
		expect(message).toMatch(/(Line|Regel) 3\b/)
		expect(message).toContain('KP-900')

		const after = (await (await request.get(`${SHILLINQ}/JournalEntry/${id}`, { headers: HEADERS })).json()) as JournalEntry
		expect(after.state).toBe('draft')
		expect(after.glTransactionId ?? null).toBeFalsy()
	})

	test('a bookkeeper books a line on cost centre CC-100 @spec REQ-LJD-001', async ({ page, request }) => {
		const administrationId = await administration(request)
		test.skip(administrationId === '', 'no administration is configured for the admin')

		let centre = (await list<RegisterObject & { name?: string }>(request, `${SHILLINQ}/AnalyticalDimension?code=CC-100&dimensionType=cost-center&lifecycleState=active&administrationId=${encodeURIComponent(administrationId)}&_limit=5`))[0]
		if (centre === undefined) {
			const made = await request.post(`${SHILLINQ}/AnalyticalDimension`, {
				headers: HEADERS,
				data: { code: 'CC-100', name: `E2E cost centre ${RUN}`, dimensionType: 'cost-center', lifecycleState: 'active', status: 'active', administrationId },
			})
			expect(made.ok(), await made.text()).toBe(true)
			centre = (await made.json()) as RegisterObject & { name?: string }
			createdDimensions.push(idOf(centre))
		}
		const centreName = String(centre.name ?? 'CC-100')

		const res = await request.post(`${SHILLINQ}/JournalEntry`, {
			headers: HEADERS,
			data: {
				journalNumber: `E2E-LJD-UI-${RUN}`,
				entryDate: '2026-09-30',
				description: `e2e line on a cost centre ${RUN}`,
				journalType: 'manual',
				approvalState: 'not-required',
				state: 'draft',
				administrationId,
				lines: [
					{ accountNumber: '4340', side: 'debit', amount: 100 },
					{ accountNumber: '1600', side: 'credit', amount: 100 },
				],
			},
		})
		expect(res.ok(), await res.text()).toBe(true)
		const id = idOf((await res.json()) as RegisterObject)
		created.push(id)

		await page.goto(`/index.php/apps/shillinq/journals/${id}`)
		await page.getByRole('button', { name: /^Edit Lines$/ }).click()

		// The lines are a table whose columns include the three codes.
		const editor = page.locator('.cn-sub-objects-field')
		await expect(editor.getByRole('columnheader', { name: 'Cost Center Code' })).toBeVisible()
		await expect(editor.getByRole('columnheader', { name: 'Cost Carrier Code' })).toBeVisible()
		await expect(editor.getByRole('columnheader', { name: 'Project Code' })).toBeVisible()

		// Line 1: pick CC-100 by its name in the row dialog.
		await editor.locator('[data-row-index="0"]').getByRole('button').first().click()
		await page.getByRole('menuitem', { name: 'Edit' }).click()
		const dialog = page.getByTestId('cn-sub-objects-dialog')
		await dialog.getByRole('combobox', { name: /Cost Center Code/ }).click()
		await page.getByRole('option', { name: new RegExp(centreName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) }).click()
		await dialog.getByRole('button', { name: /^(Save|Confirm|OK)$/ }).click()
		await expect(editor.getByRole('cell', { name: 'CC-100' })).toBeVisible()

		await page.locator('.cn-object-data-widget__editor-actions').getByRole('button', { name: 'Save' }).click()

		// The entry stores the code, which posting checks and copies onto the ledger line.
		await expect.poll(async () => {
			const entry = (await (await request.get(`${SHILLINQ}/JournalEntry/${id}`, { headers: HEADERS })).json()) as JournalEntry
			return entry.lines?.[0]?.costCenterCode ?? null
		}).toBe('CC-100')
	})
})
