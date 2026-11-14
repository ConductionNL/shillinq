/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * sales-quote-and-order-to-invoice 2.1: the Quotes pages and the two detail
 * actions. "Create sales order" on an accepted quote posts to
 * /api/quotes/{id}/order and opens the order; "Invoice order" on a sales
 * order posts to /api/orders/{id}/invoice and opens the draft invoice. A
 * refusal is shown as the server words it, and nothing is opened.
 *
 * @spec openspec/changes/sales-quote-and-order-to-invoice/specs/sales-quote-order-invoice/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => null,
	getRequestToken: () => '',
	onRequestTokenUpdate: () => {},
}))
const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => '/index.php' + url.replace(/\{(\w+)\}/g, (match, key) => encodeURIComponent(params[key])),
}))
const dialogs = { showError: vi.fn(), showSuccess: vi.fn() }
vi.mock('@nextcloud/dialogs', () => ({
	showError: (...args) => dialogs.showError(...args),
	showSuccess: (...args) => dialogs.showSuccess(...args),
	showInfo: vi.fn(),
	showWarning: vi.fn(),
	getFilePickerBuilder: vi.fn(),
}))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))

const { manifestActions } = await import('../../src/manifestActions.js')
const { evaluateVisibleWhenLocal, isLocallyDecidableVisibleWhen } =
	await import('@conduction/nextcloud-vue/dist/esm/utils/visibleWhen.js')

/**
 * One page of a fragment.
 *
 * @param {string} file The fragment.
 * @param {string} id The page id.
 * @return {object} The page.
 */
function pageOf(file, id) {
	return read(file).pages.find((candidate) => candidate.id === id)
}

/**
 * One header action of a detail page.
 *
 * @param {object} page The page.
 * @param {string} id The action id.
 * @return {object} The action.
 */
function actionOf(page, id) {
	return page.config.headerActions.find((action) => action.id === id)
}

/**
 * Whether the action shows on this object.
 *
 * @param {object} action The action.
 * @param {object} object The object.
 * @return {boolean} Shown.
 */
function shownOn(action, object) {
	expect(isLocallyDecidableVisibleWhen(action.visibleWhen)).toBe(true)
	return evaluateVisibleWhenLocal(action.visibleWhen, object) !== false
}

const QUOTE_DETAIL = pageOf('src/manifest.d/sales-quotes.json', 'QuoteDetail')
const ORDER_DETAIL = pageOf('src/manifest.d/order-workspace.json', 'OrderDetail')

let assigned
beforeEach(() => {
	axiosMock.post.mockReset()
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
	assigned = []
	vi.stubGlobal('window', {
		location: {
			pathname: '/index.php/apps/shillinq/quotes/q-from-path',
			assign: (url) => assigned.push(url),
		},
	})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('Quotes pages', () => {
	it('lists quotes and opens the quote detail, under Sales', () => {
		const index = pageOf('src/manifest.d/sales-quotes.json', 'Quotes')
		expect(index.config.schema).toBe('Quote')
		expect(index.config.detailRoute).toBe('QuoteDetail')
		expect(QUOTE_DETAIL.route).toBe('/quotes/:id')
		expect(read('src/menu-layout.json').relocations.Quotes).toBe('Sales')
	})

	it('shows the quote lines of this quote', () => {
		const lines = QUOTE_DETAIL.config.relatedCollections[0]
		expect(lines.schema).toBe('QuoteLine')
		expect(lines.filter).toEqual({ quote: '@objectId' })
	})

	it('offers Create sales order on an accepted quote only', () => {
		const action = actionOf(QUOTE_DETAIL, 'create-sales-order')
		expect(action.handler).toBe('createSalesOrderFromQuote')
		expect(manifestActions[action.handler]).toBeTypeOf('function')
		expect(shownOn(action, { status: 'accepted' })).toBe(true)
		expect(shownOn(action, { status: 'sent' })).toBe(false)
	})
})

describe('Orders page', () => {
	it('filters to sales orders', () => {
		const filters = pageOf('src/manifest.d/order-workspace.json', 'Orders').config.quickFilters
		expect(filters.find((filter) => filter.label === 'Sales').filter).toEqual({ orderType: 'sales' })
	})

	it('offers Invoice order on a sales order only', () => {
		const action = actionOf(ORDER_DETAIL, 'invoice-sales-order')
		expect(manifestActions[action.handler]).toBeTypeOf('function')
		expect(shownOn(action, { orderType: 'sales', state: 'confirmed' })).toBe(true)
		expect(shownOn(action, { orderType: 'purchase', state: 'ORDERED' })).toBe(false)
	})
})

describe('the actions', () => {
	it('creates the order from the quote and opens it', async () => {
		axiosMock.post.mockResolvedValue({ data: { id: 'o-7' } })

		const order = await manifestActions.createSalesOrderFromQuote({ item: { id: 'q-1' } })

		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/quotes/q-1/order')
		expect(order).toEqual({ id: 'o-7' })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Sales order created from the quote.')
		expect(assigned).toEqual(['/index.php/apps/shillinq/orders/o-7'])
	})

	it('reads the quote from the path when the page passes no item', async () => {
		axiosMock.post.mockResolvedValue({ data: { id: 'o-8' } })

		await manifestActions.createSalesOrderFromQuote(undefined)

		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/quotes/q-from-path/order')
	})

	it('shows the server refusal and opens nothing', async () => {
		axiosMock.post.mockRejectedValue({ response: { status: 422, data: { message: 'Deze offerte heeft al een verkooporder.' } } })

		const order = await manifestActions.createSalesOrderFromQuote({ item: { id: 'q-1' } })

		expect(order).toBeNull()
		expect(dialogs.showError).toHaveBeenCalledWith('Deze offerte heeft al een verkooporder.')
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
		expect(assigned).toEqual([])
	})

	it('invoices the order and opens the draft invoice', async () => {
		axiosMock.post.mockResolvedValue({ data: { id: 'inv-3' } })

		await manifestActions.invoiceSalesOrder({ item: { id: 'o-7' } })

		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/orders/o-7/invoice')
		expect(assigned).toEqual(['/index.php/apps/shillinq/bookkeeping/accounts-receivable/inv-3'])
	})

	it('falls back to its own message when the server gives none', async () => {
		axiosMock.post.mockRejectedValue(new Error('network'))

		await manifestActions.invoiceSalesOrder({ item: { id: 'o-7' } })

		expect(dialogs.showError).toHaveBeenCalledWith('The order could not be invoiced.')
	})
})
