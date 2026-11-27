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
 *      and its checks; Generate XML stores a file valid against the bundled
 *      CbC XSD, which is downloaded and the report marked submitted with
 *      CBC-2027-0001 (REQ-CBC-008, REQ-CBC-011);
 *   4. the safe harbour tests: Run safe harbour tests on a 2026 minimum tax
 *      return writes a pass or fail per test and jurisdiction, drafts a
 *      computation for every jurisdiction that passes none, and the Safe
 *      harbour list shows the passes; Generate XML stores no GIR file while
 *      a computed jurisdiction misses per-entity elements, and stores one
 *      claiming the safe harbour for NL and DE (REQ-CBC-009, REQ-CBC-011).
 *
 * Generate XML refuses every file until task 2.1 bundles the OECD schema
 * sets; the tests that need a stored file skip with that reason until then.
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

/** The answer of a Generate XML call: the stored report and the return it is linked on, or the refusal. */
interface GenerateAnswer {
	status: number
	body: { report?: RegisterObject, return?: RegisterObject, error?: string }
}

/** Press Generate XML through its endpoint: `returns/{id}/xml` or `minimum-tax-returns/{id}/xml`. */
async function generateXml(request: APIRequestContext, path: string): Promise<GenerateAnswer> {
	const res = await request.post(`${API}/${path}/xml`, { headers: HEADERS })
	const text = await res.text()
	let body: GenerateAnswer['body']
	try {
		body = JSON.parse(text) as GenerateAnswer['body']
	} catch {
		body = { error: text }
	}
	return { status: res.status(), body }
}

/** Skip while the OECD schema set of task 2.1 is not bundled: every file is refused until then. */
function skipWithoutSchemaSet(answer: GenerateAnswer): void {
	const error = String(answer.body.error ?? '')
	test.skip(answer.status === 422 && /schema set is not in/i.test(error), `the OECD schema set is not bundled (task 2.1): ${error}`)
}

/** Download a stored report file through the reporting endpoint and answer its text. */
async function download(request: APIRequestContext, reportId: string): Promise<string> {
	const res = await request.get(`/index.php/apps/shillinq/api/reporting/download/${reportId}`, { headers: HEADERS })
	expect(res.status(), `download ${reportId}`).toBe(200)
	return res.text()
}

/** Run a lifecycle transition through OpenRegister with its inputs. */
async function transition(request: APIRequestContext, id: string, action: string, data: Record<string, string> = {}): Promise<{ ok: boolean, status: number, text: string }> {
	const res = await request.post(`${OBJECTS}/CbcrReturn/${encodeURIComponent(id)}/transition`, { headers: HEADERS, data: { action, data } })
	return { ok: res.ok(), status: res.status(), text: await res.text() }
}

/** The 2026 CbC reports, the Voorbeeld group's first. */
async function cbcReports(request: APIRequestContext): Promise<RegisterObject[]> {
	const upe = await entityNamed(request, UPE_NAME)
	const reports = (await list(request, 'CbcrReturn')).filter((report) => String(report.period ?? '').includes(String(YEAR)))
	return reports.sort((a, b) => Number(upe !== undefined && b.reportingEntity === idOf(upe)) - Number(upe !== undefined && a.reportingEntity === idOf(upe)))
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

	test('the tax director downloads the 2026 file and marks the report submitted with CBC-2027-0001 @spec REQ-CBC-011', async ({ page, request }) => {
		const report = (await cbcReports(request)).find((candidate) => candidate.state === 'reconciled')
		test.skip(report === undefined, `no reconciled ${YEAR} country-by-country report on this instance`)
		const reportId = idOf(report!)

		const generated = await generateXml(request, `returns/${reportId}`)
		skipWithoutSchemaSet(generated)
		expect(generated.status, JSON.stringify(generated.body)).toBe(201)
		const fileId = String(generated.body.report?.id ?? '')
		expect(fileId, 'the stored file').not.toBe('')
		expect(generated.body.return?.cbcrXmlSubmission, 'the file is linked on the report').toBe(fileId)

		const xml = await download(request, fileId)
		expect(xml).toContain('CBC_OECD')
		expect(xml).toContain('MessageSpec')

		// Marking it submitted asks for the Belastingdienst reference; without one it is refused.
		const bare = await transition(request, reportId, 'submit')
		expect(bare.ok, `submit without a reference is refused: ${bare.text}`).toBe(false)
		const submitted = await transition(request, reportId, 'submit', { taxAuthorityReference: 'CBC-2027-0001' })
		expect(submitted.ok, submitted.text).toBe(true)

		const stored = (await list(request, 'CbcrReturn')).find((candidate) => idOf(candidate) === reportId)
		expect(stored?.state).toBe('submitted')
		expect(stored?.taxAuthorityReference).toBe('CBC-2027-0001')

		await page.goto(`${APP}/taxes/cbc-reports/${reportId}`)
		await dismissOverlays(page)
		await expect(page.getByTestId('cn-detail-page')).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText('CBC-2027-0001').first()).toBeVisible({ timeout: 20_000 })
	})

	test('Generate XML renders the 2026 CbC file valid against CbcXML_v2.0.xsd @spec REQ-CBC-008', async ({ request }) => {
		const reports = (await cbcReports(request)).filter((candidate) => ['draft', 'reconciled'].includes(String(candidate.state ?? '')))
		test.skip(reports.length === 0, `no draft or reconciled ${YEAR} country-by-country report on this instance`)
		const reportId = idOf(reports[0])

		const generated = await generateXml(request, `returns/${reportId}`)
		skipWithoutSchemaSet(generated)
		test.skip(generated.status === 422 && /check/i.test(String(generated.body.error ?? '')), `a blocking check refuses the file: ${generated.body.error}`)
		expect(generated.status, JSON.stringify(generated.body)).toBe(201)

		const stored = (await list(request, 'CbcrReturn')).find((candidate) => idOf(candidate) === reportId)
		const validation = (stored?.lastValidation ?? {}) as Record<string, unknown>
		expect(validation.valid, 'the stored file validated against the bundled XSD').toBe(true)
		expect(String(validation.schemaVersion ?? ''), 'the schema version is recorded').not.toBe('')
		expect(String(validation.xsdChecksum ?? ''), 'the XSD set checksum is recorded').not.toBe('')

		const xml = await download(request, String(generated.body.report?.id ?? ''))
		const summaries = (Array.isArray(stored?.jurisdictionSummaries) ? stored!.jurisdictionSummaries : []) as unknown[]
		const reportsInFile = (xml.match(/<(?:\w+:)?CbcReports[\s>]/g) ?? []).length
		expect(reportsInFile, 'at least one CbcReports').toBeGreaterThan(0)
		if (summaries.length > 0) {
			expect(reportsInFile, 'one CbcReports per jurisdiction summary').toBe(summaries.length)
		}
		expect(xml).toMatch(/<(?:\w+:)?DocSpec[\s>]/)
	})

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

	test('Generate XML stores no GIR file while jurisdictions miss per-entity elements @spec REQ-CBC-009', async ({ request }) => {
		const returns = (await list(request, 'GlobeInformationReturn')).filter((candidate) => String(candidate.period ?? '').includes(String(YEAR)) && candidate.state === 'draft')
		test.skip(returns.length === 0, `no draft ${YEAR} minimum tax return on this instance`)

		// A return whose group has a computed jurisdiction: one that passes no safe harbour test.
		let chosen: RegisterObject | undefined
		for (const candidate of returns) {
			const computations = await list(request, 'Pillar2JurisdictionComputation', { ultimateParentEntity: String(candidate.ultimateParent ?? ''), period: `FY${YEAR}` })
			if (computations.length > 0) {
				chosen = candidate
				break
			}
		}
		test.skip(chosen === undefined, `no ${YEAR} minimum tax return with a computed jurisdiction`)
		const returnId = idOf(chosen!)

		const generated = await generateXml(request, `minimum-tax-returns/${returnId}`)
		test.skip(generated.status === 201, 'every computed jurisdiction holds its per-entity elements, so a file was stored')
		expect(generated.status, JSON.stringify(generated.body)).toBe(422)

		const stored = (await list(request, 'GlobeInformationReturn')).find((candidate) => idOf(candidate) === returnId)
		expect(String(stored?.globeXmlSubmission ?? ''), 'no file is linked').toBe('')
		const missing = (stored?.missingElements ?? []) as Array<Record<string, unknown>>
		test.skip(missing.length === 0, `refused for another reason: ${generated.body.error}`)
		for (const element of missing) {
			expect(String(element.jurisdiction ?? ''), 'each missing element names its jurisdiction').not.toBe('')
			expect(String(element.element ?? ''), 'and the element').not.toBe('')
		}

		const checks = await request.get(`${API}/minimum-tax-returns/${returnId}/checks`, { headers: HEADERS })
		expect(checks.status(), await checks.text()).toBe(200)
	})

	test('Generate XML stores a GIR file claiming the safe harbour for NL and DE @spec REQ-CBC-009', async ({ request }) => {
		const returns = (await list(request, 'GlobeInformationReturn')).filter((candidate) => String(candidate.period ?? '').includes(String(YEAR)) && candidate.state === 'draft')
		test.skip(returns.length === 0, `no draft ${YEAR} minimum tax return on this instance`)

		// A return whose group puts NL and DE under a safe harbour and computes nothing.
		let chosen: RegisterObject | undefined
		for (const candidate of returns) {
			const upe = String(candidate.ultimateParent ?? '')
			const passes = (await list(request, 'Pillar2SafeHarbour', { ultimateParentEntity: upe, period: `FY${YEAR}` })).filter((record) => record.testResult === 'pass')
			const computations = await list(request, 'Pillar2JurisdictionComputation', { ultimateParentEntity: upe, period: `FY${YEAR}` })
			const covered = new Set(passes.map((record) => String(record.jurisdiction)))
			if (covered.has('NL') && covered.has('DE') && computations.length === 0) {
				chosen = candidate
				break
			}
		}
		test.skip(chosen === undefined, `no ${YEAR} minimum tax return with NL and DE both under a safe harbour and nothing computed`)
		const returnId = idOf(chosen!)

		const generated = await generateXml(request, `minimum-tax-returns/${returnId}`)
		skipWithoutSchemaSet(generated)
		expect(generated.status, JSON.stringify(generated.body)).toBe(201)
		const fileId = String(generated.body.report?.id ?? '')
		expect(generated.body.return?.globeXmlSubmission, 'the file is linked on the return').toBe(fileId)

		const stored = (await list(request, 'GlobeInformationReturn')).find((candidate) => idOf(candidate) === returnId)
		expect(((stored?.lastValidation ?? {}) as Record<string, unknown>).valid, 'the file validated against the bundled GIR XSD').toBe(true)

		const xml = await download(request, fileId)
		for (const jurisdiction of ['NL', 'DE']) {
			expect(xml, `${jurisdiction} has its own jurisdiction section`).toContain(jurisdiction)
		}
		expect(xml, 'the summary claims a safe harbour').toMatch(/SafeHarbour/i)
	})
})
