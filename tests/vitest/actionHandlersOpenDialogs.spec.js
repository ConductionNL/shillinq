/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The named manifest action handlers in src/utils: which dialog each opens,
 * with which props, and when a handler opens nothing because the row or the
 * selection gives it nothing to act on. The meter readings "Rate" action
 * transitions every selected reading and reports what was and was not rated.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn(() => 'dialog') }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))
for (const modal of ['MissedDepreciationDialog', 'BankLineMatchModal', 'UnmatchedClassifyDialog', 'MeterReadingImportModal', 'ProposePaymentRunModal', 'PrepareVatReturnModal']) {
	vi.doMock(`../../src/modals/${modal}.vue`, () => ({ default: { name: modal } }))
}

const { spawnDialog } = await import('@nextcloud/vue/functions/dialog')
const { showError, showSuccess } = await import('@nextcloud/dialogs')
const axios = (await import('@nextcloud/axios')).default
const { openMissedDepreciation } = await import('../../src/utils/assetActions.js')
const { lineIdOfRow, openBankLineMatch, classifyUnmatched } = await import('../../src/utils/bankMatchActions.js')
const { rateMeterReadings, openMeterReadingImport } = await import('../../src/utils/usageBillingActions.js')
const { openProposePaymentRun } = await import('../../src/utils/paymentRunActions.js')
const { openPrepareVatReturn } = await import('../../src/utils/vatReturnActions.js')

/**
 * The name of the dialog component and the props of the nth spawnDialog call.
 *
 * @param {number} n The call index.
 * @return {Array} [name, props].
 */
const opened = (n = 0) => [spawnDialog.mock.calls[n][0].name, spawnDialog.mock.calls[n][1]]

beforeEach(() => {
	vi.clearAllMocks()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('openMissedDepreciation', () => {
	it('opens the dialog for the asset of the row', () => {
		expect(openMissedDepreciation({ item: { id: 'asset-7' } })).toBe('dialog')
		expect(opened()).toEqual(['MissedDepreciationDialog', { open: true, assetId: 'asset-7' }])
	})

	it('falls back on the asset in the address, and opens nothing without one', () => {
		vi.stubGlobal('window', { location: { pathname: '/apps/shillinq/assets/asset-9' } })
		openMissedDepreciation(undefined)
		expect(opened()).toEqual(['MissedDepreciationDialog', { open: true, assetId: 'asset-9' }])

		vi.stubGlobal('window', { location: { pathname: '/apps/shillinq/assets/' } })
		expect(openMissedDepreciation({ item: {} })).toBe(false)
		expect(spawnDialog).toHaveBeenCalledTimes(1)
	})
})

describe('bank match actions', () => {
	it('finds the statement line of a row by its own id fields', () => {
		expect(lineIdOfRow(null)).toBe('')
		expect(lineIdOfRow({ bankStatementLineId: 'l-1', bankLineId: 'l-2' })).toBe('l-1')
		expect(lineIdOfRow({ bankLineId: 42 })).toBe('42')
		expect(lineIdOfRow({ id: 'l-3', statementId: 's-1' })).toBe('l-3')
		expect(lineIdOfRow({ id: 'not-a-line' })).toBe('')
	})

	it('opens the match dialog for a row with a line, and nothing otherwise', () => {
		expect(openBankLineMatch({ item: { bankLineId: 'l-5' } })).toBe('dialog')
		expect(opened()).toEqual(['BankLineMatchModal', { lineId: 'l-5' }])
		expect(openBankLineMatch({ item: { id: 'x' } })).toBeUndefined()
		expect(openBankLineMatch(undefined)).toBeUndefined()
		expect(spawnDialog).toHaveBeenCalledTimes(1)
	})

	it('classifies a selection with the status its action names', () => {
		classifyUnmatched({ actionId: 'bulk-classify-pending', selectedIds: ['a', 'b'] })
		expect(opened()).toEqual(['UnmatchedClassifyDialog', { selectedIds: ['a', 'b'], resolutionStatus: 'pending' }])
	})

	it('classifies nothing for an unknown action or an empty selection', () => {
		expect(classifyUnmatched({ actionId: 'bulk-delete', selectedIds: ['a'] })).toBeUndefined()
		expect(classifyUnmatched({ actionId: 'bulk-classify-timing', selectedIds: [] })).toBeUndefined()
		expect(classifyUnmatched({ actionId: 'bulk-classify-timing', selectedIds: 'a' })).toBeUndefined()
		expect(classifyUnmatched(undefined)).toBeUndefined()
		expect(spawnDialog).not.toHaveBeenCalled()
	})
})

describe('meter reading actions', () => {
	it('rates every selected reading and says how many were and were not rated', async () => {
		axios.post.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('409')).mockResolvedValueOnce({})

		const outcome = await rateMeterReadings({ selectedIds: ['r 1', 'r2', 'r3'] })

		expect(outcome).toEqual({ rated: 2, failed: 1 })
		expect(axios.post).toHaveBeenNthCalledWith(1, '/index.php/apps/openregister/api/objects/r%201/transition', { action: 'rate', data: {} })
		expect(showSuccess).toHaveBeenCalledWith('2 readings rated.')
		expect(showError).toHaveBeenCalledWith('1 readings were not rated: they are already rated, or have no rate plan.')
	})

	it('says nothing it has no news for', async () => {
		axios.post.mockResolvedValue({})
		await rateMeterReadings({ selectedIds: ['r1'] })
		expect(showError).not.toHaveBeenCalled()

		vi.clearAllMocks()
		axios.post.mockRejectedValue(new Error('409'))
		await rateMeterReadings({ selectedIds: ['r1'] })
		expect(showSuccess).not.toHaveBeenCalled()
		expect(showError).toHaveBeenCalledTimes(1)
	})

	it('rates nothing without a selection', async () => {
		expect(await rateMeterReadings({ selectedIds: [] })).toBeUndefined()
		expect(await rateMeterReadings(undefined)).toBeUndefined()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('opens the readings import', () => {
		expect(openMeterReadingImport()).toBe('dialog')
		expect(opened()).toEqual(['MeterReadingImportModal', {}])
	})
})

describe('header actions without input', () => {
	it('opens the payment run proposal and the VAT return preparation', () => {
		openProposePaymentRun()
		openPrepareVatReturn()
		expect([opened(0), opened(1)]).toEqual([['ProposePaymentRunModal', {}], ['PrepareVatReturnModal', {}]])
	})
})
