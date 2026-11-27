/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: an activated fixed asset gets its investment deduction
 * eligibility and the year is recalculated per scheme
 * (tax-investment-deduction-claims task 3.1, REQ-IDC-001, REQ-IDC-002,
 * REQ-IDC-003).
 *
 *   1. a delivery van is activated: the fixed asset "Bestelbus" of EUR
 *      32,000 acquired 2026-03-01 becomes active and its investment asset
 *      shows KIA eligible with its reason, and EIA, MIA and Vamil not
 *      eligible because no list code matched; the asset page's Investment
 *      deduction tab lists it (REQ-IDC-001);
 *   2. an override survives: with KIA overridden to not eligible with reason
 *      "privé gebruik", a later change to the asset keeps the flag and the
 *      reason (REQ-IDC-001);
 *   3. 2026 is calculated: Recalculate for 2026 on the investment
 *      deductions page writes draft claims whose KIA total is the sum of the
 *      KIA claims, one per eligible asset acquired in 2026 (REQ-IDC-002);
 *   4. a year without tables: recalculating a year with no KIA tiers is
 *      refused, naming the missing tiers (REQ-IDC-002);
 *   5. the tax return: the investment deductions page opens from the menu
 *      and lists the claims with the scheme and notification deadline
 *      columns (REQ-IDC-003).
 *
 * The spec writes: it adds its own fixed asset (with a run-unique asset
 * number) to the caller's active administration, deletes it and its
 * investment asset in afterAll, and recalculates 2026 once more so the
 * year's draft claims no longer count it. Claims used in a tax return are
 * never touched by a recalculation. The caller is the suite's administrator
 * (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/archive/2026-11-24-tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md
 * @spec openspec/changes/archive/2026-11-24-tax-investment-deduction-claims/tasks.md#task-3.1
 *
 * @e2e bookkeeping-investeringsaftrek::a-bookkeeper-activates-a-delivery-van
 * @e2e bookkeeping-investeringsaftrek::an-override-survives-a-later-change
 * @e2e bookkeeping-investeringsaftrek::a-bookkeeper-calculates-2026
 * @e2e bookkeeping-investeringsaftrek::a-year-without-tables
 * @e2e bookkeeping-investeringsaftrek::a-bookkeeper-prepares-the-tax-return
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api/investment-deductions'
const OR = '/index.php/apps/openregister/api/objects'
const OBJECTS = `${OR}/shillinq`
const HEADERS = { 'OCS-APIRequest': 'true' }
const RUN = Date.now().toString(36)
const ASSET_NAME = `Bestelbus E2E ${RUN}`

/** An OpenRegister object as the list and item endpoints answer it. */
interface RegisterObject {
	id?: string
	'@self'?: { id?: string }
	[field: string]: unknown
}

/** The InvestmentAsset InvestmentDeductionService::recordEligibility stores. */
interface InvestmentAsset extends RegisterObject {
	fixedAssetId: string
	description: string
	acquisitionDate: string
	acquisitionValue: number
	kiaEligible: boolean
	eiaEligible: boolean
	miaEligible: boolean
	vamilEligible: boolean
	eligibilityReasons: Record<'kia' | 'eia' | 'mia' | 'vamil', string>
	eligibilityOverride?: { scheme: string, value: boolean, rationale: string } | null
}

/** POST /api/investment-deductions/{year}/calculate. */
interface CalculateAnswer {
	year: number
	totals: Record<'KIA' | 'EIA' | 'MIA', number>
	claims: number
	kept: number
}

/** The id of a register object, wherever OpenRegister put it. */
function idOf(object: RegisterObject): string {
	return String(object.id ?? object['@self']?.id ?? '')
}

async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/** Run a page's header action: a button when it stands alone, else an entry of its Actions menu. */
async function runHeaderAction(page: Page, name: RegExp): Promise<void> {
	const button = page.getByRole('button', { name }).first()
	if (await button.isVisible().catch(() => false)) {
		await button.click()
		return
	}
	await page.getByRole('button', { name: /^(Actions|Acties)$/i }).first().click()
	await page.getByRole('menuitem', { name }).first().click()
}

/** The caller's active administration, or '' when there is none. */
async function activeAdministration(request: APIRequestContext): Promise<string> {
	const context = await request.get('/index.php/apps/shillinq/api/administrations/context', { headers: HEADERS })
	if (!context.ok()) {
		return ''
	}
	return String((await context.json() as { activeAdministrationId?: string | null }).activeAdministrationId ?? '')
}

/** List register objects matching the given fields. */
async function list(request: APIRequestContext, schema: string, filters: Record<string, string | number>): Promise<RegisterObject[]> {
	const query = new URLSearchParams({ _limit: '500' })
	for (const [field, value] of Object.entries(filters)) {
		query.set(field, String(value))
	}
	const res = await request.get(`${OBJECTS}/${schema}?${query.toString()}`, { headers: HEADERS })
	expect(res.ok(), `list ${schema}: ${res.status()}`).toBe(true)
	return ((await res.json()) as { results?: RegisterObject[] }).results ?? []
}

/** The investment asset of a fixed asset, once the listener wrote it. */
async function investmentAssetOf(request: APIRequestContext, administrationId: string, fixedAssetId: string): Promise<InvestmentAsset | undefined> {
	const rows = await list(request, 'InvestmentAsset', { administrationId, fixedAssetId })
	return rows.find((row) => row.fixedAssetId === fixedAssetId) as InvestmentAsset | undefined
}

test.describe.serial('tax-investment-deduction-claims: eligibility on activation and the yearly claims', () => {
	let administrationId = ''
	let fixedAssetId = ''
	let fixedAsset: Record<string, unknown> = {}

	test.beforeAll(async ({ request }) => {
		administrationId = await activeAdministration(request)
	})

	test.afterAll(async ({ request }) => {
		if (fixedAssetId === '') {
			return
		}
		const asset = await investmentAssetOf(request, administrationId, fixedAssetId).catch(() => undefined)
		if (asset !== undefined) {
			for (const claim of await list(request, 'InvesteringsaftrekClaim', { administrationId, investmentAssetId: idOf(asset) }).catch(() => [])) {
				if (claim.submittedInTaxReturn !== true) {
					await request.delete(`${OBJECTS}/InvesteringsaftrekClaim/${idOf(claim)}`, { headers: HEADERS }).catch(() => {})
				}
			}
			await request.delete(`${OBJECTS}/InvestmentAsset/${idOf(asset)}`, { headers: HEADERS }).catch(() => {})
		}
		await request.delete(`${OBJECTS}/FixedAsset/${fixedAssetId}`, { headers: HEADERS }).catch(() => {})
		// The year's drafts without the e2e asset again.
		await request.post(`${API}/2026/calculate`, { headers: HEADERS, data: { administrationId } }).catch(() => {})
	})

	test('activating the delivery van records KIA eligible and EIA, MIA and Vamil not, with reasons @spec REQ-IDC-001', async ({ page, request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		fixedAsset = {
			assetNumber: `E2E-IDC-${RUN}`,
			name: ASSET_NAME,
			assetCategory: 'vehicles',
			acquisitionDate: '2026-03-01',
			acquisitionCost: 32000,
			currency: 'EUR',
			usefulLifeMonths: 60,
			residualValue: 0,
			depreciationMethod: 'linear',
			assetAccountNumber: '0300',
			accumulatedDepAccountNumber: '0310',
			depreciationExpenseAccountNumber: '4300',
			lifecycleState: 'proposed',
			administrationId,
		}
		const created = await request.post(`${OBJECTS}/FixedAsset`, { headers: HEADERS, data: fixedAsset })
		expect(created.ok(), await created.text()).toBe(true)
		fixedAssetId = idOf(await created.json() as RegisterObject)
		expect(await investmentAssetOf(request, administrationId, fixedAssetId), 'a proposed asset has no investment asset yet').toBeUndefined()

		// Activate: the lifecycle transition, or the state written directly where the transition is not offered.
		const activated = await request.post(`${OR}/${fixedAssetId}/transition`, { headers: HEADERS, data: { action: 'activate' } })
		if (!activated.ok()) {
			const written = await request.put(`${OBJECTS}/FixedAsset/${fixedAssetId}`, { headers: HEADERS, data: { ...fixedAsset, lifecycleState: 'active' } })
			expect(written.ok(), await written.text()).toBe(true)
		}
		fixedAsset = { ...fixedAsset, lifecycleState: 'active' }

		await expect.poll(async () => (await investmentAssetOf(request, administrationId, fixedAssetId)) !== undefined, { timeout: 20_000 }).toBe(true)
		const asset = (await investmentAssetOf(request, administrationId, fixedAssetId))!
		expect(asset.description).toBe(ASSET_NAME)
		expect(asset.acquisitionDate.slice(0, 10)).toBe('2026-03-01')
		expect(asset.acquisitionValue, 'EUR 32,000 in cents').toBe(3_200_000)
		expect(asset.kiaEligible, asset.eligibilityReasons.kia).toBe(true)
		expect(asset.eligibilityReasons.kia).toMatch(/^KIA: /)
		for (const scheme of ['eia', 'mia', 'vamil'] as const) {
			expect(asset[`${scheme}Eligible`], `${scheme}: ${asset.eligibilityReasons[scheme]}`).toBe(false)
			expect(asset.eligibilityReasons[scheme], `${scheme}: no list code matched`).toMatch(/geen .*match/i)
		}

		await page.goto(`${APP}/fixed-assets/${fixedAssetId}`)
		await dismissOverlays(page)
		await page.getByText(/^(Investment deduction|Investeringsaftrek)$/).first().click()
		await expect(page.getByText(ASSET_NAME).first(), 'the tab lists the investment asset').toBeVisible({ timeout: 20_000 })
	})

	test('a KIA override to not eligible with reason "privé gebruik" survives a later change @spec REQ-IDC-001', async ({ request }) => {
		test.skip(fixedAssetId === '', 'no activated fixed asset was made')
		const asset = (await investmentAssetOf(request, administrationId, fixedAssetId))!
		const overridden = await request.put(`${OBJECTS}/InvestmentAsset/${idOf(asset)}`, {
			headers: HEADERS,
			data: { ...asset, eligibilityOverride: { scheme: 'kia', value: false, rationale: 'privé gebruik' } },
		})
		expect(overridden.ok(), await overridden.text()).toBe(true)

		// A later change to the fixed asset runs the listener again.
		const changed = await request.put(`${OBJECTS}/FixedAsset/${fixedAssetId}`, { headers: HEADERS, data: { ...fixedAsset, name: `${ASSET_NAME} (gewijzigd)` } })
		expect(changed.ok(), await changed.text()).toBe(true)

		await expect.poll(async () => (await investmentAssetOf(request, administrationId, fixedAssetId))?.description, { timeout: 20_000 }).toBe(`${ASSET_NAME} (gewijzigd)`)
		const after = (await investmentAssetOf(request, administrationId, fixedAssetId))!
		expect(after.kiaEligible, 'the override stands').toBe(false)
		expect(after.eligibilityReasons.kia).toContain('privé gebruik')
		expect(after.eligibilityOverride?.rationale).toBe('privé gebruik')

		// Back to the computed flag for the calculation below.
		const cleared = await request.put(`${OBJECTS}/InvestmentAsset/${idOf(after)}`, { headers: HEADERS, data: { ...after, eligibilityOverride: null } })
		expect(cleared.ok(), await cleared.text()).toBe(true)
		const restored = await request.put(`${OBJECTS}/FixedAsset/${fixedAssetId}`, { headers: HEADERS, data: fixedAsset })
		expect(restored.ok(), await restored.text()).toBe(true)
		await expect.poll(async () => (await investmentAssetOf(request, administrationId, fixedAssetId))?.kiaEligible, { timeout: 20_000 }).toBe(true)
	})

	test('Recalculate 2026 writes the draft claims and the KIA total is the sum of its claims @spec REQ-IDC-002', async ({ page, request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')

		await page.goto(`${APP}/tax/investment-deductions`)
		await dismissOverlays(page)
		await runHeaderAction(page, /^(Recalculate|Herberekenen)$/i)
		const dialog = page.getByTestId('recalculate-investment-deductions-modal')
		await expect(dialog).toBeVisible({ timeout: 10_000 })
		await dialog.getByTestId('recalculate-year').locator('input').fill('2026')
		await dialog.getByTestId('recalculate-submit').click()
		await expect(dialog.getByTestId('recalculate-summary').or(dialog.getByTestId('recalculate-error'))).toBeVisible({ timeout: 30_000 })
		test.skip(await dialog.getByTestId('recalculate-error').isVisible(), 'no KIA tiers are loaded for 2026 on this instance')
		await expect(dialog.getByTestId('recalculate-totals')).toContainText('KIA')

		const answer = await request.post(`${API}/2026/calculate`, { headers: HEADERS, data: { administrationId } })
		expect(answer.status(), await answer.text()).toBe(200)
		const result = await answer.json() as CalculateAnswer
		expect(result.year).toBe(2026)

		const claims = await list(request, 'InvesteringsaftrekClaim', { administrationId, financialYear: 2026 })
		for (const scheme of ['KIA', 'EIA', 'MIA'] as const) {
			const sum = claims.filter((claim) => claim.scheme === scheme).reduce((total, claim) => total + Number(claim.deductionAmount ?? 0), 0)
			expect(sum, `${scheme}: the total is the sum of its claims`).toBe(result.totals[scheme])
		}
		for (const claim of claims.filter((candidate) => candidate.submittedInTaxReturn !== true)) {
			expect(claim.status, 'a recalculated claim is a draft').toBe('draft')
		}

		const asset = await investmentAssetOf(request, administrationId, fixedAssetId)
		if (asset !== undefined && asset.kiaEligible) {
			const own = claims.find((claim) => claim.investmentAssetId === idOf(asset) && claim.scheme === 'KIA')
			expect(own, 'the activated van has a KIA claim').toBeDefined()
			expect(Number(own!.basis), 'over its value').toBe(asset.acquisitionValue)
		}
	})

	test('recalculating a year without KIA tiers is refused and names them @spec REQ-IDC-002', async ({ request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		const tiers = await list(request, 'KIATier', { yearNumber: 2027 })
		test.skip(tiers.some((tier) => Number(tier.yearNumber) === 2027), 'KIA tiers are loaded for 2027 on this instance')

		const res = await request.post(`${API}/2027/calculate`, { headers: HEADERS, data: { administrationId } })
		expect(res.status(), await res.text()).toBe(422)
		expect(((await res.json()) as { error: string }).error).toBe('No KIA tiers are loaded for 2027.')
	})

	test('the investment deductions page opens from the menu and lists the claims per scheme @spec REQ-IDC-003', async ({ page, request }) => {
		test.skip(administrationId === '', 'the e2e user works in no administration on this stack')
		await page.goto(`${APP}/`)
		await dismissOverlays(page)
		await page.getByRole('link', { name: /^(Investment deductions|Investeringsaftrek)$/i }).first().click()
		await expect(page).toHaveURL(/\/tax\/investment-deductions/)

		for (const header of [/^(Scheme|Regeling)$/i, /^(Notification deadline|Meldingstermijn)$/i]) {
			await expect(page.getByText(header).first(), `column ${header}`).toBeVisible({ timeout: 20_000 })
		}
		const claims = await list(request, 'InvesteringsaftrekClaim', { administrationId, financialYear: 2026 })
		test.skip(claims.length === 0, 'no 2026 claims are calculated in this administration')
		await expect(page.getByText(/^KIA$/).first(), 'a KIA claim is listed').toBeVisible({ timeout: 20_000 })
		const withDeadline = claims.find((claim) => ['EIA', 'MIA'].includes(String(claim.scheme)) && String(claim.rvoReportDeadline ?? '') !== '')
		if (withDeadline !== undefined) {
			expect(String(withDeadline.rvoReportDeadline)).toMatch(/^\d{4}-\d{2}-\d{2}/)
		}
	})
})
