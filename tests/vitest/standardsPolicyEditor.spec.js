/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for StandardsPolicyEditor.vue: how the saved policy
 * orders the framework rows, how rows are switched and moved, and what a save
 * sends. Methods run bound to a fake `this` seeded from the real `data()`, as
 * tests/vitest/pipelinqIntegration.spec.js does.
 *
 * @spec openspec/specs/accounting-standards-policy/spec.md#requirement-req-asp-002-administrator-can-declare-and-order-frameworks
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import StandardsPolicyEditor from '../../src/views/settings/StandardsPolicyEditor.vue'
import { fetchAdministrationContext } from '../../src/api/administrationApi.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `${app}:${text}` }))
vi.mock('../../src/api/administrationApi.js', () => ({ fetchAdministrationContext: vi.fn() }))

/**
 * A fake component instance with its methods and computed bound.
 *
 * @return {object}
 */
function editor() {
	const self = { ...StandardsPolicyEditor.data.call({}) }
	for (const [name, method] of Object.entries(StandardsPolicyEditor.methods)) {
		self[name] = method.bind(self)
	}
	Object.defineProperty(self, 'resolvedLabel', { get: StandardsPolicyEditor.computed.resolvedLabel.bind(self) })
	return self
}

describe('StandardsPolicyEditor', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.get = vi.fn()
		axios.put = vi.fn()
	})

	it('starts loading with every framework off, in catalogue order', () => {
		const vm = editor()

		expect([vm.loading, vm.saving, vm.administrationId, vm.canEdit]).toEqual([true, false, null, false])
		expect(vm.rows).toHaveLength(19)
		expect(vm.rows.slice(0, 3).map((row) => row.key)).toEqual(['ifrs', 'ifrs-eu', 'dutch-gaap'])
		expect(vm.rows.every((row) => row.enabled === false)).toBe(true)
		expect(vm.rows[0].docs).toBe('https://shillinq.conduction.nl/standards/ifrs')
		expect(vm.resolvedLabel).toBe('—')
	})

	it('loads the active administration\'s policy and orders the rows by precedence', async () => {
		fetchAdministrationContext.mockResolvedValue({ activeAdministrationId: 'adm 1' })
		axios.get.mockResolvedValue({
			data: {
				canEdit: true,
				frameworks: [
					{ key: 'dutch-gaap', enabled: true, precedence: 1 },
					{ key: 'ifrs', enabled: false, precedence: 2 },
					{ key: 'esrs', enabled: true, precedence: 3 },
					{ key: 'unknown', enabled: true, precedence: 0 },
				],
			},
		})
		const vm = editor()

		await vm.load()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/adm%201/standards-policy')
		expect([vm.loading, vm.administrationId, vm.canEdit]).toEqual([false, 'adm 1', true])
		expect(vm.rows.slice(0, 4).map((row) => [row.key, row.enabled])).toEqual([
			['dutch-gaap', true],
			['ifrs', false],
			['esrs', true],
			['ifrs-eu', false],
		])
		expect(vm.rows.map((row) => row.key)).not.toContain('unknown')
		expect(vm.rows).toHaveLength(19)
		expect(vm.resolvedLabel).toBe('Dutch GAAP (BW Title 9 + RJ)')
	})

	it('reads canEdit only when it is literally true, and a missing list as no policy', async () => {
		fetchAdministrationContext.mockResolvedValue({ activeAdministrationId: 'a1' })
		axios.get.mockResolvedValue({ data: { canEdit: 'yes', frameworks: 'none' } })
		const vm = editor()

		await vm.load()

		expect(vm.canEdit).toBe(false)
		expect(vm.rows[0].key).toBe('ifrs')
		expect(vm.rows.some((row) => row.enabled)).toBe(false)
	})

	it('reads no policy without an active administration', async () => {
		fetchAdministrationContext.mockResolvedValue({})
		const vm = editor()

		await vm.load()

		expect(axios.get).not.toHaveBeenCalled()
		expect([vm.loading, vm.administrationId, vm.canEdit]).toEqual([false, null, false])
	})

	it('keeps the defaults when the policy cannot be read', async () => {
		fetchAdministrationContext.mockResolvedValue({ activeAdministrationId: 'a1' })
		axios.get.mockRejectedValue(new Error('403'))
		const vm = editor()

		await vm.load()

		expect(vm.loading).toBe(false)
		expect(vm.canEdit).toBe(false)
		expect(vm.rows[0].key).toBe('ifrs')
	})

	it('switches a row and moves rows within bounds', () => {
		const vm = editor()
		vm.setEnabled(1, true)
		expect(vm.resolvedLabel).toBe('IFRS (EU-endorsed)')

		vm.moveUp(1)
		expect(vm.rows.slice(0, 2).map((row) => row.key)).toEqual(['ifrs-eu', 'ifrs'])
		vm.moveDown(0)
		expect(vm.rows.slice(0, 2).map((row) => row.key)).toEqual(['ifrs', 'ifrs-eu'])

		const before = vm.rows.map((row) => row.key)
		vm.moveUp(0)
		vm.moveDown(vm.rows.length - 1)
		expect(vm.rows.map((row) => row.key)).toEqual(before)
	})

	it('saves the order as one-based precedence and confirms', async () => {
		axios.put.mockResolvedValue({ data: {} })
		const vm = editor()
		vm.administrationId = 'a1'
		vm.setEnabled(2, true)
		vm.moveUp(2)

		const saving = vm.save()
		expect(vm.saving).toBe(true)
		await saving

		const [url, payload] = axios.put.mock.calls[0]
		expect(url).toBe('/index.php/apps/shillinq/api/administrations/a1/standards-policy')
		expect(payload.frameworks).toHaveLength(19)
		expect(payload.frameworks.slice(0, 3)).toEqual([
			{ key: 'ifrs', enabled: false, precedence: 1 },
			{ key: 'dutch-gaap', enabled: true, precedence: 2 },
			{ key: 'ifrs-eu', enabled: false, precedence: 3 },
		])
		expect(showSuccess).toHaveBeenCalledWith('shillinq:Standards policy saved.')
		expect(showError).not.toHaveBeenCalled()
		expect(vm.saving).toBe(false)
	})

	it('reports a save that failed', async () => {
		axios.put.mockRejectedValue(new Error('500'))
		const vm = editor()
		vm.administrationId = 'a1'

		await vm.save()

		expect(showError).toHaveBeenCalledWith('shillinq:Failed to save the standards policy.')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(vm.saving).toBe(false)
	})
})
