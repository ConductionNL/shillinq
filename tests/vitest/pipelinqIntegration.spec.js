/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for PipelinqIntegration.vue: the requests the
 * settings panel sends and the feedback and state each response leaves.
 * Methods run bound to a fake `this` seeded from the real `data()`, as
 * tests/vitest/spendAnalyticsPanel.spec.js does.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import PipelinqIntegration from '../../src/views/settings/PipelinqIntegration.vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

/**
 * A fake component instance with its methods bound.
 *
 * @return {object}
 */
function panel() {
	const self = { ...PipelinqIntegration.data.call({}) }
	for (const [name, method] of Object.entries(PipelinqIntegration.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

const HEADERS = { 'Content-Type': 'application/json', requesttoken: 'tok-1' }

describe('PipelinqIntegration', () => {
	beforeEach(() => {
		globalThis.OC = { requestToken: 'tok-1' }
		globalThis.t = (app, text, vars = {}) => `${app}:` + text.replace(/\{(\w+)\}/g, (m, key) => String(vars[key]))
		globalThis.fetch = vi.fn()
	})

	it('saves the endpoint without a token, refreshes the flag and confirms', async () => {
		fetch.mockResolvedValue({ ok: true, json: () => Promise.resolve({ hasToken: 1 }) })
		const vm = panel()
		vm.form.endpoint = 'https://p'

		await vm.save()

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/shillinq/api/pipelinq/settings', { method: 'POST', headers: HEADERS, body: '{"endpoint":"https://p"}' })
		expect([vm.hasToken, vm.form.token, vm.saving, vm.feedback]).toEqual([true, '', false, { kind: 'success', message: 'shillinq:Pipelinq settings saved.' }])
	})

	it('sends a typed token, and keeps it when the save is refused', async () => {
		fetch.mockResolvedValue({ ok: false, status: 403 })
		const vm = panel()
		vm.form.endpoint = 'https://p'
		vm.form.token = 'secret'

		await vm.save()

		expect(fetch.mock.calls[0][1].body).toBe('{"endpoint":"https://p","token":"secret"}')
		expect([vm.hasToken, vm.form.token, vm.saving, vm.feedback]).toEqual([false, 'secret', false, { kind: 'error', message: 'shillinq:Failed to save pipelinq settings (HTTP 403).' }])
	})

	it('reports a save that could not be sent', async () => {
		fetch.mockRejectedValue(new Error('offline'))
		const vm = panel()

		await vm.save()

		expect([vm.saving, vm.feedback]).toEqual([false, { kind: 'error', message: 'shillinq:Failed to save pipelinq settings: offline.' }])
	})

	it('tests the connection without a body and shows the verdict', async () => {
		fetch.mockResolvedValue({ ok: true, json: () => Promise.resolve({ success: false, message: 'Pipelinq returned HTTP 401.' }) })
		const vm = panel()

		await vm.test()

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/shillinq/api/pipelinq/settings/test', { method: 'POST', headers: HEADERS })
		expect([vm.testing, vm.feedback]).toEqual([false, { kind: 'error', message: 'Pipelinq returned HTTP 401.' }])
	})

	it('reports a failed or unsent connection test', async () => {
		fetch.mockResolvedValueOnce({ ok: false, status: 500 })
		const vm = panel()
		await vm.test()
		expect(vm.feedback).toEqual({ kind: 'error', message: 'shillinq:Test connection failed (HTTP 500).' })

		fetch.mockRejectedValueOnce(new Error('dns'))
		await vm.test()
		expect([vm.testing, vm.feedback]).toEqual([false, { kind: 'error', message: 'shillinq:Test connection failed: dns.' }])
	})
})
