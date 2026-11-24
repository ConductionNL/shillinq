/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: case costs per taakveld in the Iv3 report and per case type
 * (iv3-report-takes-case-costs-by-case-type tasks 7 and 11, REQ-IV3-010 to
 * REQ-IV3-012).
 *
 * The live pass of the change, driven through the routes the Case costs page
 * (/overheid/zaakkosten) and the report catalogue call:
 *
 *   1. two dossiq case types, one on taakveld 6.71 and one on its 2023
 *      refinement 6.71a, each with a case and humaniq hours in Q3 2026 (8 and 4
 *      hours): `GET /api/reporting/subject-costs?period=2026-Q3` shows one row
 *      6.71 with 2 cases and 12 hours and no row 6.71a, the Case costs page
 *      shows that row, and the `iv3` CSV of the report catalogue holds the same
 *      row in its `subjectCostsPerTaskField` section (task 7);
 *   2. a third case of a type without a taakveld, with 2 hours: the per case
 *      type grouping (`grouping=type`) shows one row per type (1 case each, 8,
 *      4 and 2 hours), its total (3 cases, 14 hours) equals the total of the
 *      per taakveld grouping in cases, hours and cost, the page shows the
 *      three types, and the `subject-costs-per-type` CSV holds the same rows
 *      and total (task 11);
 *   3. without dossiq, or with dossiq and without humaniq, the section names
 *      the missing app and shows no figure.
 *
 * The cost of an hour is humaniq's employer rate for the person, which this
 * repo cannot set. The hours are booked on `E2E_HUMANIQ_EMPLOYEE_ID` when the
 * nightly names a priced employee, and then every case must be complete with a
 * cost above zero; without it they are booked on a person humaniq does not
 * know, and every case must be listed as incomplete with its hours counted and
 * no cost added (the aggregation policy of REQ-IV3-010).
 *
 * The live tests skip when dossiq (or its old id procest) or humaniq (or hrmq)
 * is not enabled. The missing-app tests run on exactly the instance the live
 * tests skip on; uninstalling an app in the middle of the nightly would take
 * it away from every other spec, so they do not do that.
 *
 * Everything runs in a municipal administration of its own (unique id, with a
 * membership for the suite's administrator), which the page needs as the
 * active administration; the previous active administration is restored, and
 * every object written here, in shillinq, dossiq and humaniq, and the
 * generated reports, is removed afterwards.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/tasks.md
 *
 * @e2e bookkeeping-iv3-reporting::old-and-new-codes-land-in-one-row
 * @e2e bookkeeping-iv3-reporting::dossiq-is-not-installed
 * @e2e bookkeeping-iv3-reporting::cases-are-grouped-by-their-case-type
 * @e2e bookkeeping-iv3-reporting::a-missing-app-is-named-not-shown-as-zero
 * @e2e bookkeeping-iv3-reporting::the-grouping-exports-to-csv
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { money, OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/index.php/apps/shillinq'
const OR_API = '/index.php/apps/openregister/api'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMINISTRATION_ID = `${UNIQUE_PREFIX}-gemeente`
const QUARTER = '2026-Q3'
const PRICED_PERSON = process.env.E2E_HUMANIQ_EMPLOYEE_ID ?? ''
const PERSON = PRICED_PERSON !== '' ? PRICED_PERSON : `${UNIQUE_PREFIX}-person`
const MISSING_DOSSIQ_FIELD = /^(Case costs per task field come from Dossiq\. Dossiq is not installed\.|Zaakkosten per taakveld komen uit Dossiq\. Dossiq is niet geïnstalleerd\.)$/
const MISSING_DOSSIQ_TYPE = /^(Case costs per case type come from Dossiq\. Dossiq is not installed\.|Zaakkosten per zaaktype komen uit Dossiq\. Dossiq is niet geïnstalleerd\.)$/
const MISSING_HUMANIQ = /^(Hours come from Humaniq\. Humaniq is not installed\.|Uren komen uit Humaniq\. Humaniq is niet geïnstalleerd\.)$/
const TOTAL = /^(Total|Totaal)$/

/** One row of the Case costs tables, as `GET /api/reporting/subject-costs` answers it. */
interface CostRow {
	label: string
	objects: number | null
	hours: number | null
	cost: number | null
	incomplete: string
	types: string
}

/** A case of the test: its type's title, its id and its hours. */
interface TestCase {
	typeTitle: string
	caseId: string
	hours: number
}

test.describe.configure({ mode: 'serial' })

test.describe('iv3-report-takes-case-costs-by-case-type: case costs per taakveld and per case type', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	let enabled: Set<string> | null = null
	let administrationReady = false
	let previousActive: string | null = null
	let hoursRegister = ''
	/** Objects written in another app's register, removed in afterAll. */
	const foreign: Array<{ register: string, schema: string, id: string }> = []
	const reportIds = new Set<string>()
	const cases: TestCase[] = []

	/**
	 * The CSRF token shillinq's own write routes need beside the session cookie.
	 *
	 * @return The request token of an authenticated app page.
	 */
	async function requestToken(): Promise<string> {
		if (token === '') {
			const html = await (await api.get(`${APP}/`)).text()
			token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		}
		return token
	}

	/**
	 * Headers of a JSON write as the administrator.
	 *
	 * @return The headers.
	 */
	async function writeHeaders(): Promise<Record<string, string>> {
		return { requesttoken: await requestToken(), 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
	}

	/**
	 * Whether one of an app's ids is enabled: the provisioning list first, a
	 * probe of the app's own page when that list cannot be read.
	 *
	 * @param ids The app's ids, newest first (FleetAppId's candidates).
	 * @return True when one of them is enabled.
	 */
	async function appEnabled(ids: string[]): Promise<boolean> {
		if (enabled === null) {
			const res = await api.get('/ocs/v2.php/cloud/apps?filter=enabled&format=json', { headers: { 'OCS-APIRequest': 'true' } })
			const body = res.ok() ? await res.json().catch(() => ({})) : {}
			enabled = new Set<string>((body?.ocs?.data?.apps ?? []) as string[])
		}
		for (const id of ids) {
			if (enabled.size > 0 ? enabled.has(id) : (await api.get(`/index.php/apps/${id}/`, { maxRedirects: 0 })).status() === 200) {
				return true
			}
		}
		return false
	}

	/**
	 * Whether dossiq is enabled.
	 *
	 * @return True when dossiq (or procest) is enabled.
	 */
	async function dossiq(): Promise<boolean> {
		return appEnabled(['dossiq', 'procest'])
	}

	/**
	 * Whether humaniq is enabled.
	 *
	 * @return True when humaniq (or hrmq) is enabled.
	 */
	async function humaniq(): Promise<boolean> {
		return appEnabled(['humaniq', 'hrmq'])
	}

	/**
	 * The municipal administration of the test, the administrator's membership
	 * of it, and the administrator switched to it (the page reads the active one).
	 */
	async function administration(): Promise<void> {
		if (administrationReady) {
			return
		}
		const missing = await fx.missingSchema(['Administration', 'AdministrationMembership', 'GeneratedReport'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)
		await fx.create('Administration', {
			administrationCode: ADMINISTRATION_ID,
			name: `Gemeente ${UNIQUE_PREFIX}`,
			legalForm: 'municipality',
			administrationType: 'municipality',
			status: 'actief',
			fiscalYearStartMonth: 1,
			fiscalYearStartDay: 1,
			vatRegime: 'overheid',
		})
		await fx.create('AdministrationMembership', {
			userId: ADMIN_USER,
			administrationId: ADMINISTRATION_ID,
			role: 'controller',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		const context = await api.get(`${APP}/api/administrations/context`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(context.ok(), `administration context: HTTP ${context.status()}`).toBeTruthy()
		previousActive = ((await context.json()).activeAdministrationId ?? null) as string | null
		const switched = await api.post(`${APP}/api/administrations/switch`, { headers: await writeHeaders(), data: { administrationId: ADMINISTRATION_ID } })
		expect(switched.status(), await switched.text()).toBe(200)
		administrationReady = true
	}

	/**
	 * Create an object in another app's register through OpenRegister's object API.
	 *
	 * @param register The register slug.
	 * @param schema   The schema slug.
	 * @param data     The object.
	 * @return Its id.
	 */
	async function createIn(register: string, schema: string, data: Record<string, unknown>): Promise<string> {
		const res = await api.post(`${OR_API}/objects/${register}/${schema}`, { headers: await writeHeaders(), data })
		expect(res.ok(), `create ${register}/${schema}: HTTP ${res.status()} ${await res.text()}`).toBeTruthy()
		const body = await res.json()
		const id = String((body['@self'] ?? body).id ?? body.id ?? '')
		expect(id, `no id for ${register}/${schema}`).not.toBe('')
		foreign.push({ register, schema, id })
		return id
	}

	/**
	 * The register humaniq's hours live in (renamed from hrmq, HrmqCostRateAdapter::registerSlug()).
	 *
	 * @return The register slug.
	 */
	async function hoursRegisterSlug(): Promise<string> {
		if (hoursRegister === '') {
			const probe = await api.get(`${OR_API}/objects/humaniq/TimeEntry?_limit=1`, { headers: { 'OCS-APIRequest': 'true' } })
			hoursRegister = probe.ok() ? 'humaniq' : 'hrmq'
		}
		return hoursRegister
	}

	/**
	 * A dossiq case of a new case type, with hours booked on it in Q3 2026.
	 *
	 * @param suffix    Makes the type unique within the run.
	 * @param taskField The type's taakveld, '' for none.
	 * @param hours     The hours booked.
	 * @param date      The day they are booked on.
	 * @return The case.
	 */
	async function caseWithHours(suffix: string, taskField: string, hours: number, date: string): Promise<TestCase> {
		const typeTitle = `Zaaktype ${suffix} ${UNIQUE_PREFIX}`
		const type: Record<string, unknown> = { title: typeTitle }
		if (taskField !== '') {
			type.iv3TaskField = taskField
		}
		const typeId = await createIn('dossiq', 'caseType', type)
		const caseId = await createIn('dossiq', 'case', { title: `Zaak ${suffix} ${UNIQUE_PREFIX}`, caseType: typeId })
		await createIn(await hoursRegisterSlug(), 'TimeEntry', {
			employeeId: PERSON,
			date,
			hours,
			domainObjectRef: caseId,
			domainObjectType: 'dossiq:case',
			administrationId: ADMINISTRATION_ID,
			description: `${UNIQUE_PREFIX} ${suffix}`,
		})
		const made = { typeTitle, caseId, hours }
		cases.push(made)
		return made
	}

	/**
	 * The rows of the Case costs tables for Q3 2026 in the test's administration.
	 *
	 * @param grouping 'type' for the per case type table, '' for per taakveld.
	 * @return The rows.
	 */
	async function costRows(grouping: '' | 'type'): Promise<CostRow[]> {
		const query = new URLSearchParams({ period: QUARTER, administrationId: ADMINISTRATION_ID })
		if (grouping !== '') {
			query.set('grouping', grouping)
		}
		const res = await api.get(`${APP}/api/reporting/subject-costs?${query.toString()}`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.status(), await res.text()).toBe(200)
		return (await res.json()).rows as CostRow[]
	}

	/**
	 * Generate a report of the catalogue as CSV and read it.
	 *
	 * @param reportType The catalogue id.
	 * @return The CSV lines, split into fields.
	 */
	async function csvOf(reportType: string): Promise<string[][]> {
		const generated = await api.post(`${APP}/api/reporting/generate`, {
			headers: await writeHeaders(),
			data: { reportType, period: QUARTER, administrationId: ADMINISTRATION_ID, format: 'csv' },
		})
		expect(generated.status(), await generated.text()).toBe(201)
		const report = await generated.json()
		reportIds.add(String(report.id ?? ''))
		const download = await api.get(String(report.downloadPath), { headers: { 'OCS-APIRequest': 'true' } })
		expect(download.status()).toBe(200)
		return (await download.text()).split(/\r?\n/).filter((line) => line !== '').map(csvFields)
	}

	/**
	 * Split one fputcsv line into its fields.
	 *
	 * @param line The line.
	 * @return The fields.
	 */
	function csvFields(line: string): string[] {
		const fields: string[] = []
		let field = ''
		let quoted = false
		for (let i = 0; i < line.length; i++) {
			const char = line[i]
			if (quoted && char === '"' && line[i + 1] === '"') {
				field += '"'
				i++
			} else if (char === '"') {
				quoted = !quoted
			} else if (char === ',' && !quoted) {
				fields.push(field)
				field = ''
			} else {
				field += char
			}
		}
		fields.push(field)
		return fields
	}

	/**
	 * Open the Case costs page for Q3 2026.
	 *
	 * @param page The page.
	 */
	async function openCaseCosts(page: Page): Promise<void> {
		await page.goto(`${APP}/overheid/zaakkosten`)
		await page.getByLabel(/period|periode/i).first().fill(QUARTER)
		await page.getByRole('button', { name: /^(show|tonen)$/i }).first().click()
	}

	/**
	 * Check the cost of a row against the person the hours are booked on.
	 *
	 * @param row      The row.
	 * @param caseIds  The cases in it.
	 */
	function expectCostPolicy(row: CostRow, caseIds: string[]): void {
		if (PRICED_PERSON !== '') {
			expect(row.incomplete, 'every hour is priced').toBe('')
			expect(Number(row.cost)).toBeGreaterThan(0)
		} else {
			expect(row.incomplete.split(', ').sort(), 'an unpriced case is listed, not summed').toEqual([...caseIds].sort())
			expect(Number(row.cost)).toBe(0)
		}
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		const headers = api ? await writeHeaders() : {}
		if (administrationReady && previousActive !== null) {
			await api?.post(`${APP}/api/administrations/switch`, { headers, data: { administrationId: previousActive } }).catch(() => undefined)
		}
		for (const { register, schema, id } of [...foreign].reverse()) {
			await api?.delete(`${OR_API}/objects/${register}/${schema}/${id}`, { headers }).catch(() => undefined)
		}
		for (const id of reportIds) {
			await fx?.remove('GeneratedReport', id)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('hours on cases typed 6.71 and 6.71a land in one row 6.71 of the case cost section @spec REQ-IV3-010', async ({ page }) => {
		test.skip(!(await dossiq()), 'dossiq is not enabled on this instance; the missing-app test below covers it')
		test.skip(!(await humaniq()), 'humaniq is not enabled on this instance; the missing-app test below covers it')
		await administration()

		const old = await caseWithHours('A', '6.71', 8, '2026-08-04')
		const refined = await caseWithHours('B', '6.71a', 4, '2026-08-05')

		const rows = await costRows('')
		expect(rows.some((row) => row.label === '6.71a'), 'the refinement code has no row of its own').toBe(false)
		const [row] = rows.filter((candidate) => candidate.label === '6.71')
		expect(row, 'one row 6.71').toBeTruthy()
		expect(row.objects).toBe(2)
		expect(row.hours).toBe(12)
		expectCostPolicy(row, [old.caseId, refined.caseId])
		const total = rows.find((candidate) => TOTAL.test(candidate.label))
		expect(total?.objects).toBe(2)
		expect(total?.hours).toBe(12)

		// The page shows the row.
		await openCaseCosts(page)
		const pageRow = page.getByRole('row').filter({ hasText: /^\s*6\.71(?!a)/ }).first()
		await expect(pageRow).toBeVisible({ timeout: 20_000 })
		await expect(pageRow).toContainText('2')
		await expect(pageRow).toContainText('12')

		// The Iv3 report's CSV holds the same row in its own section, after the GL totals.
		const csv = await csvOf('iv3')
		const header = csv.findIndex((line) => line[0] === 'subjectCostsPerTaskField')
		expect(header, 'the CSV has the case cost section').toBeGreaterThan(-1)
		const section = csv.slice(header + 1)
		const line = section.find((fields) => fields[0] === '6.71')
		expect(line, 'the section has the row 6.71').toBeTruthy()
		expect(line?.[1]).toBe('2')
		expect(line?.[2]).toBe('12.00')
		expect(money(Number(line?.[3]))).toBe(money(Number(row.cost)))
		expect(line?.[4]).toBe(PRICED_PERSON !== '' ? '0' : '2')
		expect(section.some((fields) => fields[0] === '6.71a')).toBe(false)
	})

	test('a third case type gets its own row and the per type total equals the per taakveld total, on the page and in the CSV @spec REQ-IV3-012', async ({ page }) => {
		test.skip(!(await dossiq()), 'dossiq is not enabled on this instance; the missing-app test below covers it')
		test.skip(!(await humaniq()), 'humaniq is not enabled on this instance; the missing-app test below covers it')
		test.skip(cases.length !== 2, 'the hours of the 6.71 test above were not booked')
		await administration()

		const untyped = await caseWithHours('C', '', 2, '2026-09-10')

		const perType = await costRows('type')
		for (const made of cases) {
			const [row] = perType.filter((candidate) => candidate.label === made.typeTitle)
			expect(row, `one row for ${made.typeTitle}`).toBeTruthy()
			expect(row.objects).toBe(1)
			expect(row.hours).toBe(made.hours)
			expectCostPolicy(row, [made.caseId])
		}
		const typeTotal = perType.find((candidate) => TOTAL.test(candidate.label))
		expect(typeTotal?.objects).toBe(3)
		expect(typeTotal?.hours).toBe(14)

		// The same rows grouped per taakveld: the untyped case is on "No task field", naming its type.
		const perField = await costRows('')
		const noField = perField.find((candidate) => candidate.label === 'No task field' || candidate.label === 'Geen taakveld')
		expect(noField?.objects).toBe(1)
		expect(noField?.types).toBe(untyped.typeTitle)
		const fieldTotal = perField.find((candidate) => TOTAL.test(candidate.label))
		expect(typeTotal?.objects).toBe(fieldTotal?.objects)
		expect(typeTotal?.hours).toBe(fieldTotal?.hours)
		expect(money(Number(typeTotal?.cost))).toBe(money(Number(fieldTotal?.cost)))

		// And equal to the case cost section of the Iv3 report itself.
		const iv3 = await csvOf('iv3')
		const section = iv3.slice(iv3.findIndex((line) => line[0] === 'subjectCostsPerTaskField') + 1)
		expect(section.length, 'the Iv3 CSV has the case cost section').toBeGreaterThan(0)
		expect(section.reduce((sum, line) => sum + Number(line[1]), 0)).toBe(3)
		expect(money(section.reduce((sum, line) => sum + Number(line[2]), 0))).toBe(14)
		expect(money(section.reduce((sum, line) => sum + Number(line[3]), 0))).toBe(money(Number(typeTotal?.cost)))

		// The page shows one row per type.
		await openCaseCosts(page)
		for (const made of cases) {
			await expect(page.getByRole('row').filter({ hasText: made.typeTitle }).first()).toBeVisible({ timeout: 20_000 })
		}

		// "Zaakkosten per zaaktype" as CSV: the same rows and the same total.
		const csv = await csvOf('subject-costs-per-type')
		expect(csv[0]).toEqual(['period', 'type', 'objects', 'hours', 'cost', 'incomplete'])
		for (const made of cases) {
			const line = csv.find((fields) => fields[1] === made.typeTitle)
			const row = perType.find((candidate) => candidate.label === made.typeTitle)
			expect(line, `the CSV has a line for ${made.typeTitle}`).toBeTruthy()
			expect(line).toEqual([QUARTER, made.typeTitle, '1', made.hours.toFixed(2), Number(row?.cost).toFixed(2), PRICED_PERSON !== '' ? '0' : '1'])
		}
		const totalLine = csv.find((fields) => TOTAL.test(fields[1] ?? ''))
		expect(totalLine?.slice(2, 5)).toEqual(['3', '14.00', Number(typeTotal?.cost).toFixed(2)])
	})

	test('without dossiq the case cost section and the per type report name dossiq and show no figure @spec REQ-IV3-011 @spec REQ-IV3-012', async ({ page }) => {
		test.skip(await dossiq(), 'dossiq is enabled here; uninstalling it mid-run would take it from every other spec in the nightly, so this runs on an instance without dossiq')
		await administration()

		const [field] = await costRows('')
		expect(field.label).toMatch(MISSING_DOSSIQ_FIELD)
		expect(field.objects).toBeNull()
		expect(field.cost).toBeNull()
		const [type] = await costRows('type')
		expect(type.label).toMatch(MISSING_DOSSIQ_TYPE)
		expect(type.objects).toBeNull()
		expect(type.cost).toBeNull()

		await openCaseCosts(page)
		await expect(page.getByText(MISSING_DOSSIQ_FIELD).first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText(MISSING_DOSSIQ_TYPE).first()).toBeVisible()
		await expect(page.getByText(/€\s?0[.,]00|EUR\s?0[.,]00/)).toHaveCount(0)
	})

	test('with dossiq and without humaniq the per type report names humaniq and shows no figure @spec REQ-IV3-012', async ({ page }) => {
		test.skip(!(await dossiq()), 'dossiq is not enabled here; the dossiq message is the one shown, covered above')
		test.skip(await humaniq(), 'humaniq is enabled here; uninstalling it mid-run would take it from every other spec in the nightly, so this runs on an instance without humaniq')
		await administration()

		const [type] = await costRows('type')
		expect(type.label).toMatch(MISSING_HUMANIQ)
		expect(type.objects).toBeNull()
		expect(type.cost).toBeNull()

		await openCaseCosts(page)
		await expect(page.getByText(MISSING_HUMANIQ).first()).toBeVisible({ timeout: 20_000 })
		await expect(page.getByText(/€\s?0[.,]00|EUR\s?0[.,]00/)).toHaveCount(0)
	})
})
