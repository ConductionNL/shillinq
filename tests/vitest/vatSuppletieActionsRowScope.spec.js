/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The edges of the VAT correction page actions (tax-vat-position-and-suppletie):
 * where the record id comes from, the actions without an id, the fallback
 * refusals, a prepared correction under the threshold, a failed filing that
 * is not a missing Digipoort, and a lines action with nothing to show.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	post: vi.fn(),
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
	emit: vi.fn(),
	spawnDialog: vi.fn(() => 'dialog'),
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (m, k) => String(params[k])),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => String(vars[k])),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: mocks.showError, showSuccess: mocks.showSuccess, showWarning: mocks.showWarning }))
vi.mock('@nextcloud/event-bus', () => ({ emit: mocks.emit }))
vi.mock('@nextcloud/axios', () => ({ default: { post: mocks.post } }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: mocks.spawnDialog }))
vi.mock('../../src/modals/VatPositionLinesModal.vue', () => ({ default: { name: 'VatPositionLinesModal' } }))

const actions = await import('../../src/utils/vatSuppletieActions.js')

let assign

/**
 * Point the page at a path.
 *
 * @param {string} pathname The path.
 */
function at(pathname) {
	assign = vi.fn()
	vi.stubGlobal('window', { location: { pathname, assign } })
}

beforeEach(() => {
	Object.values(mocks).forEach((mock) => mock.mockReset())
	mocks.spawnDialog.mockReturnValue('dialog')
	at('/apps/shillinq/tax/vat-corrections/corr-url')
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('recordId', () => {
	it('takes the row, then the page object, then the URL', () => {
		expect(actions.recordId({ item: { id: 'row-1' }, object: { id: 'obj-1' } })).toBe('row-1')
		expect(actions.recordId({ object: { id: 'obj-1' } })).toBe('obj-1')
		expect(actions.recordId(undefined)).toBe('corr-url')
		at('/')
		expect(actions.recordId({})).toBe('')
	})
})

describe('without a record id', () => {
	beforeEach(() => at('/'))

	it('asks the server nothing and says nothing', async () => {
		expect(await actions.checkVatReturnForCorrections({})).toBeNull()
		expect(await actions.prepareSupplementaryReturn({})).toBeNull()
		expect(actions.downloadSupplementaryReturn({})).toBe('')
		expect(await actions.fileSupplementaryReturn({})).toBe('failed')
		expect(mocks.post).not.toHaveBeenCalled()
		expect(assign).not.toHaveBeenCalled()
		expect(mocks.showError).not.toHaveBeenCalled()
	})
})

describe('preparing and filing', () => {
	it('says a correction under the threshold goes into the next return', async () => {
		mocks.post.mockResolvedValueOnce({ data: { data: { thresholdExceeded: false } } })

		expect(await actions.prepareSupplementaryReturn({ item: { id: 'corr-1' } })).toEqual({ thresholdExceeded: false })
		expect(mocks.post).toHaveBeenCalledWith('/apps/shillinq/api/vat-corrections/corr-1/prepare')
		expect(mocks.showSuccess).toHaveBeenCalledWith('The correction is EUR 1,000 or less and can go into the next return. The correction posting is staged.')
		expect(mocks.emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})

	it('answers null when the server sends no correction back', async () => {
		mocks.post.mockResolvedValueOnce({ data: {} })

		expect(await actions.prepareSupplementaryReturn({ item: { id: 'corr-1' } })).toBeNull()
	})

	it('names no date when an exceeded threshold carries none', () => {
		expect(actions.preparedMessage({ thresholdExceeded: true })).toBe('The correction is above EUR 1,000: file the supplementary return before . The correction posting is staged.')
	})

	it.each([
		['prepare', () => actions.prepareSupplementaryReturn({ item: { id: 'c' } }), null, 'The supplementary return could not be prepared.'],
		['check', () => actions.checkVatReturnForCorrections({ item: { id: 'r' } }), null, 'The return could not be checked for corrections.'],
		['file', () => actions.fileSupplementaryReturn({ item: { id: 'c' } }), 'failed', 'The supplementary return could not be filed.'],
	])('falls back to its own words when %s fails without a reason', async (name, run, result, text) => {
		mocks.post.mockRejectedValueOnce(new Error('Network Error'))

		expect(await run()).toBe(result)
		expect(mocks.showError).toHaveBeenCalledWith(text)
		expect(mocks.emit).not.toHaveBeenCalled()
	})

	it('does not download on a conflict that offers no download', async () => {
		mocks.post.mockRejectedValueOnce({ response: { status: 409, data: { error: 'Already filed.' } } })

		expect(await actions.fileSupplementaryReturn({ item: { id: 'corr-1' } })).toBe('failed')
		expect(mocks.showError).toHaveBeenCalledWith('Already filed.')
		expect(mocks.showWarning).not.toHaveBeenCalled()
		expect(assign).not.toHaveBeenCalled()
	})
})

describe('openVatPositionLines', () => {
	it('opens nothing when no argument carries lines', () => {
		expect(actions.openVatPositionLines({ item: { id: 'x' } }, 'row', null)).toBeNull()
		expect(mocks.spawnDialog).not.toHaveBeenCalled()
	})

	it('takes the lines from a row scope when no box is handed over', () => {
		const item = { box: '1a', lines: [] }

		expect(actions.openVatPositionLines({ item })).toBe('dialog')
		expect(mocks.spawnDialog).toHaveBeenCalledWith({ name: 'VatPositionLinesModal' }, { box: item })
	})
})
