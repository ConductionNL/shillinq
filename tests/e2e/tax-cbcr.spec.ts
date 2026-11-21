/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the country-by-country report and the minimum tax pages
 * (tax-cbcr-and-pillar2-returns tasks 6.1 to 6.3, REQ-CBC-001, REQ-CBC-002,
 * REQ-CBC-008, REQ-CBC-009, REQ-CBC-011).
 *
 *   1. the scope test: Assess scope for 2026 on the Voorbeeld group's
 *      ultimate parent stores an assessment that is in scope for CbC when
 *      2025 reached EUR 750M, due twelve months after year end, and in scope
 *      for Pillar Two only when two of the four years before reached it
 *      (REQ-CBC-001);
 *   2. figures from the books: Read figures from the books for 2026 on
 *      Voorbeeld Productie B.V. stores its figures with source books, the
 *      EUR 40M sold to the customer standing for Voorbeeld GmbH as related
 *      revenue and the EUR 300M to third parties as unrelated (REQ-CBC-002);
 *   3. filing the CbC report: the 2026 report page shows its jurisdictions
 *      and its checks; generating and downloading the 2026 file and marking
 *      it submitted with CBC-2027-0001 wait for the CbC XML generator
 *      (REQ-CBC-008, REQ-CBC-011);
 *   4. the safe harbour tests: Run safe harbour tests on a 2026 minimum tax
 *      return writes a pass or fail per test and jurisdiction, drafts a
 *      computation for every jurisdiction that passes none, and the Safe
 *      harbour list shows the passes; Generate XML waits for the GIR
 *      generator (REQ-CBC-009, REQ-CBC-011).
 *
 * The Voorbeeld group (Voorbeeld Groep Holding B.V., Voorbeeld GmbH,
 * Voorbeeld Productie B.V.) is the test data design.md names; it is not a
 * shipped seed, so each test skips when its records are absent. Assess
 * scope, Read figures and Run safe harbour tests write their own records in
 * place (one per group, year and jurisdiction), so running them again
 * leaves the same records; the spec deletes nothing. The caller is the
 * suite's administrator (globalSetup's storage state).
 *
 * Written, not run: it needs a live instance.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/specs/bookkeeping-cbcr-pillar2/spec.md
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.1
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.2
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.3
 *
 * @e2e bookkeeping-cbcr-pillar2::groep-overschrijdt-eur-750m-drempel-in-fy2025
 * @e2e bookkeeping-cbcr-pillar2::related-revenue-is-split-out-from-the-books
 * @e2e bookkeeping-cbcr-pillar2::a-tax-director-files-the-cbc-report
 * @e2e bookkeeping-cbcr-pillar2::cbcr-indiening-2026-naar-belastingdienst
 * @e2e bookkeeping-cbcr-pillar2::gir-fy2026-voor-groep-van-23-jurisdicties
 * @e2e bookkeeping-cbcr-pillar2::every-jurisdiction-under-safe-harbour
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/apps/shillinq'
const API = '/index.php/apps/shillinq/api/cbcr'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const HEADERS = { 'OCS-APIRequest': 'true' }
const YEAR = 2026
const THRESHOLD = 750_000_000
const UPE_NAME = 'Voorbeeld Groep Holding B.V.'
const PRODUCTIE_NAME = 'Voorbeeld Productie B.V.'

/** An OpenRegister object as the list endpoint answers it. */
interface RegisterObject {
	id?: string
	'@self'?: { id?: string }
	[field: string]: unknown
}

/** One year of revenue on a scope assessment. */
interface RevenueYear {
	year: number
	amount: number | null
	source: 'consolidated-statements' | 'entered' | 'none'
}

/** The GroupScopeAssessment GroupScopeService::assess stores. */
interface ScopeAssessment {
	ultimateParentEntity: string
	fiscalYear: number
	fiscalYearEnd: string
	revenueByYear: RevenueYear[]
	cbcrInScope: boolean
	pillar2InScope: boolean
	cbcrFilingDeadline: string | null
	girFilingDeadline: string | null
}

/** The CbcrEntityFigures CbcrFiguresService::readFromBooks stores. */
interface EntityFigures {
	entity: string
	fiscalYear: number
	source: 'books' | 'entered' | 'imported'
	currency: string
	unrelatedPartyRevenue: number
	relatedPartyRevenue: number
	unmappedAccounts: unknown[]
}

/** The answer of the safe harbour endpoint, per jurisdiction. */
interface SafeHarbourAnswer {
	jurisdictions: Record<string, { passed: string[], computation: string | null }>
}

/** One check the checks endpoint answers. */
interface ChecksAnswer {
	checks: Array<Record<string, unknown>>
	blocking: unknown[]
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

/** Run a detail page's header action: a button when it stands alone, else an entry of its Actions menu. */
async function runHeaderAction(page: Page, name: RegExp): Promise<void> {
	const button = page.getByRole('button', { name }).first()
	if (await button.isVisible().catch(() => false)) {
		await button.click()
		return
	}
	await page.getByTestId('cn-detail-page').getByRole('button', { name: /^(Actions|Acties)$/i }).first().click()
	await page.getByRole('menuitem', { name }).first().click()
}

/** List register objects matching the given fields. */
async function list(request: APIRequestContext, schema: string, filters: Record<string, string | number> = {}): Promise<RegisterObject[]> {
	const query = new URLSearchParams({ _limit: '500' })
	for (const [field, value] of Object.entries(filters)) {
		query.set(field, String(value))
	}
	const res = await request.get(`${OBJECTS}/${schema}?${query.toString()}`, { headers: HEADERS })
	expect(res.ok(), `list ${schema}: ${res.status()}`).toBe(true)
	return ((await res.json()) as { results?: RegisterObject[] }).results ?? []
}

/** The group entity of that name, or undefined. */
async function entityNamed(request: APIRequestContext, name: string): Promise<RegisterObject | undefined> {
	return (await list(request, 'GroupEntityRegistry', { entityName: name })).find((entity) => entity.entityName === name)
}

/** Open a fiscal year action dialog on the entity page, run it for the year and wait for its outcome. */
async function runFiscalYearAction(page: Page, entityId: string, action: RegExp): Promise<void> {
	await page.goto(`${APP}/taxes/group-entities/${entityId}`)
	await dismissOverlays(page)
	await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 20_000 })
	await runHeaderAction(page, action)
	const dialog = page.getByTestId('fiscal-year-action-modal')
	await expect(dialog).toBeVisible({ timeout: 10_000 })
	await dialog.getByTestId('fiscal-year-action-year').locator('input').fill(String(YEAR))
	await dialog.getByTestId('fiscal-year-action-submit').click()
	await expect(dialog.getByTestId('fiscal-year-action-error'), 'the action is not refused').toBeHidden({ timeout: 30_000 })
	await expect(dialog.getByTestId('fiscal-year-action-done')).toBeVisible({ timeout: 30_000 })
}

/** Whether a year's revenue reached the threshold. */
function reached(assessment: ScopeAssessment, year: number): boolean {
	const row = assessment.revenueByYear.find((candidate) => candidate.year === year)
	return row !== undefined && row.amount !== null && row.amount >= THRESHOLD
}

test.describe('tax-cbcr-and-pillar2-returns: country-by-country report and minimum tax', () => {
	test('Assess scope for 2026 stores the CbC and Pillar Two outcome with the filing deadline @spec REQ-CBC-001', async ({ page, request }) => {
		const upe = await entityNamed(request, UPE_NAME)
		test.skip(upe === undefined, `no group entity "${UPE_NAME}" on this instance`)
		const upeId = idOf(upe!)

		await runFiscalYearAction(page, upeId, /^(Assess scope|Reikwijdte toetsen)$/i)

		const stored = await list(request, 'GroupScopeAssessment', { ultimateParentEntity: upeId, fiscalYear: YEAR })
		const assessment = stored.find((row) => Number(row.fiscalYear) === YEAR) as ScopeAssessment | undefined
		expect(assessment, 'one stored assessment for the group and year').toBeDefined()
		for (const row of assessment!.revenueByYear) {
			expect(['consolidated-statements', 'entered', 'none'], `the source of ${row.year} is shown`).toContain(row.source)
		}

		// The one-year CbC rule and the two-in-four Pillar Two rule.
		expect(assessment!.cbcrInScope, 'CbC follows the year before').toBe(reached(assessment!, YEAR - 1))
		const yearsReached = [1, 2, 3, 4].filter((back) => reached(assessment!, YEAR - back)).length
		expect(assessment!.pillar2InScope, 'Pillar Two needs two of the four years before').toBe(yearsReached >= 2)
		if (assessment!.cbcrInScope && assessment!.fiscalYearEnd === `${YEAR}-12-31`) {
			expect(assessment!.cbcrFilingDeadline, 'CbC is due twelve months after year end').toBe(`${YEAR + 1}-12-31`)
		}

		// The scenario's series: EUR 720M in 2024 and EUR 805M in 2025.
		const series = assessment!.revenueByYear
		const scenario = series.some((row) => row.year === 2024 && row.amount === 720_000_000)
			&& series.some((row) => row.year === 2025 && row.amount === 805_000_000)
		if (scenario) {
			expect(assessment!.cbcrInScope).toBe(true)
			expect(assessment!.cbcrFilingDeadline).toBe('2027-12-31')
			expect(assessment!.pillar2InScope, 'only one of the four preceding years reached EUR 750M').toBe(false)
		}

		await expect(page.getByText(/^(Scope|Reikwijdte)$/).first(), 'the Scope list of the parent page').toBeVisible({ timeout: 20_000 })
	})

	test('Read figures from the books for 2026 splits Productie\'s related revenue out @spec REQ-CBC-002', async ({ page, request }) => {
		const productie = await entityNamed(request, PRODUCTIE_NAME)
		test.skip(productie === undefined, `no group entity "${PRODUCTIE_NAME}" on this instance`)
		test.skip(String(productie!.administrationId ?? '') === '', `${PRODUCTIE_NAME} keeps no books here`)
		const entityId = idOf(productie!)

		await runFiscalYearAction(page, entityId, /^(Read figures from the books|Cijfers uit de boeken lezen)$/i)

		const stored = await list(request, 'CbcrEntityFigures', { entity: entityId, fiscalYear: YEAR })
		const figures = stored.find((row) => Number(row.fiscalYear) === YEAR) as EntityFigures | undefined
		expect(figures, 'the 2026 figures of the entity').toBeDefined()
		expect(figures!.source, 'read from the books').toBe('books')
		expect(Array.isArray(figures!.unmappedAccounts)).toBe(true)
		expect(Math.round(figures!.unrelatedPartyRevenue), 'EUR 300M sold to third parties').toBe(300_000_000)
		expect(Math.round(figures!.relatedPartyRevenue), 'EUR 40M sold to the customer standing for Voorbeeld GmbH').toBe(40_000_000)
	})

	test('the 2026 CbC report page shows its jurisdictions and its checks @spec REQ-CBC-011', async ({ page, request }) => {
		const upe = await entityNamed(request, UPE_NAME)
		const reports = (await list(request, 'CbcrReturn')).filter((report) => String(report.period ?? '').includes(String(YEAR)))
		const report = reports.find((candidate) => upe !== undefined && candidate.reportingEntity === idOf(upe)) ?? reports[0]
		test.skip(report === undefined, `no ${YEAR} country-by-country report on this instance`)
		const reportId = idOf(report!)

		const res = await request.get(`${API}/returns/${reportId}/checks`, { headers: HEADERS })
		expect(res.status(), await res.text()).toBe(200)
		const checks = await res.json() as ChecksAnswer
		expect(Array.isArray(checks.checks)).toBe(true)
		expect(Array.isArray(checks.blocking)).toBe(true)

		await page.goto(`${APP}/taxes/cbc-reports/${reportId}`)
		await dismissOverlays(page)
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText(/^(Jurisdictions|Jurisdicties)$/i).first()).toBeVisible({ timeout: 20_000 })
	})

	// Missing: Generate XML (tasks 4.1, lib/Reporting/Generator/CbcXmlGenerator.php) is not built, so no 2026 file can be stored, downloaded from the Files tab or filed.
	test.fixme('the tax director downloads the 2026 file and marks the report submitted with CBC-2027-0001 @spec REQ-CBC-011', async () => {})

	// Missing: Generate XML (tasks 4.1, CbcXmlGenerator with the bundled OECD CbC XSD of task 2.1) is not built.
	test.fixme('Generate XML renders the 2026 CbC file valid against CbcXML_v2.0.xsd @spec REQ-CBC-008', async () => {})

	test('Run safe harbour tests for 2026 shows pass or fail per jurisdiction and drafts the rest @spec REQ-CBC-009', async ({ page, request }) => {
		const returns = (await list(request, 'GlobeInformationReturn')).filter((candidate) => String(candidate.period ?? '').includes(String(YEAR)))
		test.skip(returns.length === 0, `no ${YEAR} minimum tax return on this instance`)

		// The first 2026 return whose group is in scope for the minimum tax.
		let chosen: { id: string, upe: string, answer: SafeHarbourAnswer } | undefined
		for (const candidate of returns) {
			const res = await request.post(`${API}/minimum-tax-returns/${idOf(candidate)}/safe-harbour`, { headers: HEADERS })
			if (res.status() === 200) {
				chosen = { id: idOf(candidate), upe: String(candidate.ultimateParent ?? ''), answer: await res.json() as SafeHarbourAnswer }
				break
			}
			expect([404, 422], `a refusal, not a failure: ${await res.text()}`).toContain(res.status())
		}
		test.skip(chosen === undefined, `no ${YEAR} minimum tax return of a group in scope for Pillar Two`)

		const jurisdictions = Object.entries(chosen!.answer.jurisdictions)
		const records = await list(request, 'Pillar2SafeHarbour', { ultimateParentEntity: chosen!.upe, period: `FY${YEAR}` })
		const computations = await list(request, 'Pillar2JurisdictionComputation', { ultimateParentEntity: chosen!.upe, period: `FY${YEAR}` })
		for (const [jurisdiction, outcome] of jurisdictions) {
			const tests = records.filter((record) => record.jurisdiction === jurisdiction)
			expect(tests.length, `${jurisdiction}: a result per test in the 2026 window`).toBeGreaterThan(0)
			for (const record of tests) {
				expect(['pass', 'fail']).toContain(record.testResult)
			}
			const passed = tests.filter((record) => record.testResult === 'pass').map((record) => String(record.testApplied)).sort()
			expect(passed, `${jurisdiction}: the stored passes are the answered ones`).toEqual([...outcome.passed].sort())
			if (outcome.passed.length === 0) {
				expect(outcome.computation, `${jurisdiction} passes none, so it gets a draft computation`).not.toBeNull()
				expect(computations.some((computation) => computation.jurisdiction === jurisdiction)).toBe(true)
			} else {
				expect(outcome.computation, `${jurisdiction} passes a test, so it gets no computation`).toBeNull()
			}
		}

		await page.goto(`${APP}/taxes/minimum-tax/${chosen!.id}`)
		await dismissOverlays(page)
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 20_000 })
		await runHeaderAction(page, /^(Run safe harbour tests|Safe-harbourtoetsen uitvoeren)$/i)
		await expect(page.getByText(/^Safe harbour$/i).first()).toBeVisible({ timeout: 20_000 })
		for (const [jurisdiction, outcome] of jurisdictions.filter(([, candidate]) => candidate.passed.length > 0)) {
			const row = page.locator('tr', { hasText: jurisdiction }).filter({ hasText: /pass/i }).first()
			await expect(row, `${jurisdiction} shows its pass (${outcome.passed.join(', ')})`).toBeVisible({ timeout: 20_000 })
		}
	})

	// Missing: Generate XML on the minimum tax return (tasks 4.3, lib/Reporting/Generator/GirXmlGenerator.php) is not built, so the missing-element list and the GIR file cannot be checked.
	test.fixme('Generate XML stores no GIR file while jurisdictions miss per-entity elements @spec REQ-CBC-009', async () => {})

	// Missing: Generate XML on the minimum tax return (tasks 4.3, GirXmlGenerator with the bundled GIR XSD of task 2.1) is not built.
	test.fixme('Generate XML stores a GIR file claiming the safe harbour for NL and DE @spec REQ-CBC-009', async () => {})
})
