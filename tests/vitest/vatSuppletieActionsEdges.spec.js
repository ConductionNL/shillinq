/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The edges of the supplementary-return actions
 * (src/utils/vatSuppletieActions.js, tax-vat-position-and-suppletie 2.1 and
 * 2.2): a correction small enough for the next return, a prepare that fails,
 * the record id taken from the page or the address, and every action doing
 * nothing without a record. Only HTTP, the toasts, the event bus, the dialog
 * spawner and the location are replaced.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ post: vi.fn() }))
const toast = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (all, key) => (key in vars ? String(vars[key]) : all)),
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, vars = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (all, key) => encodeURIComponent(vars[key])),
}))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))
vi.mock('../../src/modals/VatPositionLinesModal.vue', () => ({ default: {} }))

const actions = await import('../../src/utils/vatSuppletieActions.js')

let assign

/**
 * Put the browser on an address.
 *
 * @param {string} pathname The path.
 * @return {void}
 */
function at(pathname) {
	vi.stubGlobal('window', { location: { pathname, assign } })
}

beforeEach(() => {
	vi.clearAllMocks()
	assign = vi.fn()
	at('/apps/shillinq/bookkeeping/vat-corrections/')
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('recordId', () => {
	it('takes the page item, then the page object, then the last part of the address', () => {
		expect(actions.recordId({ item: { id: 'item-1' }, object: { id: 'obj-1' } })).toBe('item-1')
		expect(actions.recordId({ object: { id: 'obj-1' } })).toBe('obj-1')
		at('/apps/shillinq/bookkeeping/vat-returns/ret-9')
		expect(actions.recordId(null)).toBe('ret-9')
	})

	it('answers an empty id on an address that ends in a slash', () => {
		expect(actions.recordId({})).toBe('')
	})
})

describe('preparedMessage', () => {
	it('says a correction of EUR 1,000 or less goes into the next return', () => {
		expect(actions.preparedMessage({ thresholdExceeded: false })).toBe('The correction is EUR 1,000 or less and can go into the next return. The correction posting is staged.')
		expect(actions.preparedMessage(null)).toBe('The correction is EUR 1,000 or less and can go into the next return. The correction posting is staged.')
	})

	it('names no date when an above-threshold correction has no deadline', () => {
		expect(actions.preparedMessage({ thresholdExceeded: true, filingDeadline: null })).toBe('The correction is above EUR 1,000: file the supplementary return before . The correction posting is staged.')
	})
})

describe('without a record', () => {
	it('checks, prepares, downloads and files nothing', async () => {
		await expect(actions.checkVatReturnForCorrections({})).resolves.toBeNull()
		await expect(actions.prepareSupplementaryReturn({})).resolves.toBeNull()
		expect(actions.downloadSupplementaryReturn({})).toBe('')
		await expect(actions.fileSupplementaryReturn({})).resolves.toBe('failed')

		expect(http.post).not.toHaveBeenCalled()
		expect(assign).not.toHaveBeenCalled()
		expect(bus.emit).not.toHaveBeenCalled()
	})
})

describe('prepareSupplementaryReturn', () => {
	it('stages a small correction for the next return and refreshes the page', async () => {
		http.post.mockResolvedValue({ data: { data: { id: 'corr-1', thresholdExceeded: false } } })

		await expect(actions.prepareSupplementaryReturn({ item: { id: 'corr-1' } })).resolves.toEqual({ id: 'corr-1', thresholdExceeded: false })

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/vat-corrections/corr-1/prepare')
		expect(toast.showSuccess).toHaveBeenCalledWith('The correction is EUR 1,000 or less and can go into the next return. The correction posting is staged.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})

	it('answers null when the endpoint sends no correction back', async () => {
		http.post.mockResolvedValue({ data: {} })
		await expect(actions.prepareSupplementaryReturn({ item: { id: 'corr-2' } })).resolves.toBeNull()
	})

	it('shows the server refusal, or a fallback, and does not refresh', async () => {
		http.post.mockRejectedValueOnce({ response: { data: { error: 'Periode is gesloten' } } })
		await expect(actions.prepareSupplementaryReturn({ item: { id: 'corr-3' } })).resolves.toBeNull()
		expect(toast.showError).toHaveBeenLastCalledWith('Periode is gesloten')

		http.post.mockRejectedValueOnce(new Error('offline'))
		await expect(actions.prepareSupplementaryReturn({ item: { id: 'corr-3' } })).resolves.toBeNull()
		expect(toast.showError).toHaveBeenLastCalledWith('The supplementary return could not be prepared.')
		expect(bus.emit).not.toHaveBeenCalled()
	})
})

describe('fileSupplementaryReturn', () => {
	it('does not download on a conflict that offers no download', async () => {
		http.post.mockRejectedValue({ response: { status: 409, data: { error: 'Al ingediend' } } })

		await expect(actions.fileSupplementaryReturn({ item: { id: 'corr-4' } })).resolves.toBe('failed')

		expect(toast.showError).toHaveBeenCalledWith('Al ingediend')
		expect(toast.showWarning).not.toHaveBeenCalled()
		expect(assign).not.toHaveBeenCalled()
	})
})
