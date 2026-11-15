/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * loadGuidanceRows: the requests it makes for a journal entry and for a ledger
 * transaction, the line order and the one account kept per number.
 *
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { computeKpis } from '../../src/components/dashboard/financial/financialSeries.js'
import { guidanceRows, loadGuidanceRows } from '../../src/utils/accountGuidance.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

const accountsBy = {
	1000: [{ accountNumber: '1000', name: 'Kas', guidance: 'cash' }, { accountNumber: '1000', name: 'second' }],
	8000: [{ accountNumber: '8000', name: 'Omzet' }],
}

/**
 * Answer the GETs: the object, its GL lines and the accounts.
 *
 * @param {object} object The object to return.
 * @param {Array<object>} glLines The GL lines to return.
 */
function answer(object, glLines = []) {
	axios.get.mockImplementation(async (url) => {
		if (url.includes('/GLLine?')) {
			return { data: { results: glLines } }
		}
		if (url.includes('/Account?')) {
			const number = new URL(url, 'http://x').searchParams.get('accountNumber')
			return { data: accountsBy[number] || [] }
		}
		return { data: object }
	})
}

describe('loadGuidanceRows', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('reads a journal entry\'s own lines and one account per distinct number', async () => {
		const lines = [
			{ accountNumber: '1000', side: 'debit', amount: 10 },
			{ accountNumber: 1000, side: 'credit', amount: 4 },
			{ accountNumber: '8000', side: 'credit', amount: 6 },
			{ side: 'debit', amount: 0 },
		]
		answer({ administrationId: 'adm-1', lines })

		const rows = await loadGuidanceRows('JournalEntry', 'je/1')

		expect(axios.get.mock.calls.map(([url]) => url)).toEqual([
			'/index.php/apps/openregister/api/objects/shillinq/JournalEntry/je%2F1',
			'/index.php/apps/openregister/api/objects/shillinq/Account?administrationId=adm-1&accountNumber=1000&_limit=200',
			'/index.php/apps/openregister/api/objects/shillinq/Account?administrationId=adm-1&accountNumber=8000&_limit=200',
		])
		expect(rows).toEqual(guidanceRows(lines, [accountsBy[1000][0], accountsBy[8000][0]]))
	})

	it('reads a ledger transaction\'s GL lines in line order', async () => {
		answer({ administrationId: '', lines: [{ accountNumber: '9999' }] }, [
			{ lineNumber: 2, accountNumber: '8000' },
			{ accountNumber: '1000' },
			{ lineNumber: 1, accountNumber: '1000' },
		])

		const rows = await loadGuidanceRows('GLTransaction', 7)

		expect(axios.get.mock.calls.map(([url]) => url)).toEqual([
			'/index.php/apps/openregister/api/objects/shillinq/GLTransaction/7',
			'/index.php/apps/openregister/api/objects/shillinq/GLLine?transactionId=7&_limit=200',
			'/index.php/apps/openregister/api/objects/shillinq/Account?accountNumber=1000&_limit=200',
			'/index.php/apps/openregister/api/objects/shillinq/Account?accountNumber=8000&_limit=200',
		])
		expect(rows).toEqual(guidanceRows(
			[{ accountNumber: '1000' }, { lineNumber: 1, accountNumber: '1000' }, { lineNumber: 2, accountNumber: '8000' }],
			[accountsBy[1000][0], accountsBy[8000][0]],
		))
	})

	it('has no rows for an object without lines', async () => {
		answer(null)
		expect(await loadGuidanceRows('JournalEntry', 'x')).toEqual([])
		expect(axios.get).toHaveBeenCalledTimes(1)
	})
})

describe('computeKpis key order', () => {
	it('lists the KPI-strip metrics in their order', () => {
		expect(Object.keys(computeKpis({}, new Date(2026, 2, 3, 12)))).toEqual([
			'turnoverYtd', 'marginYtd', 'marginPctYtd', 'openArAmount', 'openArCount',
			'openApAmount', 'openApCount', 'billableHours', 'billablePct', 'cashPosition',
		])
	})
})
