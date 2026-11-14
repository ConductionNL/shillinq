/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the pipelinq settings panel's requests and feedback
 * (src/views/settings/pipelinqSettings.js).
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const { errorFeedback, postPipelinq, settingsBody, testVerdict } = await import('../../src/views/settings/pipelinqSettings.js')

describe('pipelinqSettings', () => {
	beforeEach(() => {
		globalThis.OC = { requestToken: 'tok-123' }
		globalThis.fetch = vi.fn(() => Promise.resolve({ ok: true }))
	})

	afterEach(() => {
		delete globalThis.OC
	})

	it('posts JSON with the request token, and a body only when given', async () => {
		await postPipelinq('/apps/shillinq/api/pipelinq/settings', { endpoint: 'https://p' })
		await postPipelinq('/apps/shillinq/api/pipelinq/settings/test')

		expect(fetch.mock.calls[0]).toEqual([
			'/index.php/apps/shillinq/api/pipelinq/settings',
			{ method: 'POST', headers: { 'Content-Type': 'application/json', requesttoken: 'tok-123' }, body: '{"endpoint":"https://p"}' },
		])
		expect(fetch.mock.calls[1]).toEqual([
			'/index.php/apps/shillinq/api/pipelinq/settings/test',
			{ method: 'POST', headers: { 'Content-Type': 'application/json', requesttoken: 'tok-123' } },
		])
	})

	it('sends the token only when one was typed', () => {
		expect(settingsBody({ endpoint: 'https://p', token: '' })).toEqual({ endpoint: 'https://p' })
		expect(settingsBody({ endpoint: 'https://p', token: 'new' })).toEqual({ endpoint: 'https://p', token: 'new' })
	})

	it('words the test-connection verdict', () => {
		expect(testVerdict({ success: true, message: 'ok' })).toEqual({ kind: 'success', message: 'ok' })
		expect(testVerdict({ success: false, message: 'no' })).toEqual({ kind: 'error', message: 'no' })
		expect(errorFeedback('boom')).toEqual({ kind: 'error', message: 'boom' })
	})
})
