/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: a grant settled and accounted for, the SiSa appendix of
 * 2026 and an EU declaration with its evidence (public-sector-grant-accountability
 * task 4.2, REQ-SUBV-011, REQ-SUBV-012, REQ-SISA-012, REQ-SISA-013,
 * REQ-EUF-012, REQ-EUF-013, REQ-EUF-007).
 *
 * The live pass of the change, driven through the transitions and routes the
 * grant page, the SiSa panel and the EU project page call:
 *
 *   1. a grant given to a sports club with EUR 10,000 paid out is determined
 *      at EUR 8,500 (the `vaststellen` transition): the grant shows
 *      EUR 1,500 reclaimed and a draft invoice of EUR 1,500 to the club names
 *      the grant (REQ-SUBV-011);
 *   2. generate report on a grant of EUR 150,000
 *      (POST /api/v1/public-sector/grants/{id}/report) stores the report and
 *      marks an auditor statement as required (REQ-SUBV-012);
 *   3. a specific grant with regulation code D8 and EUR 120,000 of posted
 *      spending in 2026: the appendix for 2026 (GET /api/v1/public-sector/sisa)
 *      reads EUR 120,000 for its spending indicator and marks the output
 *      indicator without a value as missing, and the CSV export has one row per
 *      indicator in the BZK column order (REQ-SISA-012, REQ-SISA-013);
 *   4. three eligible expenditures in 2026-Q3, one without a certified
 *      supporting document: proposing the declaration on the EU project
 *      (POST /api/v1/public-sector/eu-projects/{id}/declarations) gives a
 *      draft with two expenditures, their total and EU share, and lists the
 *      third as left out for missing evidence; submitting the declaration
 *      submits both expenditures (REQ-EUF-012, REQ-EUF-013);
 *   5. an irregularity of EUR 15,400 (double funding) does not escalate
 *      without the external report reference and a recovery amount, and blocks
 *      a new declaration on the project; one of EUR 2,000 escalates without a
 *      reference (REQ-EUF-007).
 *
 * The routes check the caller's membership of the administration. The
 * administration defaults to the seed's `ADM-001`; `E2E_ADMINISTRATION_ID`
 * names another. A 404 from a route means the administrator holds no
 * membership there, and the test skips and says so.
 *
 * Grants, expenditures and supporting documents are seeded in the state the
 * scenario starts from (granted, declared, draft) through the object API: the
 * grant's `verleen` needs the dual-control approval workflow and an
 * expenditure's `declare` an active eligibility rule, which belong to their own
 * suites. When the instance keeps a seeded object in its initial state the
 * test skips and says so.
 *
 * Every object written here is removed afterwards, the reclaim invoice, the
 * report, the auditor statement and the declaration the app wrote included.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md
 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-sisa-reporting/spec.md
 * @spec openspec/changes/public-sector-grant-accountability/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 * @spec openspec/changes/public-sector-grant-accountability/tasks.md
 *
 * @e2e bookkeeping-subsidie-verantwoording::a-grants-officer-determines-a-grant-below-what-was-paid
 * @e2e bookkeeping-subsidie-verantwoording::a-grants-officer-generates-the-report
 * @e2e bookkeeping-sisa-reporting::a-controller-prepares-the-sisa-appendix
 * @e2e bookkeeping-sisa-reporting::a-controller-exports-the-appendix
 * @e2e bookkeeping-single-audit-eu-fondsen::a-project-leader-proposes-the-quarterly-declaration
 * @e2e bookkeeping-single-audit-eu-fondsen::a-project-leader-submits-the-declaration
 * @e2e bookkeeping-single-audit-eu-fondsen::double-funding-above-the-threshold-blocks-the-project
 * @e2e bookkeeping-single-audit-eu-fondsen::a-small-irregularity-escalates-without-a-reference
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const PUBLIC_SECTOR_API = '/index.php/apps/shillinq/api/v1/public-sector'
const HEADERS = { 'OCS-APIRequest': 'true' }
const ADMINISTRATION_ID = process.env.E2E_ADMINISTRATION_ID ?? 'ADM-001'
const SISA_YEAR = '2026'
const BZK_COLUMNS = ['Code', 'Specifieke uitkering', 'Beschikking', 'Indicator', 'Omschrijving', 'Waarde', 'Eenheid']
const A_SHA256 = 'a3f1c2d4e5b6978812345678901234567890abcdefabcdefabcdefabcdef0123'

/** One appendix row as SisaAppendix answers it. */
interface SisaRow {
	grantId: string
	regulationCode: string
	subsidyNumber: string
	indicatorCode: string
	kind: 'spending' | 'income' | 'output'
	value: number | null
	source: string
	missing: boolean
}

/** A proposed declaration as EuDeclarationBuilder answers it. */
interface Declaration {
	id: string
	state: string
	expenditureIds: string[]
	eligibleTotal: number
	euShare: number
	leftOut: Array<{ expenditureId: string, reason: string }>
}

/**
 * The cells of one `;`-separated, double-quoted CSV line.
 *
 * @param line The line.
 * @return Its cells, unquoted.
 */
function cells(line: string): string[] {
	return line.split(';').map((cell) => cell.replace(/^"|"$/g, '').replace(/""/g, '"'))
}

test.describe('public-sector-grant-accountability: grants, SiSa and EU declarations', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	const written: Array<{ schema: string, id: string }> = []

	/**
	 * The CSRF token shillinq's own write routes need beside the session cookie.
	 *
	 * @return The request token of an authenticated app page.
	 */
	async function requestToken(): Promise<string> {
		if (token === '') {
			const html = await (await api.get('/index.php/apps/shillinq/')).text()
			token = html.match(/data-requesttoken="([^"]+)"/)?.[1] ?? ''
		}
		return token
	}

	/**
	 * POST a JSON body to a public-sector route as the administrator.
	 *
	 * @param path The route below the public-sector API.
	 * @param data The body.
	 * @return The response.
	 */
	async function post(path: string, data: Record<string, unknown>) {
		return api.post(PUBLIC_SECTOR_API + path, {
			headers: { requesttoken: await requestToken(), 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data,
		})
	}

	/**
	 * A grant in the state the scenario starts from, skipping when the instance
	 * keeps it in its initial state.
	 *
	 * @param suffix Makes the grant number unique within this run.
	 * @param fields The grant's own fields.
	 * @return The grant's id and number.
	 */
	async function grant(suffix: string, fields: Record<string, unknown>): Promise<{ id: string, subsidyNumber: string }> {
		const subsidyNumber = `${UNIQUE_PREFIX}-SUB-${suffix}`
		const { id } = await fx.create('Subsidie', {
			administrationId: ADMINISTRATION_ID,
			subsidyNumber,
			requestDate: '2026-01-15',
			decisionDate: '2026-02-01',
			currency: 'EUR',
			state: 'granted',
			...fields,
		})
		const state = String((await fx.get('Subsidie', id)).state ?? '')
		test.skip(state !== 'granted', 'the instance keeps a seeded grant in its initial request state; verleen needs the dual-control approval workflow')
		return { id, subsidyNumber }
	}

	/**
	 * Fire a lifecycle transition.
	 *
	 * @param id     The object id.
	 * @param action The transition.
	 * @return Whether it passed, and its answer for the message.
	 */
	async function transition(id: string, action: string): Promise<{ ok: boolean, text: string }> {
		const res = await fx.transition(id, action)
		return { ok: res.ok(), text: `${action} on ${id}: HTTP ${res.status()} ${await res.text()}` }
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		for (const { schema, id } of written.reverse()) {
			await fx?.remove(schema, id)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('determining a grant at EUR 8,500 after EUR 10,000 paid out reclaims EUR 1,500 on a draft invoice @spec REQ-SUBV-011', async () => {
		const missing = await fx.missingSchema(['Subsidie', 'CustomerMaster', 'ARInvoice'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const { id: clubId } = await fx.create('CustomerMaster', {
			customerId: `${UNIQUE_PREFIX}-ZWALUWEN`,
			legalName: `Sportvereniging De Zwaluwen ${UNIQUE_PREFIX}`,
			email: 'penningmeester@example.org',
			administrationId: ADMINISTRATION_ID,
			lifecycleState: 'active',
		})
		const { id: grantId, subsidyNumber } = await grant('SPORT', {
			direction: 'outgoing',
			counterpartyName: `Sportvereniging De Zwaluwen ${UNIQUE_PREFIX}`,
			counterpartyId: clubId,
			schemeName: 'Subsidieregeling sport 2026',
			requestedAmount: 10000,
			grantedAmount: 10000,
			paidOutAmount: 10000,
			determinedAmount: 8500,
			determinationDate: '2026-11-02',
			prestatieverantwoording: 'Activiteitenverslag 2026 ontvangen',
		})

		const determined = await transition(grantId, 'vaststellen')
		expect(determined.ok, determined.text).toBe(true)

		const settled = await fx.get('Subsidie', grantId)
		const invoiceId = String(settled.reclaimInvoiceId ?? '')
		if (invoiceId !== '') {
			written.push({ schema: 'ARInvoice', id: invoiceId })
		}
		expect(settled.state).toBe('determined')
		expect(Number(settled.reclaimedAmount)).toBe(1500)
		expect(invoiceId, 'a reclaim invoice is written').not.toBe('')
		expect(settled.reclaimPayableId ?? '', 'a grant given gets no payable').toBe('')

		const invoice = await fx.get('ARInvoice', invoiceId)
		expect(invoice.lifecycleState).toBe('draft')
		expect(Number(invoice.grossAmount)).toBe(1500)
		expect(invoice.customerId, 'addressed to the sports club').toBe(clubId)
		expect(invoice.invoiceNumber).toBe(`TV-${subsidyNumber}`)
		const links = (invoice.linkedObjects ?? []) as Array<{ schema?: string, id?: string }>
		expect(links.some((link) => link.schema === 'Subsidie' && link.id === grantId), 'the invoice names the grant').toBe(true)
	})

	test('generate report on a grant of EUR 150,000 stores the report and requires an auditor statement @spec REQ-SUBV-012', async () => {
		const missing = await fx.missingSchema(['Subsidie', 'SubsidieVerantwoording', 'AuditorStatement'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const { id: grantId, subsidyNumber } = await grant('CULTUUR', {
			direction: 'outgoing',
			counterpartyName: `Stichting Cultuurhuis ${UNIQUE_PREFIX}`,
			schemeName: 'Subsidieregeling cultuur 2026',
			requestedAmount: 150000,
			grantedAmount: 150000,
		})

		const res = await post(`/grants/${grantId}/report`, {})
		test.skip(res.status() === 404, `the administrator holds no membership of ${ADMINISTRATION_ID}; set E2E_ADMINISTRATION_ID`)
		expect(res.status(), await res.text()).toBe(201)
		const answer = await res.json() as { verantwoordingId: string, auditorStatementRequired: boolean, auditorStatementId: string | null }
		written.push({ schema: 'SubsidieVerantwoording', id: answer.verantwoordingId })
		if (answer.auditorStatementId) {
			written.push({ schema: 'AuditorStatement', id: answer.auditorStatementId })
		}

		expect(answer.auditorStatementRequired).toBe(true)
		expect(answer.auditorStatementId ?? '', 'the pending auditor statement is stored').not.toBe('')
		const report = await fx.get('SubsidieVerantwoording', answer.verantwoordingId)
		expect(report.grantId).toBe(subsidyNumber)
		expect(report.accountabilityId).toBe(`SV-${subsidyNumber}`)
		expect(Number(report.awardedAmount)).toBe(150000)
		expect(report.status).toBe('draft')
		const statement = await fx.get('AuditorStatement', String(answer.auditorStatementId))
		expect(statement.status).toBe('pending')
		expect(statement.grantId).toBe(subsidyNumber)
	})

	test('the 2026 SiSa appendix reads EUR 120,000 for D8 spending, marks the empty output indicator missing, and exports in BZK column order @spec REQ-SISA-012 @spec REQ-SISA-013', async () => {
		const missing = await fx.missingSchema(['Subsidie', 'SisaIndicator', 'GLTransaction', 'GLLine'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const { id: grantId, subsidyNumber } = await grant('D8', {
			direction: 'incoming',
			counterpartyName: 'Ministerie van BZK',
			schemeName: 'Specifieke uitkering D8 (e2e)',
			requestedAmount: 150000,
			grantedAmount: 150000,
			sisaRegulationCode: 'D8',
		})
		await fx.create('SisaIndicator', {
			administrationId: ADMINISTRATION_ID,
			grantId,
			regulationCode: 'D8',
			indicatorCode: 'D8 / 01',
			description: 'Besteding (jaar T)',
			fiscalYear: Number(SISA_YEAR),
			kind: 'spending',
			unit: 'EUR',
		})
		await fx.create('SisaIndicator', {
			administrationId: ADMINISTRATION_ID,
			grantId,
			regulationCode: 'D8',
			indicatorCode: 'D8 / 03',
			description: 'Aantal deelnemers (jaar T)',
			fiscalYear: Number(SISA_YEAR),
			kind: 'output',
			unit: 'aantal',
		})

		const { id: transactionId } = await fx.create('GLTransaction', {
			administrationId: ADMINISTRATION_ID,
			periodId: '2026-05',
			transactionNumber: `${UNIQUE_PREFIX}-SISA-1`,
			postingDate: '2026-05-15',
			currency: 'EUR',
			state: 'posted',
			description: `${UNIQUE_PREFIX} spending on specific grant D8`,
		})
		const stored = await fx.get('GLTransaction', transactionId)
		test.skip(stored.state !== 'posted', 'the instance keeps a seeded GL transaction in draft; the appendix reads posted lines only')
		await fx.create('GLLine', {
			transactionId,
			administrationId: ADMINISTRATION_ID,
			periodId: '2026-05',
			lineNumber: 1,
			accountNumber: '4400',
			side: 'debit',
			amount: 120000,
			currency: 'EUR',
			subsidieId: grantId,
		})
		await fx.create('GLLine', {
			transactionId,
			administrationId: ADMINISTRATION_ID,
			periodId: '2026-05',
			lineNumber: 2,
			accountNumber: '1100',
			side: 'credit',
			amount: 120000,
			currency: 'EUR',
		})

		const query = new URLSearchParams({ administrationId: ADMINISTRATION_ID, year: SISA_YEAR })
		const res = await api.get(`${PUBLIC_SECTOR_API}/sisa?${query.toString()}`, { headers: HEADERS })
		test.skip(res.status() === 404, `the administrator holds no membership of ${ADMINISTRATION_ID}; set E2E_ADMINISTRATION_ID`)
		expect(res.status(), await res.text()).toBe(200)
		const appendix = await res.json() as { year: number, rows: SisaRow[] }
		expect(appendix.year).toBe(Number(SISA_YEAR))
		const mine = appendix.rows.filter((row) => row.grantId === grantId)
		const spending = mine.find((row) => row.kind === 'spending')
		expect(spending, 'a spending row for the grant').toBeTruthy()
		expect(spending?.regulationCode).toBe('D8')
		expect(spending?.indicatorCode).toBe('D8 / 01')
		expect(Number(spending?.value)).toBe(120000)
		expect(spending?.source).toBe('books')
		expect(spending?.missing).toBe(false)
		const output = mine.find((row) => row.kind === 'output')
		expect(output?.indicatorCode).toBe('D8 / 03')
		expect(output?.value).toBeNull()
		expect(output?.missing, 'an output indicator without a value is marked missing').toBe(true)

		// Export: one row per indicator, in the BZK template's column order.
		query.set('format', 'csv')
		const csv = await api.get(`${PUBLIC_SECTOR_API}/sisa?${query.toString()}`, { headers: HEADERS })
		expect(csv.status()).toBe(200)
		expect(csv.headers()['content-type'] ?? '').toContain('text/csv')
		const lines = (await csv.text()).split(/\r?\n/).filter((line) => line !== '')
		expect(cells(lines[0])).toEqual(BZK_COLUMNS)
		const rows = lines.slice(1).map(cells).filter((row) => row[2] === subsidyNumber)
		expect(rows, 'one row per indicator of the grant').toHaveLength(mine.length)
		for (const row of rows) {
			expect(row).toHaveLength(BZK_COLUMNS.length)
		}
		expect(rows.find((row) => row[3] === 'D8 / 01')).toEqual(['D8', 'Specifieke uitkering D8 (e2e)', subsidyNumber, 'D8 / 01', 'Besteding (jaar T)', '120000.00', 'EUR'])
	})

	test('proposing the 2026-Q3 declaration bundles two expenditures with evidence, leaves the third out, and submitting submits both @spec REQ-EUF-012 @spec REQ-EUF-013 @spec REQ-EUF-007', async () => {
		const missing = await fx.missingSchema(['EuProject', 'EuExpenditure', 'SupportingDocument', 'EuDeclaration', 'IrregularityReport'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const { id: projectId } = await fx.create('EuProject', {
			administrationId: ADMINISTRATION_ID,
			cciNumber: `${UNIQUE_PREFIX}-CCI`,
			fonds: 'EMFAF',
			programmeName: 'EMFAF Nederland 2021-2027',
			beneficiaryOrganization: `Gemeente e2e ${UNIQUE_PREFIX}`,
			managingAuthority: 'RVO',
			euCoFundingRate: 0.6,
			totalEligibleBudget: 500000,
			startDate: '2026-01-01',
			endDate: '2027-12-31',
			projectStatus: 'uitvoering',
			state: 'uitvoering',
		})

		const amounts = [10000, 20000, 5000]
		const expenditureIds: string[] = []
		for (const [index, grossAmount] of amounts.entries()) {
			const { id } = await fx.create('EuExpenditure', {
				administrationId: ADMINISTRATION_ID,
				euProjectId: projectId,
				costCategory: 'indirecte_kosten',
				description: `${UNIQUE_PREFIX} expenditure ${index + 1}`,
				grossAmount,
				vatTreatment: 'non_recoverable',
				declarationPeriod: '2026-Q3',
				eligibilityConfirmed: true,
				procurementRequired: false,
				status: 'gedeclareerd',
				state: 'gedeclareerd',
			})
			expenditureIds.push(id)
		}
		const states = await Promise.all(expenditureIds.map(async (id) => String((await fx.get('EuExpenditure', id)).state ?? '')))
		test.skip(
			states.some((state) => state !== 'gedeclareerd'),
			'the instance keeps a seeded expenditure in its initial posted state; declare needs an active eligibility rule for the fund',
		)

		// Certified evidence for the first two; the third has none.
		for (const id of expenditureIds.slice(0, 2)) {
			const { id: documentId } = await fx.create('SupportingDocument', {
				administrationId: ADMINISTRATION_ID,
				euExpenditureId: id,
				euProjectId: projectId,
				documentType: 'factuur',
				sourceUri: `https://example.org/e2e/${UNIQUE_PREFIX}/${id}.pdf`,
				sha256Hash: A_SHA256,
				state: 'draft',
			})
			const certified = await transition(documentId, 'certify')
			expect(certified.ok, certified.text).toBe(true)
		}

		// Scenario: the project leader proposes the quarterly declaration.
		const proposed = await post(`/eu-projects/${projectId}/declarations`, { period: '2026-Q3' })
		test.skip(proposed.status() === 404, `the administrator holds no membership of ${ADMINISTRATION_ID}; set E2E_ADMINISTRATION_ID`)
		expect(proposed.status(), await proposed.text()).toBe(201)
		const declaration = await proposed.json() as Declaration
		written.push({ schema: 'EuDeclaration', id: declaration.id })
		expect(declaration.state).toBe('draft')
		expect([...declaration.expenditureIds].sort()).toEqual(expenditureIds.slice(0, 2).sort())
		expect(Number(declaration.eligibleTotal)).toBe(30000)
		expect(Number(declaration.euShare)).toBe(18000)
		expect(declaration.leftOut).toHaveLength(1)
		expect(declaration.leftOut[0].expenditureId).toBe(expenditureIds[2])
		expect(declaration.leftOut[0].reason).toContain('evidence is missing')

		// Scenario: the project leader submits it.
		const submitted = await transition(declaration.id, 'submit')
		expect(submitted.ok, submitted.text).toBe(true)
		const stored = await fx.get('EuDeclaration', declaration.id)
		expect(stored.state).toBe('submitted')
		expect(String(stored.submittedAt ?? '')).not.toBe('')
		for (const id of expenditureIds.slice(0, 2)) {
			expect((await fx.get('EuExpenditure', id)).state, `expenditure ${id} is submitted`).toBe('submitted_at_ma')
		}
		expect((await fx.get('EuExpenditure', expenditureIds[2])).state, 'the left-out expenditure stays declared').toBe('gedeclareerd')

		// Scenario: double funding above the threshold blocks the project.
		const irregularity = {
			administrationId: ADMINISTRATION_ID,
			euProjectId: projectId,
			detectionDate: '2026-10-01',
			detectionSource: 'internal_audit',
			nature: 'dubbelfinanciering',
			amountConcerned: 15400,
			state: 'initieel',
		}
		const { id: largeId } = await fx.create('IrregularityReport', irregularity)
		const refused = await transition(largeId, 'escalate')
		expect(refused.ok, 'escalation asks for the external report reference and a recovery amount').toBe(false)
		expect((await fx.get('IrregularityReport', largeId)).state).toBe('initieel')
		const blocked = await post(`/eu-projects/${projectId}/declarations`, { period: '2026-Q4' })
		expect(blocked.status(), 'a new declaration is refused while the irregularity is open').toBe(422)
		expect((await blocked.json()).message).toContain('irregularity')

		await fx.update('IrregularityReport', largeId, { ...irregularity, imsReference: `IMS-${UNIQUE_PREFIX}`, recoveryAmount: 9240 })
		const escalated = await transition(largeId, 'escalate')
		expect(escalated.ok, escalated.text).toBe(true)
		expect((await fx.get('IrregularityReport', largeId)).state).toBe('vervolgcontrole')

		// Scenario: a small irregularity escalates without a reference.
		const { id: smallId } = await fx.create('IrregularityReport', { ...irregularity, nature: 'niet_subsidiabele_kosten', amountConcerned: 2000 })
		const small = await transition(smallId, 'escalate')
		expect(small.ok, small.text).toBe(true)
		const smallStored = await fx.get('IrregularityReport', smallId)
		expect(smallStored.state).toBe('vervolgcontrole')
		expect(smallStored.imsReference ?? '').toBe('')
	})
})
