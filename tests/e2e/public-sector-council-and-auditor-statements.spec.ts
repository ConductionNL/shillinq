/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the lawfulness paragraph, the audit sample and the ENSIA
 * statement (public-sector-council-and-auditor-statements task 4.2).
 *
 * The live pass of the change, driven through the routes and transitions the
 * pages call:
 *
 *   1. a 2026 paragraph over EUR 200,000,000 of posted expenses, a 1 percent
 *      error tolerance and one error finding of EUR 2,500,000 is computed with
 *      the paragraph's `compute` transition: the tolerance reads EUR 2,000,000,
 *      the errors EUR 2,500,000 and the paragraph is not within tolerance
 *      (REQ-RV-011);
 *   2. an audit protocol over 30 posted lines draws a sample of 25 with seed
 *      20261 through `POST /api/bado/controleprotocol/samples`, twice: both
 *      draws hold the same 25 line ids, and the stored AuditSample keeps the
 *      seed and the lines (REQ-011). The scenario's population is 4,000 lines;
 *      30 are seeded here, which proves the same reproducibility in a nightly
 *      that has to clean up after itself;
 *   3. an ENSIA cycle with two questions answered `nee` moves to peer review
 *      (two findings exist), to college approval (the statement downloads
 *      from `GET /api/v1/public-sector/ensia/{id}/statement`) and is submitted
 *      (the XML downloads from `.../xml`) (REQ-ENSIA-011, REQ-ENSIA-012).
 *
 * Each part runs in an administration of its own, named with the run's
 * unique prefix and given an AdministrationMembership for the suite's
 * administrator, so the per-administration guards let the caller in and no
 * other data of the instance enters the sums. Transitions go through
 * OpenRegister's transition route, as the pages' lifecycle actions do. Every
 * object written here, the findings, samples and ledger lines included, is
 * removed afterwards (a posted GLTransaction the instance refuses to delete
 * stays behind in its own, unreachable administration).
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no
 * live instance).
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 * @spec openspec/changes/public-sector-council-and-auditor-statements/tasks.md
 *
 * @e2e bookkeeping-rechtmatigheidsverantwoording::a-controller-computes-the-paragraph
 * @e2e bookkeeping-bado-controleprotocol::an-auditor-draws-a-sample-of-purchase-invoices
 * @e2e bookkeeping-ensia-zelfevaluatie::a-process-owner-moves-the-cycle-forward
 * @e2e bookkeeping-ensia-zelfevaluatie::a-process-owner-downloads-the-xml
 */

import type { APIRequestContext, APIResponse } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { money, OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP_API = '/index.php/apps/shillinq/api'
const OR_API = '/index.php/apps/openregister/api'
const OBJECTS = `${OR_API}/objects/shillinq`
const YEAR = 2026
const SEED = 20261

/** A seeded ledger line: account, side and amount in EUR. */
interface LedgerLine {
	accountNumber: string
	side: 'debit' | 'credit'
	amount: number
}

/** The answer of the draw route (AuditSampleController::draw). */
interface DrawnSample {
	id: string
	sampledLineIds: string[]
	reproducibleSeed: string
	populationSize: number
}

test.describe('public-sector-council-and-auditor-statements: lawfulness, audit sample and ENSIA', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	const written: Array<{ schema: string, id: string }> = []

	/**
	 * The CSRF token shillinq's and OpenRegister's write routes need beside the session cookie.
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
	 * The headers of a write as the administrator.
	 *
	 * @return The headers.
	 */
	async function writeHeaders(): Promise<Record<string, string>> {
		return { requesttoken: await requestToken(), 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
	}

	/**
	 * Run a lifecycle transition on an object, as a page's lifecycle action does.
	 *
	 * @param id     The object id.
	 * @param action The transition.
	 * @return The response.
	 */
	async function transition(id: string, action: string): Promise<APIResponse> {
		return api.post(`${OR_API}/objects/${encodeURIComponent(id)}/transition`, {
			headers: await writeHeaders(),
			data: { action, data: {} },
		})
	}

	/**
	 * The objects of a schema that match the filters.
	 *
	 * @param schema  The schema slug.
	 * @param filters Equality filters.
	 * @return The matching objects.
	 */
	async function list(schema: string, filters: Record<string, string>): Promise<Array<Record<string, unknown>>> {
		const res = await api.get(`${OBJECTS}/${schema}`, { headers: { 'OCS-APIRequest': 'true' }, params: { ...filters, _limit: '200' } })
		expect(res.ok(), `list ${schema}: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		return (body.results ?? []) as Array<Record<string, unknown>>
	}

	/**
	 * The id of an object as OpenRegister answers it.
	 *
	 * @param object The object.
	 * @return Its id.
	 */
	function idOf(object: Record<string, unknown>): string {
		const self = object['@self'] as Record<string, unknown> | undefined
		return String(self?.id ?? object.id ?? '')
	}

	/**
	 * An administration of this run with the administrator as a member.
	 *
	 * @param suffix Makes the administration id unique within this run.
	 * @return The administration id.
	 */
	async function administration(suffix: string): Promise<string> {
		const administrationId = `${UNIQUE_PREFIX}-${suffix}`
		await fx.create('AdministrationMembership', {
			userId: 'admin',
			administrationId,
			role: 'controller',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		return administrationId
	}

	/**
	 * A posted transaction with its lines.
	 *
	 * @param administrationId The administration.
	 * @param number           Makes the transaction number unique within this run.
	 * @param day              The posting date (YYYY-MM-DD).
	 * @param lines            The lines.
	 * @return The transaction's state as stored and the ids of its lines.
	 */
	async function posted(administrationId: string, number: string, day: string, lines: LedgerLine[]): Promise<{ state: string, lineIds: string[] }> {
		const periodId = `${administrationId}-${day.slice(0, 7)}`
		const { id: transactionId } = await fx.create('GLTransaction', {
			administrationId,
			periodId,
			transactionNumber: `${UNIQUE_PREFIX}-${number}`,
			postingDate: day,
			currency: 'EUR',
			state: 'posted',
			description: `${UNIQUE_PREFIX} ${number}`,
		})
		const lineIds: string[] = []
		for (const [index, line] of lines.entries()) {
			const { id } = await fx.create('GLLine', {
				transactionId,
				periodId,
				lineNumber: index + 1,
				accountNumber: line.accountNumber,
				side: line.side,
				amount: line.amount,
				currency: 'EUR',
			})
			lineIds.push(id)
		}
		const stored = await fx.get('GLTransaction', transactionId)
		return { state: String(stored.state ?? ''), lineIds }
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

	test('the 2026 paragraph with one error finding exceeds the tolerance @spec REQ-RV-011', async () => {
		const missing = await fx.missingSchema(['AdministrationMembership', 'Account', 'GLTransaction', 'GLLine', 'Tolerantiegrens', 'Rechtmatigheidsbevinding', 'Rechtmatigheidsparagraaf'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const administrationId = await administration('rv')
		await fx.create('Account', {
			administrationId,
			accountNumber: '4000',
			name: 'Lasten',
			accountType: 'expenses',
			currency: 'EUR',
			lifecycleState: 'active',
		})
		const ledger = await posted(administrationId, 'RV-1', `${YEAR}-06-30`, [
			{ accountNumber: '4000', side: 'debit', amount: 200_000_000 },
			{ accountNumber: '1100', side: 'credit', amount: 200_000_000 },
		])
		test.skip(ledger.state !== 'posted', 'the instance keeps a seeded GLTransaction out of `posted`; the paragraph sums posted lines only')

		await fx.create('Tolerantiegrens', {
			administrationId,
			financialYear: YEAR,
			error_percentage: 1,
			uncertainty_percentage: 3,
			calculationBasis: 'total_expenses_including_reserve_movements',
			status: 'determined',
		})
		await fx.create('Rechtmatigheidsbevinding', {
			administrationId,
			findingNumber: `${UNIQUE_PREFIX}-RV-F1`,
			kind: 'error',
			criterium: 'europees_aanbesteden',
			amount_error: 2_500_000,
			amount_uncertainty: 0,
			financialYear: YEAR,
			description: 'Contract awarded without a European tender',
			status: 'open',
		})
		const { id: paragraphId } = await fx.create('Rechtmatigheidsparagraaf', {
			administrationId,
			financialYear: YEAR,
			status: 'draft',
		})

		const res = await transition(paragraphId, 'compute')
		expect(res.ok(), `compute: HTTP ${res.status()} ${await res.text()}`).toBeTruthy()

		const paragraph = await fx.get('Rechtmatigheidsparagraaf', paragraphId)
		expect(money(Number(paragraph.total_expenses_including_reserve_movements))).toBe(200_000_000)
		expect(money(Number(paragraph.tolerance_error_amount)), 'the error tolerance is 1 percent of the expenses').toBe(2_000_000)
		expect(money(Number(paragraph.total_identified_errors))).toBe(2_500_000)
		expect(paragraph.within_tolerance, 'the errors exceed the tolerance').toBe(false)
		expect(paragraph.status, 'compute keeps the paragraph in draft').toBe('draft')
	})

	test('an auditor draws a sample of 25 twice with seed 20261 and gets the same lines @spec REQ-011', async () => {
		const missing = await fx.missingSchema(['AdministrationMembership', 'GLTransaction', 'GLLine', 'Controleprotocol', 'AuditSample'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const administrationId = await administration('bado')
		const lines: LedgerLine[] = []
		for (let i = 0; i < 15; i++) {
			lines.push({ accountNumber: '4400', side: 'debit', amount: 100 + i })
			lines.push({ accountNumber: '1600', side: 'credit', amount: 100 + i })
		}
		const ledger = await posted(administrationId, 'BADO-1', `${YEAR}-03-31`, lines)
		test.skip(ledger.state !== 'posted', 'the instance keeps a seeded GLTransaction out of `posted`; the population is the posted lines only')

		const { id: protocolId } = await fx.create('Controleprotocol', {
			organisationId: administrationId,
			organisationType: 'municipality',
			auditYear: YEAR,
			version: `${UNIQUE_PREFIX}-1`,
			status: 'draft',
			materialityBase: 'expenses',
			effectiveFrom: `${YEAR}-01-01`,
			effectiveTo: `${YEAR}-12-31`,
		})

		/**
		 * Draw 25 lines with the seed.
		 *
		 * @return The stored sample.
		 */
		async function draw(): Promise<DrawnSample> {
			const res = await api.post(`${APP_API}/bado/controleprotocol/samples`, {
				headers: await writeHeaders(),
				data: { protocol_id: protocolId, size: 25, seed: SEED },
			})
			test.skip(res.status() === 404, `the administrator's membership of ${administrationId} does not reach the protocol guard`)
			expect(res.status(), await res.text()).toBe(201)
			const sample = (await res.json()) as DrawnSample
			written.push({ schema: 'AuditSample', id: String(sample.id) })
			return sample
		}

		const first = await draw()
		expect(first.populationSize, 'the population is the 30 posted lines').toBe(30)
		expect(first.sampledLineIds).toHaveLength(25)
		expect(new Set(first.sampledLineIds).size, 'no line is drawn twice').toBe(25)
		const population = new Set(ledger.lineIds)
		expect(first.sampledLineIds.every((id) => population.has(String(id))), 'every drawn line is a posted line of the protocol').toBe(true)
		expect(first.reproducibleSeed).toBe(String(SEED))

		const stored = await fx.get('AuditSample', first.id)
		expect(String(stored.reproducibleSeed)).toBe(String(SEED))
		expect(stored.sampledLineIds).toEqual(first.sampledLineIds)
		expect(Number(stored.sampleSize)).toBe(25)

		const second = await draw()
		expect(second.id, 'the second draw is stored as a sample of its own').not.toBe(first.id)
		expect(second.sampledLineIds, 'the same seed draws the same 25 lines').toEqual(first.sampledLineIds)
	})

	test('an ENSIA cycle reaches college approval with its findings and statement, and its XML downloads @spec REQ-ENSIA-011 @spec REQ-ENSIA-012', async () => {
		const missing = await fx.missingSchema(['AdministrationMembership', 'ENSIAJaarcyclus', 'Evaluatievraag', 'Bevinding'])
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		const administrationId = await administration('ensia')
		const { id: cycleId } = await fx.create('ENSIAJaarcyclus', {
			administrationId,
			year: YEAR,
			organisation: { kvk: '12345678', name: `Gemeente ${UNIQUE_PREFIX}` },
			status: 'in-voorbereiding',
			processOwner: 'admin',
			questionSetVersion: `${YEAR}.1`,
		})
		const answers: Array<[string, string]> = [['BIO-01', 'nee'], ['BIO-02', 'nee'], ['BIO-03', 'ja']]
		const questionIds: Record<string, string> = {}
		for (const [code, answer] of answers) {
			const { id } = await fx.create('Evaluatievraag', {
				cyclusId: cycleId,
				administrationId,
				domain: 'BIO',
				questionCode: `${UNIQUE_PREFIX}-${code}`,
				questionText: `Question ${code}`,
				answerType: 'ja-nee-nvt',
				answer,
			})
			questionIds[code] = id
		}

		const start = await transition(cycleId, 'startUitvoering')
		test.skip(start.status() === 403, 'the administrator holds no proces-eigenaar role on this instance; the ENSIA transitions are role-gated')
		expect(start.ok(), `startUitvoering: HTTP ${start.status()} ${await start.text()}`).toBeTruthy()

		// Peer review: one finding per question answered nee.
		const review = await transition(cycleId, 'naarPeerReview')
		expect(review.ok(), `naarPeerReview: HTTP ${review.status()} ${await review.text()}`).toBeTruthy()
		const mine = (await list('Bevinding', { cyclusId: cycleId })).filter((finding) => finding.cyclusId === cycleId)
		for (const finding of mine) {
			written.push({ schema: 'Bevinding', id: idOf(finding) })
		}
		expect(mine, 'two findings exist for the cycle').toHaveLength(2)
		expect(mine.map((finding) => String(finding.questionId)).sort()).toEqual([questionIds['BIO-01'], questionIds['BIO-02']].sort())
		expect(mine.every((finding) => finding.status === 'open' && finding.type === 'tekortkoming')).toBe(true)

		// College approval stores the statement file.
		const approval = await transition(cycleId, 'naarCollegeAkkoord')
		expect(approval.ok(), `naarCollegeAkkoord: HTTP ${approval.status()} ${await approval.text()}`).toBeTruthy()
		const approved = await fx.get('ENSIAJaarcyclus', cycleId)
		expect(approved.status).toBe('college-akkoord')
		expect(String(approved.declarationFile ?? ''), 'the statement file is named on the cycle').toMatch(/\.docx$/)

		const statement = await api.get(`${APP_API}/v1/public-sector/ensia/${encodeURIComponent(cycleId)}/statement`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(statement.status(), 'the college statement downloads').toBe(200)
		expect(statement.headers()['content-type'] ?? '').toContain('wordprocessingml')
		expect(statement.headers()['content-disposition'] ?? '').toContain(`college-verklaring-ensia-${administrationId}-${YEAR}.docx`)
		expect((await statement.body()).subarray(0, 2).toString('latin1'), 'a DOCX is a zip').toBe('PK')

		// No XML before submission; then submit and download it.
		const early = await api.get(`${APP_API}/v1/public-sector/ensia/${encodeURIComponent(cycleId)}/xml`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(early.status(), 'an approved, unsubmitted cycle has no XML yet').toBe(409)

		const submit = await transition(cycleId, 'indienen')
		expect(submit.ok(), `indienen: HTTP ${submit.status()} ${await submit.text()}`).toBeTruthy()
		const submitted = await fx.get('ENSIAJaarcyclus', cycleId)
		expect(submitted.status).toBe('submitted')

		const xml = await api.get(`${APP_API}/v1/public-sector/ensia/${encodeURIComponent(cycleId)}/xml`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(xml.status(), 'the submission XML downloads').toBe(200)
		expect(xml.headers()['content-type'] ?? '').toContain('xml')
		expect(xml.headers()['content-disposition'] ?? '').toContain(`ensia-${administrationId}-${YEAR}.xml`)
		const text = await xml.text()
		expect(text).toContain('ensiaZelfevaluatie')
		expect(text).toContain(`<year>${YEAR}</year>`)
		expect(text).toContain(`code="${UNIQUE_PREFIX}-BIO-01"`)
	})
})
