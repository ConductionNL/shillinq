/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * budget-known-costs design.md §12: the "Regenerate known costs" action on
 * Budget Line Derivations, its request helper and its dialog. Only HTTP, the
 * dialog spawner and the Nextcloud globals are stubbed; the helpers run for
 * real.
 */

import axios from '@nextcloud/axios'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RegenerateKnownCostsModal from '../../src/modals/RegenerateKnownCostsModal.vue'
import { openRegenerateKnownCosts } from '../../src/utils/knownCostActions.js'
import {
	regenerateErrorText,
	regenerateKnownCosts,
	summaryLines,
} from '../../src/utils/knownCostApi.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? String(vars[key]) : m)),
	translatePlural: (app, one, many) => many,
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcTextField: {} }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn(() => 'dialog') }))

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

/**
 * A fake component instance: data, bound methods, live computed getters and a
 * recorded $emit.
 *
 * @return {object} The instance.
 */
function instance() {
	const Comp = RegenerateKnownCostsModal
	const vm = { emitted: [] }
	vm.$emit = (...args) => vm.emitted.push(args)
	for (const [name, method] of Object.entries(Comp.methods)) {
		vm[name] = method.bind(vm)
	}
	for (const [name, getter] of Object.entries(Comp.computed)) {
		Object.defineProperty(vm, name, { get: () => getter.call(vm), configurable: true })
	}
	Object.assign(vm, Comp.data.call(vm))
	return vm
}

const httpError = (status) => Object.assign(new Error('x'), { response: { status, data: {} } })

const RESULT = { created: 2, updated: 1, overridden: 1, skippedFiscalYears: [2025, 2027] }

beforeEach(() => {
	axios.post.mockReset()
	spawnDialog.mockClear()
})

describe('regenerateKnownCosts', () => {
	it('posts to the regeneration route, naming the administration only when given', async () => {
		axios.post.mockResolvedValue({ data: RESULT })

		expect(await regenerateKnownCosts()).toEqual(RESULT)
		expect(await regenerateKnownCosts('adm-2')).toEqual(RESULT)

		expect(axios.post.mock.calls).toEqual([
			['/index.php/apps/shillinq/api/v1/budgets/known-costs/regenerate', {}],
			['/index.php/apps/shillinq/api/v1/budgets/known-costs/regenerate', { administrationId: 'adm-2' }],
		])
	})
})

describe('summaryLines', () => {
	it('says what was written, what was left alone and which years had no budget', () => {
		expect(summaryLines(RESULT)).toEqual([
			'Budget lines created: 2. Updated: 1.',
			'Budget lines you changed by hand and that were left alone: 1.',
			'Years without a default budget, so nothing was written for them: 2025, 2027.',
		])
	})

	it('leaves out the lines with nothing to say', () => {
		expect(summaryLines({ created: 0, updated: 0, overridden: 0, skippedFiscalYears: [] })).toEqual([
			'Budget lines created: 0. Updated: 0.',
		])
		expect(summaryLines({})).toEqual(['Budget lines created: 0. Updated: 0.'])
	})
})

describe('regenerateErrorText', () => {
	it('tells a refused caller apart from a failed run', () => {
		const refused = 'You cannot regenerate the budget of this administration.'
		expect(regenerateErrorText(httpError(401))).toBe(refused)
		expect(regenerateErrorText(httpError(403))).toBe(refused)
		expect(regenerateErrorText(httpError(404))).toBe(refused)
		expect(regenerateErrorText(httpError(500))).toMatch(/could not be regenerated/)
		expect(regenerateErrorText(new Error('offline'))).toMatch(/could not be regenerated/)
	})
})

describe('the Regenerate known costs action', () => {
	it('opens the dialog and is registered for both handler maps', () => {
		expect(openRegenerateKnownCosts()).toBe('dialog')
		expect(spawnDialog).toHaveBeenCalledWith(RegenerateKnownCostsModal, {})
		// Imported for real the registry pulls in every dialog of the app, so
		// its source is read: imported once and listed in the frozen map.
		const registry = readFileSync(join(ROOT, 'src/manifestActions.js'), 'utf8')
		expect(registry).toContain("import { openRegenerateKnownCosts } from './utils/knownCostActions.js'")
		expect(registry).toMatch(/\n\topenRegenerateKnownCosts,\n/)
	})

	it('is a header action of the Derivations index page', () => {
		const fragment = JSON.parse(
			readFileSync(join(ROOT, 'src/manifest.d/budget-known-costs.json'), 'utf8'),
		)
		const page = fragment.pages.find((p) => p.id === 'BudgetLineDerivations')
		expect(page.config.headerActions).toContainEqual(
			expect.objectContaining({ id: 'regenerate-known-costs', handler: 'openRegenerateKnownCosts' }),
		)
	})
})

describe('RegenerateKnownCostsModal', () => {
	it('runs, shows the summary and hands it back on close', async () => {
		axios.post.mockResolvedValue({ data: RESULT })
		const vm = instance()

		await vm.submit()

		expect(vm.submitting).toBe(false)
		expect(vm.error).toBe('')
		expect(vm.lines).toHaveLength(3)
		vm.close()
		expect(vm.emitted).toEqual([['close', RESULT]])
	})

	it('shows the refusal and keeps no result', async () => {
		axios.post.mockRejectedValue(httpError(404))
		const vm = instance()

		await vm.submit()

		expect(vm.result).toBeNull()
		expect(vm.lines).toEqual([])
		expect(vm.error).toBe('You cannot regenerate the budget of this administration.')
		vm.close()
		expect(vm.emitted).toEqual([['close', null]])
	})
})
