/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * receivables-sepa-direct-debit (task 3.2): register a mandate, propose a
 * direct debit batch for an open invoice paid by direct debit, generate its
 * pain.008 on the batch page and download the file.
 *
 * The run makes its own administration (with the logged-in user as owner, a
 * creditor identifier and an account), an active mandate and an issued
 * invoice, and removes them afterwards. The target comes from
 * PLAYWRIGHT_BASE_URL (base-url.ts, through playwright.config.ts); there is no
 * localhost default.
 *
 * @e2e bookkeeping-sepa-direct-debit::a-bookkeeper-proposes-the-october-collection
 * @e2e bookkeeping-sepa-direct-debit::a-bookkeeper-downloads-the-file-for-the-bank
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import { resolveBaseURL } from './base-url.ts'

const APP = '/index.php/apps/shillinq'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const STAMP = Date.now()

/** What this run created, as schema and id, removed in reverse order. */
const created: Array<{ schema: string; id: string }> = []

/**
 * Call a route from inside the page, carrying the session and its token.
 *
 * @param page   The page to run in.
 * @param method The HTTP verb.
 * @param url    The url.
 * @param body   The request body, for a write.
 *
 * @return The status and the parsed body.
 */
async function api(
	page: Page,
	method: string,
	url: string,
	body?: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
	return await page.evaluate(
		async ({ method, url, body }) => {
			const res = await fetch(url, {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken:
						document
							.querySelector('head[data-requesttoken]')
							?.getAttribute('data-requesttoken') ?? '',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let parsed: Record<string, unknown>
			try {
				parsed = JSON.parse(text)
			} catch {
				parsed = { raw: text.slice(0, 300) }
			}
			return { status: res.status, body: parsed }
		},
		{ method, url, body },
	)
}

/**
 * The id of an object answer.
 *
 * @param body The answer.
 *
 * @return The id, or ''.
 */
function idOf(body: Record<string, unknown> | undefined): string {
	return String(
		body?.id
			?? (body?.['@self'] as Record<string, unknown> | undefined)?.id
			?? '',
	)
}

/**
 * Create one object, remember it for clean-up, and return its id.
 *
 * @param page   The page to run in.
 * @param schema The schema.
 * @param data   The object.
 *
 * @return The created id.
 */
async function seed(
	page: Page,
	schema: string,
	data: Record<string, unknown>,
): Promise<string> {
	const res = await api(page, 'POST', `${OBJECTS}/${schema}`, data)
	expect(
		res.status,
		`could not seed ${schema}: ${JSON.stringify(res.body).slice(0, 300)}`,
	).toBeLessThan(400)
	const id = idOf(res.body)
	created.push({ schema, id })
	return id
}

/**
 * Open the app at a route and wait for it, past a first-run wizard.
 *
 * @param page  The page.
 * @param route The app route, from the app root.
 *
 * @return Resolves once the page has loaded.
 */
async function open(page: Page, route = '/'): Promise<void> {
	await page.goto(`${APP}${route}`)
	await page.waitForLoadState('domcontentloaded')
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('A direct debit batch from mandate to pain.008', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	let administrationId = ''
	let mandateReference = ''
	let batchId = ''

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await open(page)
		if (batchId !== '') {
			const rows = await api(
				page,
				'GET',
				`${OBJECTS}/DirectDebitCollection?submittedInBatchId=${batchId}&_limit=50`,
			)
			for (const row of (rows.body.results ?? []) as Array<
				Record<string, unknown>
			>) {
				created.push({ schema: 'DirectDebitCollection', id: idOf(row) })
			}
			created.push({ schema: 'DirectDebitBatch', id: batchId })
		}
		for (const { schema, id } of created.reverse()) {
			if (id !== '') {
				await api(page, 'DELETE', `${OBJECTS}/${schema}/${id}`)
			}
		}
		await page.close()
	})

	test('a registered mandate puts its open invoice on a proposed batch', async ({
		page,
	}) => {
		await open(page)
		const context = await api(page, 'GET', `${APP}/api/administrations/context`)
		const userId = String(context.body.userId ?? '')
		expect(userId).not.toBe('')

		administrationId = await seed(page, 'Administration', {
			administrationCode: `DD${STAMP}`,
			name: `Incasso e2e ${STAMP}`,
			legalForm: 'bv',
			status: 'actief',
			sepaCreditorIdentifier: 'NL98ZZZ999999999999',
			primaryIban: 'NL91ABNA0417164300',
			primaryBic: 'ABNANL2A',
		})
		await seed(page, 'AdministrationMembership', {
			userId,
			administrationId,
			role: 'eigenaar',
		})

		mandateReference = `MND-${STAMP}`
		const mandateId = await seed(page, 'SepaMandate', {
			mandateReference,
			creditorIdentifier: 'NL98ZZZ999999999999',
			scheme: 'CORE',
			type: 'recurring',
			status: 'active',
			signedAt: '2026-10-01',
			signedBy: 'J. de Vries',
			debtorIban: 'NL20INGB0001234567',
			debtorName: 'J. de Vries',
			debtorAccountType: 'consumer',
			administrationId,
		})

		await seed(page, 'ARInvoice', {
			invoiceNumber: `INV-DD-${STAMP}`,
			customerId: `cust-${STAMP}`,
			administrationId,
			invoiceDate: '2026-11-01',
			dueDate: '2026-11-30',
			grossAmount: 121,
			netAmount: 100,
			currency: 'EUR',
			periodId: '2026-11',
			lifecycleState: 'issued',
			paymentMethod: 'direct_debit',
			directDebitMandateId: mandateId,
		})

		const collectionDate = new Date(Date.now() + 10 * 86_400_000)
			.toISOString()
			.slice(0, 10)
		const proposed = await api(
			page,
			'POST',
			`${APP}/api/v1/direct-debit-batches/propose`,
			{ administrationId, collectionDate },
		)
		expect(proposed.status, JSON.stringify(proposed.body).slice(0, 300)).toBe(
			201,
		)
		const batches = proposed.body.batches as Array<Record<string, unknown>>
		expect(batches).toHaveLength(1)
		batchId = idOf(batches[0])
		expect(batches[0].sequenceType).toBe('FRST')
		expect(Number(batches[0].controlSum)).toBe(121)
	})

	test('the batch page generates the pain.008 and downloads it', async ({
		page,
	}) => {
		await open(page, `/bookkeeping/direct-debit-batches/${batchId}`)
		await page.getByRole('button', { name: 'Generate pain.008' }).click()
		const confirm = page
			.getByRole('dialog')
			.getByRole('button', { name: /Generate pain\.008|Confirm/ })
		if (await confirm.isVisible({ timeout: 3_000 }).catch(() => false)) {
			await confirm.click()
		}
		await expect
			.poll(
				async () =>
					(
						await api(
							page,
							'GET',
							`${OBJECTS}/DirectDebitBatch/${batchId}`,
						)
					).body.status,
				{ timeout: 30_000 },
			)
			.toBe('generated')

		await page.reload()
		const download = page.waitForEvent('download')
		await page.getByRole('button', { name: 'Download pain.008' }).click()
		const file = await (await download).path()
		const xml = await readFile(file, 'utf8')
		expect(xml).toContain('pain.008.001.02')
		expect(xml).toContain(`<MndtId>${mandateReference}</MndtId>`)
		expect(xml).toContain('<CtrlSum>121.00</CtrlSum>')
	})

	test('a visitor without a session cannot propose a batch', async ({
		browser,
	}) => {
		const anonymous = await browser.newContext({
			baseURL: resolveBaseURL(),
			storageState: { cookies: [], origins: [] },
		})
		const res = await anonymous.request.post(
			`${APP}/api/v1/direct-debit-batches/propose`,
			{
				data: { administrationId, collectionDate: '2026-12-01' },
				headers: { 'OCS-APIRequest': 'true' },
			},
		)
		expect(res.status()).toBeGreaterThanOrEqual(400)
		await anonymous.close()
	})
})
