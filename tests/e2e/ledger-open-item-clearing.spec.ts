/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: lines on a balance account kept by open item are cleared
 * against each other, and groups that do not net to zero are listed
 * (ledger-open-item-clearing task 4.1, REQ-LOIC-001, REQ-LOIC-002,
 * REQ-LOIC-003, REQ-LOIC-004).
 *
 *   1. a bookkeeper opens the kruisposten account: the overview lists the
 *      account's uncleared posted lines with date, description, side and
 *      amount, and the open items page shows them (REQ-LOIC-001);
 *   2. clearing a savings transfer: clearing a suggested pair makes one group
 *      with balance EUR 0 recording who and when, takes both lines off the
 *      uncleared list, refuses a second group for the same lines, and undoing
 *      it opens them again (REQ-LOIC-002);
 *   3. a group left short: every group the page lists as not netting to zero
 *      has a non-zero balance (or was reopened) equal to its debits minus its
 *      credits; when the account has none, the spec clears two unequal lines
 *      to make one (REQ-LOIC-003);
 *   4. a suggestion is confirmed: each suggested pair is two uncleared lines
 *      with equal amounts on opposite sides and a reference, and opening the
 *      overview or the page clears nothing (REQ-LOIC-004).
 *
 * The account is the register fragment's seeded 1950 Kruisposten when the
 * caller can read it (the design's Adviesbureau Van Dijk seed: two EUR 5,000
 * "Spaaropdracht 0903" lines and an open EUR 250 deposit), else the first
 * account kept by open item the caller can read. Every clearing the spec
 * makes is undone in a finally block, so the lines end as they started. The
 * caller is the suite's administrator (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/ledger-open-item-clearing/specs/bookkeeping-general-ledger/spec.md
 * @spec openspec/changes/ledger-open-item-clearing/tasks.md#task-4.1
 *
 * @e2e bookkeeping-general-ledger::a-bookkeeper-opens-the-kruisposten-account
 * @e2e bookkeeping-general-ledger::clearing-a-savings-transfer
 * @e2e bookkeeping-general-ledger::a-group-left-eur-250-short
 * @e2e bookkeeping-general-ledger::a-suggestion-is-confirmed
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api/v1/open-items'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }

/** The seeded 1950 Kruisposten account (register.d/zz-ledger-open-item-clearing.json). */
const KRUISPOSTEN_ID = '06fcd490-c43e-5a21-adb8-315d89c5e837'

/** The account types the clearing service accepts. */
const BALANCE_TYPES = ['assets', 'liabilities', 'equity']

/** An uncleared line of the overview. */
interface OpenLine {
	id: string
	date: string
	description: string
	side: 'debit' | 'credit'
	amount: number
	reference: string
}

/** A group of the overview, or the answer of a clearing. */
interface ClearingGroup {
	id: string
	accountNumber: string
	lineIds: string[]
	debitCents: number
	creditCents: number
	balanceCents: number
	balance?: number
	clearedBy: string
	clearedAt: string
	state: 'cleared' | 'reopened'
}

/** A suggested pair. */
interface Suggestion {
	lineIds: string[]
	debitLineId: string
	creditLineId: string
	amount: number
	reference: string
}

/** The overview of one account. */
interface Overview {
	accountNumber: string
	openItemManaged: boolean
	uncleared: OpenLine[]
	openGroups: ClearingGroup[]
	suggestions: Suggestion[]
}

/** An account, as far as this spec reads it. */
interface Account {
	id?: string
	'@self'?: { id?: string }
	accountNumber: string
	accountType: string
}

/** Compare two euro amounts to the cent. */
function cents(value: number): number {
	return Math.round(value * 100)
}

/**
 * The overview of an account, or null when the caller may not read it.
 *
 * @param request   The request context.
 * @param accountId The Account uuid.
 * @return The overview, or null.
 */
async function overview(request: APIRequestContext, accountId: string): Promise<Overview | null> {
	const res = await request.get(`${API}/accounts/${accountId}`, { headers: HEADERS })
	if (res.status() === 404) {
		return null
	}
	expect(res.status(), await res.text()).toBe(200)
	return (await res.json()) as Overview
}

/**
 * The account this spec works on: the seeded 1950 when readable, else the
 * first account kept by open item the caller can read.
 *
 * @param request The request context.
 * @return The account id and its overview, or null.
 */
async function openItemAccount(request: APIRequestContext): Promise<{ id: string, account: Account, view: Overview } | null> {
	const res = await request.get(`${OBJECTS}/Account?openItemManaged=true&_limit=50`, { headers: HEADERS })
	expect(res.status(), await res.text()).toBe(200)
	const accounts = ((await res.json()).results ?? []) as Account[]
	const ids = (account: Account) => String(account.id ?? account['@self']?.id ?? '')
	accounts.sort((a, b) => Number(ids(b) === KRUISPOSTEN_ID) - Number(ids(a) === KRUISPOSTEN_ID))
	for (const account of accounts) {
		const view = await overview(request, ids(account))
		if (view !== null) {
			return { id: ids(account), account, view }
		}
	}
	return null
}

/**
 * Clear lines into one group.
 *
 * @param request The request context.
 * @param lineIds The GLLine ids.
 * @param reason  The reason recorded on the group.
 * @return The status and the answer.
 */
async function clear(request: APIRequestContext, lineIds: string[], reason: string): Promise<{ status: number, body: ClearingGroup & { message?: string } }> {
	const res = await request.post(`${API}/clear`, { headers: HEADERS, data: { lineIds, reason } })
	return { status: res.status(), body: await res.json() }
}

/**
 * Undo a clearing.
 *
 * @param request The request context.
 * @param groupId The ClearingGroup uuid.
 * @return The released line ids.
 */
async function undo(request: APIRequestContext, groupId: string): Promise<string[]> {
	const res = await request.post(`${API}/groups/${groupId}/undo`, { headers: HEADERS })
	expect(res.status(), await res.text()).toBe(200)
	return ((await res.json()) as { releasedLineIds: string[] }).releasedLineIds
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('ledger-open-item-clearing: clearing lines on an open-item account', () => {
	test.describe.configure({ mode: 'serial' })

	test('the open items of an account list its uncleared posted lines @spec REQ-LOIC-001', async ({ page, request }) => {
		const found = await openItemAccount(request)
		test.skip(found === null, 'no account kept by open item that the caller can read')
		const { id, account, view } = found!

		expect(view.openItemManaged).toBe(true)
		expect(view.accountNumber).toBe(account.accountNumber)
		expect(BALANCE_TYPES, `${account.accountNumber} is a balance account`).toContain(account.accountType)
		for (const line of view.uncleared) {
			expect(line.date, `${line.id}: date`).toMatch(/^\d{4}-\d{2}-\d{2}/)
			expect(typeof line.description).toBe('string')
			expect(['debit', 'credit']).toContain(line.side)
			expect(typeof line.amount).toBe('number')
		}
		if (id === KRUISPOSTEN_ID && view.uncleared.length === 3) {
			// The seed: the two 5,000 transfer lines and the open 250 deposit.
			expect(view.uncleared.map((line) => cents(line.amount)).sort((a, b) => a - b)).toEqual([25_000, 500_000, 500_000])
		}

		await page.goto(`${APP}/bookkeeping/open-items/${id}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)
		await expect(page.getByText(/^(Uncleared lines|Openstaande regels)$/i).first()).toBeVisible({ timeout: 20_000 })
		for (const line of view.uncleared.slice(0, 5)) {
			if (line.description !== '') {
				await expect(page.getByText(line.description).first(), line.description).toBeVisible({ timeout: 20_000 })
			}
		}
	})

	test('clearing a pair makes one zero group, and undoing it opens the lines again @spec REQ-LOIC-002', async ({ request }) => {
		const found = await openItemAccount(request)
		test.skip(found === null, 'no account kept by open item that the caller can read')
		const { id, view } = found!
		const pair = view.suggestions[0]
		test.skip(pair === undefined, 'the account has no suggested pair to clear')

		const cleared = await clear(request, pair.lineIds, 'e2e: savings transfer')
		expect(cleared.status, JSON.stringify(cleared.body)).toBe(200)
		const group = cleared.body
		try {
			expect(group.state).toBe('cleared')
			expect([...group.lineIds].sort()).toEqual([...pair.lineIds].sort())
			expect(group.balanceCents).toBe(0)
			expect(group.clearedBy, 'who cleared').not.toBe('')
			expect(Number.isNaN(Date.parse(group.clearedAt)), 'when it was cleared').toBe(false)

			const after = (await overview(request, id))!
			const open = after.uncleared.map((line) => line.id)
			for (const lineId of pair.lineIds) {
				expect(open, `${lineId} left the uncleared list`).not.toContain(lineId)
			}
			expect(after.openGroups.map((row) => row.id), 'a zero group is not listed as open').not.toContain(group.id)

			const again = await clear(request, pair.lineIds, 'e2e: the same lines again')
			expect(again.status, 'a line is in one group at most').toBe(422)
		} finally {
			const released = await undo(request, group.id)
			expect([...released].sort()).toEqual([...pair.lineIds].sort())
		}

		const restored = (await overview(request, id))!
		for (const lineId of pair.lineIds) {
			expect(restored.uncleared.map((line) => line.id), `${lineId} is open again`).toContain(lineId)
		}
	})

	test('groups that do not net to zero are listed with their balance @spec REQ-LOIC-003', async ({ page, request }) => {
		const found = await openItemAccount(request)
		test.skip(found === null, 'no account kept by open item that the caller can read')
		const { id, view } = found!

		let made: ClearingGroup | null = null
		if (view.openGroups.length === 0) {
			const debit = view.uncleared.find((line) => line.side === 'debit')
			const credit = view.uncleared.find((line) => line.side === 'credit' && debit !== undefined && cents(line.amount) !== cents(debit.amount))
			test.skip(debit === undefined || credit === undefined, 'no open group and no unequal debit and credit to clear into one')
			const cleared = await clear(request, [debit!.id, credit!.id], 'e2e: left short')
			expect(cleared.status, JSON.stringify(cleared.body)).toBe(200)
			made = cleared.body
			expect(made.balanceCents).toBe(cents(debit!.amount) - cents(credit!.amount))
		}

		try {
			const groups = (await overview(request, id))!.openGroups
			expect(groups.length, 'at least one group does not net to zero').toBeGreaterThan(0)
			for (const group of groups) {
				expect(group.balanceCents, `${group.id}: debits minus credits`).toBe(group.debitCents - group.creditCents)
				expect(group.balanceCents !== 0 || group.state === 'reopened', `${group.id} is listed for a reason`).toBe(true)
				expect(cents(Number(group.balance)), `${group.id}: balance in euros`).toBe(group.balanceCents)
			}
			if (made !== null) {
				expect(groups.map((group) => group.id)).toContain(made.id)
			}

			await page.goto(`${APP}/bookkeeping/open-items/${id}`)
			await page.waitForLoadState('domcontentloaded')
			await dismissOverlays(page)
			await expect(page.getByText(/Groups that do not net to zero|Groepen die niet op nul uitkomen/i).first()).toBeVisible({ timeout: 20_000 })
		} finally {
			if (made !== null) {
				await undo(request, made.id)
			}
		}
	})

	test('the page suggests exact pairs and clears nothing until Clear is pressed @spec REQ-LOIC-004', async ({ page, request }) => {
		const found = await openItemAccount(request)
		test.skip(found === null, 'no account kept by open item that the caller can read')
		const { id, view } = found!

		const byId = new Map(view.uncleared.map((line) => [line.id, line]))
		for (const pair of view.suggestions) {
			const debit = byId.get(pair.debitLineId)
			const credit = byId.get(pair.creditLineId)
			expect(debit, `${pair.reference}: the debit is uncleared`).toBeDefined()
			expect(credit, `${pair.reference}: the credit is uncleared`).toBeDefined()
			expect(debit!.side).toBe('debit')
			expect(credit!.side).toBe('credit')
			expect(cents(debit!.amount)).toBe(cents(credit!.amount))
			expect(cents(pair.amount)).toBe(cents(debit!.amount))
			expect(pair.reference, 'the shared reference').not.toBe('')
			expect([...pair.lineIds].sort()).toEqual([pair.debitLineId, pair.creditLineId].sort())
		}
		if (id === KRUISPOSTEN_ID && view.suggestions.length > 0) {
			expect(view.suggestions.map((pair) => pair.reference)).toContain('Spaaropdracht 0903')
		}

		await page.goto(`${APP}/bookkeeping/open-items/${id}`)
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)
		await expect(page.getByText(/^(Suggested pairs|Voorgestelde paren)$/i).first()).toBeVisible({ timeout: 20_000 })
		if (view.suggestions.length > 0) {
			await expect(page.getByText(view.suggestions[0].reference).first()).toBeVisible({ timeout: 20_000 })
		}

		const again = (await overview(request, id))!
		expect(again.uncleared.map((line) => line.id).sort(), 'opening the suggestions cleared nothing')
			.toEqual(view.uncleared.map((line) => line.id).sort())
	})
})
