/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for FxRatesAdmin.vue's fetchStatus(): the status it
 * shows, its defaults, and the message of each failure. Methods run bound to
 * a fake `this` seeded from the real `data()`.
 *
 * @spec openspec/changes/add-shillinq-multi-currency/tasks.md#task-14
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FxRatesAdmin from '../../src/views/bookkeeping/multi-currency/FxRatesAdmin.vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', () => ({ NcAppContent: {}, NcButton: {}, NcLoadingIcon: {} }))

/**
 * A fake admin page with its methods bound.
 *
 * @return {object}
 */
function page() {
	const self = { ...FxRatesAdmin.data.call({}), t: (app, text) => `${app}:${text}` }
	for (const [name, method] of Object.entries(FxRatesAdmin.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

describe('FxRatesAdmin fetchStatus', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('shows the import status', async () => {
		axios.get.mockResolvedValue({ data: { jobClass: 'Job', lastRunAt: '2026-11-01', lastRunEpoch: 1, adapterDormant: 1, interval: '3600', status: 'ok' } })
		const vm = page()

		await vm.fetchStatus()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/admin/fx-rate-import-status')
		expect([vm.status, vm.loading, vm.errorMessage]).toEqual([{ jobClass: 'Job', lastRunAt: '2026-11-01', lastRunEpoch: 1, adapterDormant: true, interval: 3600, status: 'ok' }, false, ''])
	})

	it('defaults what the answer leaves out, keeping the known job class', async () => {
		axios.get.mockResolvedValue({ data: null })
		const vm = page()
		const jobClass = vm.status.jobClass

		await vm.fetchStatus()

		expect(vm.status).toEqual({ jobClass, lastRunAt: null, lastRunEpoch: null, adapterDormant: false, interval: 86400, status: 'never-ran' })
	})

	it('words each failure', async () => {
		const messages = []
		for (const status of [401, 403, 500]) {
			axios.get.mockRejectedValueOnce({ response: { status } })
			const vm = page()
			await vm.fetchStatus()
			messages.push([vm.errorMessage, vm.loading])
		}

		expect(messages).toEqual([
			['shillinq:Admin permission required to read FX import status.', false],
			['shillinq:Admin permission required to read FX import status.', false],
			['shillinq:Failed to load FX import status.', false],
		])
	})
})
