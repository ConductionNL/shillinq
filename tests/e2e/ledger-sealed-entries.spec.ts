/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: sealed ledger transactions and the seal check
 * (ledger-sealed-entries task 3.1, REQ-LSE-001, REQ-LSE-002, REQ-LSE-003).
 *
 *   1. sealed on post: every sealed transaction of the active administration
 *      carries a 64 character seal hash, the sequences run 1, 2, 3 without a
 *      gap or a repeat, and each previous seal hash is the seal hash of the
 *      transaction before it (64 zeros for the first) (REQ-LSE-001);
 *   2. intact: the seal check endpoint answers over exactly the sealed
 *      transactions, an intact answer names no transaction, a broken one names
 *      a sealed transaction, and Check ledger seal on the general ledger page
 *      shows the same sentence (REQ-LSE-002);
 *   3. reversal: a reversed transaction and its reversal in the chain are never
 *      the first break the check names (REQ-LSE-003).
 *
 * Changing an amount directly in storage cannot be done from a browser, so
 * that scenario is a fixme here; LedgerSealServiceTest covers it. Posting a
 * new entry would leave a posted transaction behind that cannot be removed,
 * so the chain is checked on what the administration already holds. The
 * caller is the suite's administrator, whom the check endpoint allows
 * (ADR-005). The spec writes nothing.
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md
 * @spec openspec/changes/archive/2026-11-24-ledger-sealed-entries/tasks.md#task-3.1
 *
 * @e2e bookkeeping-audit-trail::a-bookkeeper-posts-and-the-entry-is-sealed
 * @e2e bookkeeping-audit-trail::an-intact-ledger
 * @e2e bookkeeping-audit-trail::an-amount-changed-in-storage-is-caught
 * @e2e bookkeeping-audit-trail::a-reversed-purchase-posting-still-checks-out
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { resolveBaseURL } from './base-url.ts'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const GENESIS = '0'.repeat(64)

/** The part of a GLTransaction this spec reads. */
interface GLTransaction {
	id?: string
	transactionNumber: string
	state: string
	reversesTransactionId?: string | null
	sealSequence?: number | null
	previousSealHash?: string | null
	sealHash?: string | null
	sealedAt?: string | null
	'@self'?: { id?: string }
}

/** The seal check answer (LedgerSealController::verify). */
interface SealCheck {
	intact: boolean
	entries: number
	transactionNumber: string | null
	transactionId: string | null
	reason: 'content' | 'link' | 'sequence' | null
	message: string
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
 * The sealed transactions of an administration, in chain order.
 *
 * @param request          The request context.
 * @param administrationId The administration.
 * @return The transactions with a seal sequence.
 */
async function sealedChain(request: APIRequestContext, administrationId: string): Promise<GLTransaction[]> {
	const all: GLTransaction[] = []
	for (let page = 1; page <= 50; page++) {
		const query = new URLSearchParams({ administrationId, _limit: '200', _page: String(page) })
		const res = await request.get(`${OBJECTS}/GLTransaction?${query.toString()}`, { headers: HEADERS })
		expect(res.ok(), await res.text()).toBe(true)
		const rows = ((await res.json()) as { results?: GLTransaction[] }).results ?? []
		all.push(...rows)
		if (rows.length < 200) {
			break
		}
	}
	return all
		.filter((row) => row.sealSequence !== null && row.sealSequence !== undefined)
		.sort((a, b) => Number(a.sealSequence) - Number(b.sealSequence))
}

/**
 * Run the seal check for an administration.
 *
 * @param request          The request context.
 * @param administrationId The administration.
 * @return The answer.
 */
async function sealCheck(request: APIRequestContext, administrationId: string): Promise<SealCheck> {
	const res = await request.get(`${API}/v1/ledger/seal-check?administrationId=${encodeURIComponent(administrationId)}`, { headers: HEADERS })
	expect(res.status(), await res.text()).toBe(200)
	return (await res.json()) as SealCheck
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('ledger-sealed-entries: the seal chain', () => {
	test('every sealed transaction chains onto the one before it @spec REQ-LSE-001', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const chain = await sealedChain(request, administrationId)
		test.skip(chain.length === 0, 'the administration has no sealed transaction')

		let previous = GENESIS
		chain.forEach((transaction, index) => {
			const label = transaction.transactionNumber || idOf(transaction)
			expect(transaction.sealSequence, `${label}: no gap or repeat in the sequence`).toBe(index + 1)
			expect(['posted', 'reversed'], `${label}: only a posted transaction is sealed`).toContain(transaction.state)
			expect(transaction.sealHash ?? '', `${label}: a SHA-256 seal`).toMatch(/^[0-9a-f]{64}$/)
			expect(transaction.previousSealHash, `${label}: chained onto its predecessor`).toBe(previous)
			expect(Number.isNaN(Date.parse(String(transaction.sealedAt))), `${label}: sealedAt is a time`).toBe(false)
			previous = String(transaction.sealHash)
		})
	})

	test.fixme('the general ledger detail page shows the seal of a posted entry @spec REQ-LSE-001', async () => {
		// Missing: GeneralLedgerDetail's Details widget (src/manifest.d/ledger-booking-rules.json) does not include sealSequence or sealHash.
	})

	test('the seal check answers over the sealed transactions @spec REQ-LSE-002', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const chain = await sealedChain(request, administrationId)
		const result = await sealCheck(request, administrationId)

		expect(result.entries, 'the check walks every sealed transaction').toBe(chain.length)
		expect(result.message.trim()).not.toBe('')
		if (result.intact) {
			expect(result.transactionNumber).toBeNull()
			expect(result.transactionId).toBeNull()
			expect(result.reason).toBeNull()
			expect(result.message).toContain(String(result.entries))
		} else {
			expect(['content', 'link', 'sequence']).toContain(result.reason)
			expect(chain.map(idOf), 'the break is a sealed transaction').toContain(result.transactionId)
		}
	})

	test('the seal check refuses an anonymous caller @spec REQ-LSE-002', async ({ playwright }) => {
		const anonymous = await playwright.request.newContext({ baseURL: resolveBaseURL() })
		const res = await anonymous.get(`${API}/v1/ledger/seal-check?administrationId=any`, { headers: HEADERS })
		expect(res.status()).not.toBe(200)
		await anonymous.dispose()
	})

	test('Check ledger seal on the general ledger page shows the result @spec REQ-LSE-002', async ({ page, request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const result = await sealCheck(request, administrationId)

		await page.goto(APP + '/general-ledger')
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)
		await page.getByRole('button', { name: /^(Check ledger seal|Grootboekzegel controleren)$/i }).click({ timeout: 20_000 })
		const dialog = page.locator('[data-testid="ledger-seal-modal"]')
		await expect(dialog).toBeVisible()
		const outcome = page.locator(result.intact ? '[data-testid="ledger-seal-intact"]' : '[data-testid="ledger-seal-broken"]')
		await expect(outcome).toBeVisible({ timeout: 30_000 })
		await expect(outcome).toHaveText(result.message)
	})

	test.fixme('an amount changed in storage is named as the first break @spec REQ-LSE-002', async () => {
		// Missing: a browser cannot change a stored line past OpenRegister; LedgerSealServiceTest tampers with the fixture instead.
	})

	test('a reversed transaction is never the first break @spec REQ-LSE-003', async ({ request }) => {
		const administrationId = await activeAdministration(request)
		test.skip(administrationId === '', 'the caller has no active administration')
		const chain = await sealedChain(request, administrationId)
		const reversals = chain.filter((transaction) => Boolean(transaction.reversesTransactionId))
		test.skip(reversals.length === 0, 'the chain holds no reversal')

		const involved = new Set<string>()
		for (const reversal of reversals) {
			involved.add(idOf(reversal))
			involved.add(String(reversal.reversesTransactionId))
		}

		const result = await sealCheck(request, administrationId)
		if (!result.intact) {
			expect(involved.has(String(result.transactionId)), `the break at ${result.transactionNumber} is not a reversal pair`).toBe(false)
		}
	})
})
