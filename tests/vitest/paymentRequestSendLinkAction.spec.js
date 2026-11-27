/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * receivables-payment-links 3.3 (REQ-RPL-002): Send payment link on the
 * payment request page is disabled with the reason while online payment is
 * not set up.
 *
 * The action is manifest configuration; its `disabledWhen` is evaluated here
 * through the INSTALLED library's own `evaluateVisibleWhen`, the function
 * CnActionButtons calls for every `disabledWhen`, against the answer of
 * `GET /api/online-payment/status` (OnlinePaymentStatusController).
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-3.3
 */

import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => null,
	getRequestToken: () => '',
	onRequestTokenUpdate: () => {},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const root = path.resolve(__dirname, '../..')

/**
 * The page's header action, as the manifest fragment declares it.
 *
 * @return {object} The Send payment link action.
 */
function sendAction() {
	const fragment = JSON.parse(fs.readFileSync(path.join(root, 'src/manifest.d/ar-invoice-payment-links.json'), 'utf8'))
	const page = fragment.pages.find((p) => p.id === 'PaymentRequestDetail')
	return (page.config.headerActions || []).find((a) => a.id === 'send-payment-link')
}

/**
 * Answer the status endpoint with the given body.
 *
 * @param {object} body The JSON the endpoint returns.
 * @return {Function} The fetch mock.
 */
function answer(body) {
	const fetchMock = vi.fn(async () => ({ ok: true, status: 200, json: async () => body }))
	vi.stubGlobal('fetch', fetchMock)
	return fetchMock
}

describe('Send payment link on the payment request page (REQ-RPL-002)', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('posts to the send endpoint of the open record, only on a pending request', () => {
		const action = sendAction()
		expect(action).toBeTruthy()
		expect(action.type).toBe('api-call')
		expect(action.method).toBe('POST')
		expect(action.url).toBe('/apps/shillinq/api/payment-requests/@objectId/send')
		expect(action.visibleWhen).toEqual({ field: 'state', op: 'eq', value: 'pending' })
	})

	it('is disabled with the reason while the adapter is dormant', async () => {
		const { evaluateVisibleWhen } = await import('../../node_modules/@conduction/nextcloud-vue/dist/esm/utils/visibleWhen.js')
		const action = sendAction()
		const fetchMock = answer({ live: false, reason: 'not-set-up', message: 'Online payment is not set up: configure a payment source in integriq.' })

		const disabled = await evaluateVisibleWhen(action.disabledWhen, { objectId: 'req-1', object: { state: 'pending' } })

		expect(disabled).toBe(true)
		expect(String(fetchMock.mock.calls[0][0])).toMatch(/\/apps\/shillinq\/api\/online-payment\/status$/)
		expect(action.disabledReason).toBe('Online payment is not set up: configure a payment source in integriq.')
	})

	it('is enabled while a live adapter is bound', async () => {
		const { evaluateVisibleWhen } = await import('../../node_modules/@conduction/nextcloud-vue/dist/esm/utils/visibleWhen.js')
		answer({ live: true, reason: '', message: '' })

		const disabled = await evaluateVisibleWhen(sendAction().disabledWhen, { objectId: 'req-1', object: { state: 'pending' } })

		expect(disabled).toBe(false)
	})

	it('asks a route the app declares', () => {
		const routes = fs.readFileSync(path.join(root, 'appinfo/routes.php'), 'utf8')
		expect(routes).toContain("['name' => 'onlinePaymentStatus#status', 'url' => '/api/online-payment/status', 'verb' => 'GET']")
	})

	it('has its words in English and Dutch', () => {
		const action = sendAction()
		for (const lang of ['en', 'nl']) {
			const strings = JSON.parse(fs.readFileSync(path.join(root, `l10n/${lang}.json`), 'utf8')).translations
			for (const text of [action.label, action.disabledReason, action.successMessage, action.errorMessage]) {
				expect(strings[text], `${lang}: ${text}`).toBeTruthy()
			}
		}
	})
})
