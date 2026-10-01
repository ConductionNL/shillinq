/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * tax-vat-number-check: the Check VAT number action on the customer and
 * supplier pages reaches the route, shows valid, invalid and not reachable,
 * and the pages, the handler map and appinfo/routes.php agree.
 *
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-2.1
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const mainJs = fs.readFileSync(path.join(ROOT, 'src/main.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) =>
		'/index.php' + url.replace(/\{(\w+)\}/g, (m, k) => params[k]),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, k) => vars[k]),
}))
const dialogs = { showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
const axiosMock = { post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

/**
 * Every page with the given id across src/manifest.json and the fragments.
 *
 * @param {string} id The page id.
 * @return {object[]} The pages.
 */
function pages(id) {
	const files = [path.join(ROOT, 'src/manifest.json')].concat(
		fs
			.readdirSync(path.join(ROOT, 'src/manifest.d'))
			.filter((f) => f.endsWith('.json'))
			.map((f) => path.join(ROOT, 'src/manifest.d', f)),
	)
	return files.flatMap((f) =>
		(JSON.parse(fs.readFileSync(f, 'utf8')).pages || []).filter(
			(p) => p.id === id,
		),
	)
}

describe('tax-vat-number-check', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('posts the customer check to the route and shows a valid number', async () => {
		const { checkCustomerVatNumber } =
			await import('../../src/utils/vatNumberCheck.js')
		axiosMock.post.mockResolvedValue({
			data: {
				status: 'valid',
				vatId: 'DE000000000',
				lastValidAt: '2026-10-01T09:00:00+00:00',
			},
		})

		const result = await checkCustomerVatNumber({ item: { id: 'c-1' } })

		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/vat-number-checks/customer/c-1',
		)
		expect(result.status).toBe('valid')
		expect(dialogs.showSuccess.mock.calls[0][0]).toContain(
			'VAT number DE000000000 is valid',
		)
	})

	it('shows an invalid number as an error and VIES down as a warning with the last valid date', async () => {
		const { checkSupplierVatNumber, vatCheckMessage } =
			await import('../../src/utils/vatNumberCheck.js')
		axiosMock.post.mockResolvedValue({
			data: { status: 'invalid', vatId: 'BE0000000000', lastValidAt: null },
		})
		await checkSupplierVatNumber({ item: { id: 'p-1' } })
		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/vat-number-checks/supplier/p-1',
		)
		expect(dialogs.showError.mock.calls[0][0]).toContain('is not valid')

		const down = vatCheckMessage({
			status: 'vies_outage',
			vatId: 'BE0000000000',
			lastValidAt: '2026-09-21T09:00:00+00:00',
		})
		expect(down.kind).toBe('warning')
		expect(down.text).toContain(
			'VIES cannot be reached. VAT number BE0000000000 was last valid on',
		)
		expect(
			vatCheckMessage({
				status: 'vies_outage',
				vatId: 'BE1',
				lastValidAt: null,
			}).text,
		).toContain('has not been confirmed before')
	})

	it('shows the refusal the endpoint answers with', async () => {
		const { checkCustomerVatNumber } =
			await import('../../src/utils/vatNumberCheck.js')
		axiosMock.post.mockRejectedValue({
			response: { data: { error: 'This record has no VAT number to check.' } },
		})

		expect(await checkCustomerVatNumber({ item: { id: 'c-2' } })).toBeNull()
		expect(dialogs.showError).toHaveBeenCalledWith(
			'This record has no VAT number to check.',
		)
	})

	it('declares the action on both pages, maps the handlers and routes the endpoint', () => {
		const handlers = {
			CustomerDetail: 'checkCustomerVatNumber',
			PayeeDetail: 'checkSupplierVatNumber',
		}
		for (const [page, handler] of Object.entries(handlers)) {
			const actions = pages(page).flatMap((p) => p.config.headerActions || [])
			expect(
				actions.find((a) => a.handler === handler),
				page,
			).toBeTruthy()
			expect(mainJs).toContain(handler)
		}
		expect(routes).toContain("'url' => '/api/vat-number-checks/{type}/{id}'")
	})
})
