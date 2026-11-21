/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: an administration kept under Belgian rules next to Dutch
 * ones (ledger-foreign-legislation task 4.1, REQ-LFL-001, REQ-LFL-002,
 * REQ-LFL-003, REQ-LFL-004).
 *
 *   1. an accountant creates a Belgian administration: the setup wizard's
 *      chart step offers the Belgian legislation pack, and every Belgian
 *      organisation type suggests it (REQ-LFL-001);
 *   2. the jurisdiction is fixed after posting: changing the jurisdiction of
 *      an administration with a posted transaction is refused with the
 *      reason that postings exist (REQ-LFL-001);
 *   3. a Belgian invoice is checked against Belgian rules: test.fixme, no
 *      page or endpoint reports which rules a check ran (REQ-LFL-002);
 *   4. the Belgian chart and rates are present: the BE VAT tariffs are 0, 6,
 *      12 and 21 percent, and a BE administration's chart lists 400000
 *      Handelsdebiteuren and 451000 Te betalen btw (REQ-LFL-003);
 *   5. a Dutch VAT return is not produced for a Belgian company: the VAT
 *      return the report dialog asks for is refused, naming BE (REQ-LFL-004).
 *
 * Scenario 5 creates its own Belgian administration under this run's stamp,
 * with the caller as member, and removes it afterwards. Scenario 2 tries the
 * change on whatever administration holds a posted transaction and puts the
 * jurisdiction back if the refusal does not come. Scenario 4 reads the
 * administrations the instance holds and skips without a BE one: seeding a
 * pack goes through the setup wizard's seed action, which works on the
 * instance's configured administration, so the spec does not run it. The
 * caller is the suite's administrator (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 * @spec openspec/changes/ledger-foreign-legislation/tasks.md#task-4.1
 *
 * @e2e bookkeeping-multi-administratie::an-accountant-creates-a-belgian-administration
 * @e2e bookkeeping-multi-administratie::the-jurisdiction-is-fixed-after-posting
 * @e2e bookkeeping-multi-administratie::a-belgian-invoice-is-checked-against-belgian-rules
 * @e2e bookkeeping-multi-administratie::the-belgian-chart-and-rates-are-present
 * @e2e bookkeeping-multi-administratie::a-dutch-vat-return-is-not-produced-for-a-belgian-company
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const STAMP = Date.now()

/** An object as OpenRegister answers it. */
interface RegisterObject {
	id?: string
	uuid?: string
	'@self'?: { id?: string }
}

/** An administration, as far as this spec reads it. */
interface Administration extends RegisterObject {
	administrationCode?: string
	name?: string
	jurisdiction?: string
}

/** An account of a chart. */
interface Account extends RegisterObject {
	accountNumber: string
	name: string
	administrationId: string
}

/** A VAT tariff. */
interface VatTariff extends RegisterObject {
	code: string
	ratePercentage: number
	jurisdiction?: string
}

/** A refusal of a Dutch-only output. */
interface JurisdictionRefusal {
	error: string
	message: string
	jurisdiction: string
}

/** A wizard step, as far as this spec reads it. */
interface WizardStep {
	id: string
	optionsByParent?: Record<string, Array<{ value: string }>>
	suggestMap?: Record<string, string>
}

/** What this run created, removed in reverse order. */
const created: Array<{ url: string }> = []

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
	return ((await res.json()).results ?? []) as T[]
}

/**
 * Create one object, remember it for clean-up, and answer it.
 *
 * @param request The request context.
 * @param schema  The schema.
 * @param data    The object.
 * @return The saved object.
 */
async function seed(request: APIRequestContext, schema: string, data: Record<string, unknown>): Promise<RegisterObject> {
	const res = await request.post(`${OBJECTS}/${schema}`, { headers: HEADERS, data })
	expect(res.ok(), `could not seed ${schema}: ${await res.text()}`).toBe(true)
	const saved = (await res.json()) as RegisterObject
	created.push({ url: `${OBJECTS}/${schema}/${idOf(saved)}` })
	return saved
}

/** ISO jurisdiction, NL when the field is empty (the schema's default). */
function jurisdictionOf(administration: Administration): string {
	return String(administration.jurisdiction ?? '').trim().toUpperCase() || 'NL'
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('ledger-foreign-legislation: a Belgian administration', () => {
	test.afterAll(async ({ request }) => {
		for (const { url } of created.reverse()) {
			await request.delete(url, { headers: HEADERS }).catch(() => {})
		}
	})

	test('the setup wizard offers the Belgian legislation pack for a Belgian organisation @spec REQ-LFL-001', async ({ request }) => {
		const res = await request.get(`${API}/setup/status`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const status = (await res.json()) as { chartTemplates: Array<{ value: string, label: string }> }
		expect(status.chartTemplates.map((choice) => choice.value), 'the chart step offers the BE pack').toContain('legislation-be')

		// The wizard suggests the pack from the organisation type picked under BE.
		const manifest = JSON.parse(readFileSync(join(__dirname, '..', '..', 'src', 'manifest.json'), 'utf8')) as {
			setup?: { steps?: WizardStep[] }
		}
		const steps = manifest.setup?.steps ?? []
		const organisation = steps.find((step) => step.id === 'organisation')
		const chart = steps.find((step) => step.id === 'rgs-template')
		expect(organisation, 'the organisation step').toBeDefined()
		expect(chart, 'the chart of accounts step').toBeDefined()
		const belgian = organisation!.optionsByParent?.be ?? []
		expect(belgian.length, 'Belgian organisation types').toBeGreaterThan(0)
		for (const option of belgian) {
			expect(chart!.suggestMap?.[option.value], `${option.value} suggests the BE pack`).toBe('legislation-be')
		}
	})

	test('the jurisdiction of an administration with a posted transaction cannot change @spec REQ-LFL-001', async ({ request }) => {
		const [posted] = await list<{ administrationId?: string }>(request, `${OBJECTS}/GLTransaction?state=posted&_limit=1`)
		const reference = String(posted?.administrationId ?? '')
		test.skip(reference === '', 'the instance holds no posted transaction')

		// Transactions carry the administrationCode, older ones the uuid.
		let administration: Administration | undefined
		const direct = await request.get(`${OBJECTS}/Administration/${encodeURIComponent(reference)}`, { headers: HEADERS })
		if (direct.ok()) {
			administration = (await direct.json()) as Administration
		} else {
			[administration] = await list<Administration>(request, `${OBJECTS}/Administration?administrationCode=${encodeURIComponent(reference)}&_limit=1`)
		}
		test.skip(administration === undefined, `the administration of the posted transaction (${reference}) is not readable`)

		const id = idOf(administration!)
		const before = jurisdictionOf(administration!)
		const other = before === 'NL' ? 'BE' : 'NL'
		const res = await request.patch(`${OBJECTS}/Administration/${id}`, { headers: HEADERS, data: { jurisdiction: other } })
		const body = await res.text()
		if (res.ok()) {
			await request.patch(`${OBJECTS}/Administration/${id}`, { headers: HEADERS, data: { jurisdiction: before } })
		}
		expect(res.ok(), 'the change must be refused').toBe(false)
		expect(body).toMatch(/posted transactions/i)

		const after = (await (await request.get(`${OBJECTS}/Administration/${id}`, { headers: HEADERS })).json()) as Administration
		expect(jurisdictionOf(after)).toBe(before)
	})

	// Missing: a page or endpoint that reports which rules a check ran; RuleComplianceGuard's selection is covered by PHPUnit only.
	test.fixme('a Belgian invoice is checked against the BE, EU and global rules and no NL-only rule @spec REQ-LFL-002', async () => {})

	test('the Belgian VAT rates are 0, 6, 12 and 21 percent @spec REQ-LFL-003', async ({ request }) => {
		const tariffs = await list<VatTariff>(request, `${OBJECTS}/VatTariff?jurisdiction=BE&_limit=50`)
		test.skip(tariffs.length === 0, 'no Belgian legislation pack has been seeded on this instance')

		for (const tariff of tariffs) {
			expect(tariff.jurisdiction).toBe('BE')
		}
		const rates = [...new Set(tariffs.map((tariff) => Number(tariff.ratePercentage)))].sort((a, b) => a - b)
		expect(rates).toEqual([0, 6, 12, 21])
	})

	test('a Belgian administration\'s chart lists 400000 Handelsdebiteuren and 451000 Te betalen btw @spec REQ-LFL-003', async ({ page, request }) => {
		const belgian = await list<Administration>(request, `${OBJECTS}/Administration?jurisdiction=BE&_limit=20`)
		let chart: Account[] = []
		let owner: Administration | undefined
		for (const administration of belgian) {
			for (const key of [idOf(administration), String(administration.administrationCode ?? '')].filter((value) => value !== '')) {
				const accounts = await list<Account>(request, `${OBJECTS}/Account?administrationId=${encodeURIComponent(key)}&_limit=500`)
				if (accounts.some((account) => account.accountNumber === '400000')) {
					chart = accounts
					owner = administration
					break
				}
			}
			if (owner !== undefined) {
				break
			}
		}
		test.skip(owner === undefined, 'no BE administration seeded from the Belgian pack on this instance')

		const byNumber = new Map(chart.map((account) => [account.accountNumber, account.name]))
		expect(byNumber.get('400000')).toBe('Handelsdebiteuren')
		expect(byNumber.get('451000')).toBe('Te betalen btw')

		// The chart of accounts page lists the active administration's chart.
		const context = await request.get(`${API}/administrations/context`, { headers: HEADERS })
		const active = String(((await context.json()) as { activeAdministrationId?: string | null }).activeAdministrationId ?? '')
		test.skip(![idOf(owner!), String(owner!.administrationCode ?? '')].includes(active), 'the Belgian administration is not the caller\'s active one')
		await page.goto(`${APP}/chart-of-accounts`)
		await page.waitForLoadState('domcontentloaded')
		await dismissOverlays(page)
		await expect(page.getByRole('row', { name: /400000/ }).first()).toContainText(/Handelsdebiteuren/, { timeout: 20_000 })
		await expect(page.getByRole('row', { name: /451000/ }).first()).toContainText(/Te betalen btw/, { timeout: 20_000 })
	})

	test('a Dutch VAT return is refused for a Belgian administration, naming its jurisdiction @spec REQ-LFL-004', async ({ request }) => {
		const context = await request.get(`${API}/administrations/context`, { headers: HEADERS })
		expect(context.status(), await context.text()).toBe(200)
		const userId = String(((await context.json()) as { userId?: string }).userId ?? '')
		test.skip(userId === '', 'the administration context names no user')

		const administration = await seed(request, 'Administration', {
			administrationCode: `BE${STAMP}`,
			name: `Van Dijk Services BV/SRL e2e ${STAMP}`,
			legalForm: 'bv',
			status: 'actief',
			jurisdiction: 'BE',
		})
		const administrationId = idOf(administration)
		await seed(request, 'AdministrationMembership', { userId, administrationId, role: 'eigenaar' })

		const res = await request.post(`${API}/vat-returns`, {
			headers: HEADERS,
			data: { administrationId, period: 'quarter', periodYear: 2026, periodNumber: 3, regime: 'standard' },
		})
		const text = await res.text()
		if (res.ok()) {
			const id = idOf(JSON.parse(text) as RegisterObject)
			if (id !== '') {
				await request.delete(`${API}/vat-returns/${id}`, { headers: HEADERS })
			}
		}
		expect(res.status(), text).toBe(422)
		const refusal = JSON.parse(text) as JurisdictionRefusal
		expect(refusal.error).toBe('jurisdiction-refused')
		expect(refusal.jurisdiction).toBe('BE')
		expect(refusal.message).toMatch(/\bBE\b/)
	})
})
