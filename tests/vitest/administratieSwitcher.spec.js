/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * AdministratieSwitcher: it reads the administrations the user may open,
 * labels them, and switches through the administrations API, with a
 * message per refusal.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-13
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import Switcher from '../../src/components/AdministratieSwitcher.vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))

const CONTEXT = '/index.php/apps/shillinq/api/administrations/context'
const SWITCH = '/index.php/apps/shillinq/api/administrations/switch'

/**
 * A stand-in instance.
 *
 * @param {object} [fields] Fields to override.
 * @return {object} The instance.
 */
function instance(fields = {}) {
	const self = { reloadAfterSwitch: false, $emit: vi.fn(), ...Switcher.data(), ...fields }
	for (const [name, fn] of Object.entries(Switcher.methods)) {
		self[name] = fn.bind(self)
	}
	for (const [name, fn] of Object.entries(Switcher.computed)) {
		Object.defineProperty(self, name, { get: () => fn.call(self) })
	}
	return self
}

const ADMINS = [
	{ administrationId: 'a1', administrationCode: 'HQ', name: 'Hoofdkantoor' },
	{ administrationId: 'a2', name: 'Filiaal' },
	{ administrationId: 'a3', administrationCode: 'X9' },
	{ administrationId: 'a4' },
]

beforeEach(() => {
	globalThis.t = (_app, text, vars) => text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
	http.get.mockReset()
	http.post.mockReset()
})

afterEach(() => {
	delete globalThis.t
})

describe('reading the administrations', () => {
	it('reads the context on mount', async () => {
		http.get.mockResolvedValue({ data: { administrations: ADMINS, activeAdministrationId: 'a2' } })
		const self = instance()
		await Switcher.mounted.call(self)
		expect(http.get).toHaveBeenCalledWith(CONTEXT)
		expect(self.administrations).toBe(ADMINS)
		expect(self.visibleAdministrations).toBe(ADMINS)
		expect(self.activeId).toBe('a2')
		expect(self.loading).toBe(false)
		expect(self.captionLabel).toBe('4 accessible administrations')
	})

	it('takes a missing list as none and a missing active id as null', async () => {
		http.get.mockResolvedValue({ data: { administrations: 'nope' } })
		const self = instance()
		await self.loadContext()
		expect(self.administrations).toEqual([])
		expect(self.activeId).toBeNull()
	})

	it('reports a failed read and clears what it had', async () => {
		const error = new Error('down')
		http.get.mockRejectedValue(error)
		const self = instance({ administrations: ADMINS, activeId: 'a1' })
		await self.loadContext()
		expect(self.administrations).toEqual([])
		expect(self.activeId).toBeNull()
		expect(self.errorMessage).toBe('Failed to load administrations')
		expect(self.$emit).toHaveBeenCalledWith('error', error)
		expect(self.loading).toBe(false)
	})
})

describe('labels', () => {
	it('labels by code and name, either alone, or the id', () => {
		const self = instance()
		expect(ADMINS.map(self.formatLabel)).toEqual(['HQ — Hoofdkantoor', 'Filiaal', 'X9', 'a4'])
	})

	it('names the active administration, or invites a switch', () => {
		expect(instance({ administrations: ADMINS, activeId: 'a1' }).activeLabel).toBe('HQ — Hoofdkantoor')
		expect(instance({ administrations: ADMINS, activeId: 'zz' }).activeLabel).toBe('Switch administration')
	})
})

describe('switching', () => {
	it('switches, reports the new administration and stays without reload', async () => {
		http.post.mockResolvedValue({ data: { activeAdministrationId: 'a2' } })
		const self = instance({ activeId: 'a1' })
		await self.onSelect('a2')
		expect(http.post).toHaveBeenCalledWith(SWITCH, { administrationId: 'a2' })
		expect(self.activeId).toBe('a2')
		expect(self.$emit).toHaveBeenCalledWith('switched', 'a2')
		expect(self.loading).toBe(false)
	})

	it('reloads the page after a switch when asked to', async () => {
		http.post.mockResolvedValue({ data: { activeAdministrationId: 'a2' } })
		const reload = vi.fn()
		vi.stubGlobal('window', { location: { reload } })
		try {
			await instance({ activeId: 'a1', reloadAfterSwitch: true }).onSelect('a2')
		} finally {
			vi.unstubAllGlobals()
		}
		expect(reload).toHaveBeenCalledTimes(1)
	})

	it('does nothing while loading or for the active administration', async () => {
		await instance({ loading: true, activeId: 'a1' }).onSelect('a2')
		await instance({ activeId: 'a1' }).onSelect('a1')
		expect(http.post).not.toHaveBeenCalled()
	})

	it.each([
		[404, 'Administration not found'],
		[401, 'Not authenticated'],
		[500, 'Failed to switch administration'],
	])('explains a %s refusal', async (status, message) => {
		const error = { response: { status } }
		http.post.mockRejectedValue(error)
		const self = instance({ activeId: 'a1' })
		await self.onSelect('a2')
		expect(self.errorMessage).toBe(message)
		expect(self.activeId).toBe('a1')
		expect(self.$emit).toHaveBeenCalledWith('error', error)
		expect(self.loading).toBe(false)
	})
})
