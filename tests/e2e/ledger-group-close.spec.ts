/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the group close, from the intercompany elimination in the
 * soft close to the consolidated figures (ledger-group-close task 1.4,
 * REQ-ICE-011 and REQ-CONS-011).
 *
 * The live pass of the change, driven through the routes and pages it wired:
 *
 *   1. a parent and a subsidiary, each with a balanced opening booking in
 *      period 2026-10: the parent EUR 488,000 cash and EUR 12,000 intercompany
 *      receivable (account 1300) against EUR 500,000 equity, the subsidiary
 *      EUR 200,000 cash against EUR 12,000 intercompany payable (account 1600)
 *      and EUR 188,000 equity;
 *   2. an intercompany relation between them with the EUR 12,000 sale booked on
 *      both sides, matched both ways;
 *   3. POST /api/v2/soft-close/{parent}/execute-now for 2026-10 runs the
 *      intercompany matching (SoftCloseExecutor -> matchRelationPeriod) and
 *      writes one EliminationJournal of EUR 12,000 for the pair, which the
 *      Eliminations page (/consolidation/eliminations) shows;
 *   4. a consolidation period 2026-10 of the group, moved to its elimination
 *      phase and run (`runConsolidation`), stores a draft ConsolidatedBalance
 *      with EUR 688,000 total assets (500,000 + 200,000 - 12,000), no
 *      liabilities left and EUR 688,000 equity, shown on the consolidated
 *      report page.
 *
 * The task names two seed administrations. Summing the seed's own October
 * books would make every total depend on what else the instance holds, so the
 * test makes two administrations of its own (unique ids, with a membership for
 * the suite's administrator, as the trial-balance workflow does) and books only
 * what the scenarios name. The GL transactions are drafts: the trial balance
 * the consolidation reads does not look at the state, and a draft can be
 * deleted again in afterAll where a posted, sealed one cannot.
 *
 * The swing check (REQ-CLS-011) runs from the nightly SoftCloseJob only, after
 * a completed soft close; the on-demand route does not run it, so it is a
 * test.fixme below.
 *
 * Every object written here, by the test or by the soft close and the
 * consolidation run on its behalf, is removed afterwards.
 *
 * Written 24 Nov 2026, runs in the nightly; not run in the build sandbox (no live instance).
 *
 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-intercompany-elimination/spec.md
 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-consolidation-commercial/spec.md
 * @spec openspec/changes/ledger-group-close/specs/bookkeeping-continuous-close/spec.md
 * @spec openspec/changes/ledger-group-close/tasks.md
 *
 * @e2e bookkeeping-intercompany-elimination::matched-intercompany-sales-are-eliminated-overnight
 * @e2e bookkeeping-consolidation-commercial::a-group-of-two-administrations-is-consolidated
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, request as pwRequest, test } from '@playwright/test'
import { money, OrFixtures, UNIQUE_PREFIX } from './workflows/_fixtures.ts'

const APP = '/index.php/apps/shillinq'
const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const PARENT = `${UNIQUE_PREFIX}-parent`
const SUB = `${UNIQUE_PREFIX}-sub`
const RELATION_ID = `${UNIQUE_PREFIX}-ic-sale`
const PERIOD = '2026-10'
const NEEDED = [
	'AdministrationMembership', 'Account', 'GLTransaction', 'GLLine',
	'IntercompanyRelation', 'IntercompanyTransaction', 'IntercompanyMatch', 'EliminationJournal',
	'ConsolidationGroup', 'GroupEntity', 'ConsolidationPeriod', 'ConsolidatedBalance', 'ConsolidatedIncomeStatement', 'PeriodStatus',
]

/** One line of a booking: account, side and amount in EUR. */
type BookingLine = [string, 'debit' | 'credit', number]

test.describe('ledger-group-close: elimination in the soft close and the consolidation run', () => {
	let api: APIRequestContext
	let fx: OrFixtures
	let token = ''
	/** Objects the server wrote on the test's behalf, removed in afterAll. */
	const written: Array<{ schema: string, id: string }> = []

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
	 * The objects of a schema whose fields equal the filters.
	 *
	 * @param schema  The schema slug.
	 * @param filters Field filters.
	 * @return The objects.
	 */
	async function list(schema: string, filters: Record<string, string>): Promise<Array<Record<string, unknown>>> {
		const query = new URLSearchParams({ ...filters, _limit: '50' })
		const res = await api.get(`${OBJECTS}/${schema}?${query.toString()}`, { headers: { 'OCS-APIRequest': 'true' } })
		expect(res.ok(), `list ${schema}: HTTP ${res.status()}`).toBeTruthy()
		const body = await res.json()
		const rows = (body.results ?? body.items ?? []) as Array<Record<string, unknown>>
		// Keep only exact matches, whatever the list route does with an unknown filter.
		return rows.filter((row) => Object.entries(filters).every(([field, value]) => String(row[field] ?? '') === value))
	}

	/**
	 * The id OpenRegister answers an object under.
	 *
	 * @param row The object.
	 * @return Its id.
	 */
	function idOf(row: Record<string, unknown>): string {
		return String((row['@self'] as Record<string, unknown> | undefined)?.id ?? row.id ?? '')
	}

	/**
	 * Remember server-written objects for afterAll.
	 *
	 * @param schema The schema slug.
	 * @param rows   The objects.
	 */
	function track(schema: string, rows: Array<Record<string, unknown>>): void {
		for (const row of rows) {
			const id = idOf(row)
			if (id !== '' && !written.some((entry) => entry.schema === schema && entry.id === id)) {
				written.push({ schema, id })
			}
		}
	}

	/**
	 * Fire a lifecycle transition when the object is not yet in the wanted state.
	 *
	 * @param schema The schema slug.
	 * @param id     The object id.
	 * @param field  The lifecycle field.
	 * @param want   The wanted state.
	 * @param action The transition that reaches it.
	 */
	async function reach(schema: string, id: string, field: string, want: string, action: string): Promise<void> {
		const stored = await fx.get(schema, id)
		if (String(stored[field] ?? '') === want) {
			return
		}
		const res = await fx.transition(id, action)
		expect(res.ok(), `${schema} ${action}: HTTP ${res.status()} ${await res.text()}`).toBeTruthy()
		expect(String((await fx.get(schema, id))[field] ?? '')).toBe(want)
	}

	/**
	 * An administration of the test: the administrator's membership and its chart.
	 *
	 * @param administrationId The administration.
	 * @param accounts         Account number and type pairs.
	 */
	async function administration(administrationId: string, accounts: Array<[string, string]>): Promise<void> {
		await fx.create('AdministrationMembership', {
			userId: ADMIN_USER,
			administrationId,
			role: 'controller',
			mayPostJournalEntries: true,
			validFrom: '2024-01-01',
			validUntil: null,
		})
		for (const [accountNumber, accountType] of accounts) {
			await fx.create('Account', {
				administrationId,
				accountNumber,
				name: `${accountType} ${accountNumber} ${UNIQUE_PREFIX}`,
				accountType,
				currency: 'EUR',
				status: 'active',
				lifecycleState: 'active',
			})
		}
	}

	/**
	 * A booking of the administration in period 2026-10.
	 *
	 * @param administrationId The administration.
	 * @param lines            The lines.
	 */
	async function booking(administrationId: string, lines: BookingLine[]): Promise<void> {
		const { id: transactionId } = await fx.create('GLTransaction', {
			transactionNumber: `${UNIQUE_PREFIX}-${administrationId.slice(-6)}`,
			postingDate: `${PERIOD}-31`,
			periodId: PERIOD,
			currency: 'EUR',
			description: `${UNIQUE_PREFIX} opening ${administrationId}`,
			state: 'draft',
			administrationId,
		})
		let lineNumber = 0
		for (const [accountNumber, side, amount] of lines) {
			lineNumber++
			await fx.create('GLLine', { transactionId, periodId: PERIOD, lineNumber, accountNumber, side, amount, currency: 'EUR' })
		}
	}

	/**
	 * One side of the intercompany sale, as the administration booked it.
	 *
	 * @param source    The administration that booked it.
	 * @param target    The other administration.
	 * @param groupId   The consolidation group.
	 * @param glAccount The account it was booked on.
	 */
	async function intercompanySide(source: string, target: string, groupId: string, glAccount: string): Promise<void> {
		await fx.create('IntercompanyTransaction', {
			relationId: RELATION_ID,
			sourceAdministrationId: source,
			sourceJournalEntryId: `${UNIQUE_PREFIX}-je-${source.slice(-6)}`,
			sourceLineNumber: 1,
			bookingDate: `${PERIOD}-15`,
			glAccount,
			debitAmount: 12000,
			creditAmount: 0,
			currency: 'EUR',
			detectionMethod: 'explicitly-marked',
			detectionConfidence: 'high',
			administrationId: source,
			consolidationGroupId: groupId,
			fromMemberId: source,
			toMemberId: target,
			transactionDate: `${PERIOD}-15`,
			amount: 12000,
			accountFrom: glAccount,
			accountTo: glAccount,
			eliminationStatus: 'pending',
			movementType: 'other',
			fromAdministrationId: source,
			toAdministrationId: target,
			postingDate: `${PERIOD}-15`,
			state: 'draft',
		})
	}

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({ baseURL, storageState: 'tests/e2e/.auth/admin.json' })
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		for (const { schema, id } of [...written].reverse()) {
			await fx?.remove(schema, id)
		}
		await fx?.cleanup()
		await api?.dispose()
	})

	test('the soft close eliminates the EUR 12,000 sale and the consolidation shows EUR 688,000 total assets @spec REQ-ICE-011 @spec REQ-CONS-011', async ({ page }) => {
		const missing = await fx.missingSchema(NEEDED)
		test.skip(missing !== null, `the shillinq register lacks ${missing}`)

		// 1. The two administrations and their October books.
		await administration(PARENT, [['0500', 'equity'], ['1000', 'assets'], ['1300', 'assets']])
		await administration(SUB, [['0500', 'equity'], ['1000', 'assets'], ['1600', 'liabilities']])
		await booking(PARENT, [['1000', 'debit', 488000], ['1300', 'debit', 12000], ['0500', 'credit', 500000]])
		await booking(SUB, [['1000', 'debit', 200000], ['1600', 'credit', 12000], ['0500', 'credit', 188000]])

		// The group: the parent and the subsidiary in full.
		const { id: groupId } = await fx.create('ConsolidationGroup', {
			groupName: `Groep ${UNIQUE_PREFIX}`,
			name: `Groep ${UNIQUE_PREFIX}`,
			parentAdministrationId: PARENT,
			reportingFramework: 'RJ217',
			fiscalYearEnd: '2026-12-31',
			defaultConsolidationMethod: 'integral',
			consolidationMethod: 'full',
			firstConsolidationDate: '2026-01-01',
			administrationIds: [PARENT, SUB],
			state: 'active',
			status: 'active',
		})
		const { id: memberId } = await fx.create('GroupEntity', {
			consolidationGroupId: groupId,
			administrationId: SUB,
			entityType: 'subsidiary',
			ownershipPercentage: 100,
			consolidationMethod: 'integral',
			firstConsolidationDate: '2026-01-01',
			state: 'active',
		})
		await reach('GroupEntity', memberId, 'state', 'active', 'activate')

		// 2. The intercompany relation and the sale on both sides. Eliminating it
		// debits the subsidiary's payable (A) and credits the parent's receivable (B).
		await fx.create('IntercompanyRelation', {
			relationId: RELATION_ID,
			groupId,
			entityAId: PARENT,
			entityBId: SUB,
			relationType: 'sales-of-goods',
			defaultAccountA: '1600',
			defaultAccountB: '1300',
			toleranceAbsolute: 10,
			toleranceRelative: 0.5,
			activeFrom: '2026-01-01',
			administrationId: PARENT,
			consolidationGroupId: groupId,
			debtorEntityId: SUB,
			creditorEntityId: PARENT,
			transactionType: 'sales',
		})
		await intercompanySide(PARENT, SUB, groupId, '1300')
		await intercompanySide(SUB, PARENT, groupId, '1600')

		// 3. The soft close of the parent for 2026-10 writes the elimination.
		const closed = await api.post(`${APP}/api/v2/soft-close/${encodeURIComponent(PARENT)}/execute-now`, {
			headers: { requesttoken: await requestToken(), 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
			data: { periodId: PERIOD },
		})
		const closeText = await closed.text()
		track('PeriodStatus', await list('PeriodStatus', { administrationId: PARENT }))
		track('IntercompanyMatch', await list('IntercompanyMatch', { relationId: RELATION_ID }))
		const journals = await list('EliminationJournal', { eliminationId: `elim-match-${RELATION_ID}-${PERIOD}` })
		track('EliminationJournal', journals)
		expect(closed.status(), closeText).toBe(200)
		const report = JSON.parse(closeText).data
		expect(report.status).toBe('completed')
		expect(report.intercompanyMatches, 'the one relation of the parent is matched').toBe(1)

		const [match] = await list('IntercompanyMatch', { relationId: RELATION_ID, periodId: PERIOD })
		expect(match?.matchStatus).toBe('perfect-match')
		expect(journals, 'one elimination entry for the pair').toHaveLength(1)
		const journal = journals[0]
		expect(money(Number(journal.totalDebit))).toBe(12000)
		expect(money(Number(journal.totalCredit))).toBe(12000)
		expect(journal.consolidationPeriodId).toBe(PERIOD)
		expect(journal.administrationId).toBe(PARENT)
		expect(journal.description).toBe(`IC elimination ${RELATION_ID}`)

		await page.goto(`${APP}/consolidation/eliminations`)
		await expect(page.getByRole('row').filter({ hasText: `IC elimination ${RELATION_ID}` }).first()).toBeVisible({ timeout: 20_000 })

		// 4. The consolidation run for 2026-10.
		const { id: periodId } = await fx.create('ConsolidationPeriod', {
			consolidationGroupId: groupId,
			periodStart: `${PERIOD}-01`,
			periodEnd: `${PERIOD}-31`,
			executor: ADMIN_USER,
			status: 'open',
		})
		await reach('ConsolidationPeriod', periodId, 'status', 'eliminationPhase', 'startElimination')
		const run = await fx.transition(periodId, 'runConsolidation')
		const runText = await run.text()
		const balances = await list('ConsolidatedBalance', { consolidationPeriodId: periodId })
		track('ConsolidatedBalance', balances)
		track('ConsolidatedIncomeStatement', await list('ConsolidatedIncomeStatement', { consolidationPeriodId: periodId }))
		expect(run.ok(), `runConsolidation: HTTP ${run.status()} ${runText}`).toBeTruthy()

		expect(balances, 'one consolidated balance for the period').toHaveLength(1)
		const balance = balances[0]
		expect(balance.state).toBe('draft')
		expect(balance.reportDate).toBe(`${PERIOD}-31`)
		expect(money(Number(balance.totalAssets))).toBe(688000)
		expect(money(Number(balance.totalLiabilities))).toBe(0)
		expect(money(Number(balance.totalEquity))).toBe(688000)
		const lines = (balance.lines ?? []) as Array<Record<string, unknown>>
		const receivable = lines.find((line) => line.rapportageregelnummer === '1300')
		expect(receivable, 'the receivable has a report line').toBeTruthy()
		expect(money(Number(receivable?.preEliminationTotal))).toBe(12000)
		expect(money(Number(receivable?.postEliminationTotal))).toBe(0)

		await page.goto(`${APP}/consolidation/reports/${idOf(balance)}`)
		await expect(page.getByText(/688[.,\s\u00a0]?000/).first()).toBeVisible({ timeout: 20_000 })
	})

	// SoftCloseJob runs SoftCloseSwingCheck (FluxService) after a completed
	// nightly soft close; POST /api/v2/soft-close/{id}/execute-now does not, and
	// the job is reachable only through Nextcloud's cron (or `occ background-job:execute`).
	test.fixme('after the nightly soft close the flux analysis page lists account 4000 as a swing to explain @spec REQ-CLS-011', async () => {})
})
