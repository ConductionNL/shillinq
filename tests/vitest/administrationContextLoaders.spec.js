/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins what each component's loadAdministrationContext() does with the
 * administration-context endpoint: the request, the options or names it
 * builds (name, else code, else id), the active administration it picks, and
 * what a failed read leaves behind.
 *
 * @spec openspec/specs/administration-context/spec.md
 */

import axios from '@nextcloud/axios'
import { afterEach, describe, expect, it, vi } from 'vitest'
import BBVComplianceDashboard from '../../src/components/Dashboard/BBVComplianceDashboard.vue'
import GoodsReceiptNoteForm from '../../src/components/goods-receipt-note/GoodsReceiptNoteForm.vue'
import PurchaseOrderForm from '../../src/components/purchase-order/PurchaseOrderForm.vue'
import GeneratedReportsIndex from '../../src/components/reporting/GeneratedReportsIndex.vue'
import ReportingComplianceOverview from '../../src/components/reporting/ReportingComplianceOverview.vue'
import BudgetScenarioComparison from '../../src/views/BudgetScenarioComparison.vue'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, s) => s }))

const originalGet = axios.get

afterEach(() => {
	axios.get = originalGet
})

const CONTEXT = {
	activeAdministrationId: 'a-2',
	administrations: [
		{ administrationId: 'a-1', name: 'One', administrationCode: 'C1' },
		{ administrationId: 'a-2', name: '', administrationCode: 'C2' },
		{ administrationId: 'a-3' },
	],
}

/**
 * A fake instance with the component's methods, a translator and the state.
 *
 * @param {object} component The SFC.
 * @param {object} state Fields to set.
 * @return {object}
 */
function vm(component, state = {}) {
	const ctx = { error: '', errorMessage: '', loading: false, administrationId: 'old', administrationOptions: ['old'], ...state }
	for (const layer of [...(component.mixins || []), component]) {
		for (const [name, fn] of Object.entries(layer.methods || {})) {
			ctx[name] = fn.bind(ctx)
		}
	}
	ctx.t = (app, text) => text
	ctx.loadScenarios = vi.fn(async () => {})
	ctx.loadLedgerGroupNames = vi.fn(async () => {})
	return ctx
}

/**
 * Answer the context request with `data`, recording the URLs.
 *
 * @param {Function} answer The response factory.
 * @return {Array<string>} The URLs read.
 */
function answerWith(answer) {
	const urls = []
	axios.get = vi.fn(async (url) => {
		urls.push(url)
		return answer()
	})
	return urls
}

const OPTIONS = [
	{ value: 'a-1', label: 'One' },
	{ value: 'a-2', label: 'C2' },
	{ value: 'a-3', label: 'a-3' },
]

describe.each([
	['BBVComplianceDashboard', BBVComplianceDashboard],
	['PurchaseOrderForm', PurchaseOrderForm],
	['GoodsReceiptNoteForm', GoodsReceiptNoteForm],
])('%s loadAdministrationContext()', (name, component) => {
	it('builds the options and takes the active administration', async () => {
		const urls = answerWith(async () => ({ data: CONTEXT }))
		const ctx = vm(component)
		await ctx.loadAdministrationContext()
		expect(urls).toEqual(['/apps/shillinq/api/administrations/context'])
		expect([ctx.administrationOptions, ctx.administrationId, ctx.error]).toEqual([OPTIONS, 'a-2', ''])
	})

	it('keeps the administration without an active one, and lists none without data', async () => {
		answerWith(async () => ({}))
		const ctx = vm(component)
		await ctx.loadAdministrationContext()
		expect([ctx.administrationOptions, ctx.administrationId, ctx.error]).toEqual([[], 'old', ''])
	})

	it('reports a failed read', async () => {
		answerWith(async () => {
			throw new Error('down')
		})
		const ctx = vm(component)
		await ctx.loadAdministrationContext()
		expect([ctx.administrationOptions, ctx.administrationId, ctx.error]).toEqual([['old'], 'old', 'Failed to load administration context'])
	})
})

describe('ReportingComplianceOverview loadAdministrationContext()', () => {
	it('builds the options and takes the active administration', async () => {
		answerWith(async () => ({ data: CONTEXT }))
		const ctx = vm(ReportingComplianceOverview, { activeAdministrationId: 'old' })
		await ctx.loadAdministrationContext()
		expect([ctx.administrationOptions, ctx.activeAdministrationId]).toEqual([OPTIONS, 'a-2'])
	})

	it('empties the options on a failed read', async () => {
		answerWith(async () => {
			throw new Error('down')
		})
		const ctx = vm(ReportingComplianceOverview, { activeAdministrationId: 'old' })
		await ctx.loadAdministrationContext()
		expect([ctx.administrationOptions, ctx.activeAdministrationId]).toEqual([[], 'old'])
	})
})

describe('GeneratedReportsIndex loadAdministrationContext()', () => {
	it('names each administration', async () => {
		answerWith(async () => ({ data: CONTEXT }))
		const ctx = vm(GeneratedReportsIndex, { administrationNames: { x: 'y' } })
		await ctx.loadAdministrationContext()
		expect(ctx.administrationNames).toEqual({ 'a-1': 'One', 'a-2': 'C2', 'a-3': 'a-3' })
	})

	it('names none on a failed read', async () => {
		answerWith(async () => {
			throw new Error('down')
		})
		const ctx = vm(GeneratedReportsIndex, { administrationNames: { x: 'y' } })
		await ctx.loadAdministrationContext()
		expect(ctx.administrationNames).toEqual({})
	})
})

describe('BudgetScenarioComparison loadAdministrationContext()', () => {
	it.each([
		['the active administration', CONTEXT, 'a-2', true],
		['the first option without an active one', { administrations: CONTEXT.administrations }, 'a-1', true],
		['nothing without administrations', {}, '', false],
	])('takes %s', async (name, data, expected, loadsMore) => {
		answerWith(async () => ({ data }))
		const ctx = vm(BudgetScenarioComparison)
		await ctx.loadAdministrationContext()
		expect([ctx.administrationId, ctx.loading, ctx.loadScenarios.mock.calls.length, ctx.loadLedgerGroupNames.mock.calls.length]).toEqual([expected, false, loadsMore ? 1 : 0, loadsMore ? 1 : 0])
	})

	it('reports a failed read and keeps the old administration', async () => {
		answerWith(async () => {
			throw new Error('down')
		})
		const ctx = vm(BudgetScenarioComparison)
		await ctx.loadAdministrationContext()
		expect([ctx.errorMessage, ctx.administrationId, ctx.loading, ctx.loadScenarios.mock.calls.length]).toEqual(['Failed to load administration context', 'old', false, 1])
	})
})
