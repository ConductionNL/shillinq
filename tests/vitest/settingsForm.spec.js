/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Characterisation tests for Settings.vue: the form prefilled from the
 * settings store, and the message a save leaves. Hooks and methods run bound
 * to a fake `this` seeded from the real `data()`.
 *
 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import Settings from '../../src/views/settings/Settings.vue'
import { useSettingsStore } from '../../src/store/modules/settings.js'

vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: vi.fn() }))

/**
 * A fake component instance with its methods bound, after created().
 *
 * @return {object}
 */
function form() {
	const self = { ...Settings.data.call({}) }
	for (const [name, method] of Object.entries(Settings.methods)) {
		self[name] = method.bind(self)
	}
	Settings.created.call(self)
	return self
}

describe('Settings', () => {
	let store

	beforeEach(() => {
		globalThis.t = (app, text) => `${app}:${text}`
		store = { settings: null, saveSettings: vi.fn() }
		useSettingsStore.mockReturnValue(store)
	})

	it('prefills the register and portal return address from the store', () => {
		store.settings = { register: 'shillinq', portal_payment_redirect_url: 'https://portal.example/paid', other: 'x' }

		expect(form().form).toEqual({ register: 'shillinq', portal_payment_redirect_url: 'https://portal.example/paid' })
	})

	it('prefills empty fields when the store has no settings', () => {
		expect(form().form).toEqual({ register: '', portal_payment_redirect_url: '' })

		store.settings = { register: null }
		expect(form().form).toEqual({ register: '', portal_payment_redirect_url: '' })
	})

	it('saves the form through the store and confirms', async () => {
		store.saveSettings.mockResolvedValue({ register: 'r2' })
		const vm = form()
		vm.form.register = 'r2'
		vm.errorMessage = 'old'

		const saving = vm.save()
		expect([vm.saving, vm.errorMessage, vm.successMessage]).toEqual([true, '', ''])
		await saving

		expect(store.saveSettings).toHaveBeenCalledWith({ register: 'r2', portal_payment_redirect_url: '' })
		expect([vm.saving, vm.successMessage, vm.errorMessage]).toEqual([false, 'shillinq:Settings saved successfully', ''])
	})

	it('explains a refused save', async () => {
		store.saveSettings.mockResolvedValue(null)
		const vm = form()
		vm.successMessage = 'old'

		await vm.save()

		expect([vm.saving, vm.successMessage]).toEqual([false, ''])
		expect(vm.errorMessage).toBe('shillinq:The settings were not saved. Check that the portal return address starts with https://.')
	})
})
