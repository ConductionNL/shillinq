/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for BudgetTrendChart.vue's own computed properties — the
 * account-type-driven Cumulative-toggle disabling (REQ-BCH-005), the
 * effective-prop resolution the ChartOfAccountsDetail sidebar-tab
 * placement depends on (§1b: that placement hands this component
 * `objectData`, not pre-shaped `id`/`name`/`administrationId` props), and
 * the series/table wiring end to end against a fixed payload. The SFC is
 * compiled by @vitejs/plugin-vue (see vitest.config.js) and its
 * `methods`/`computed` are invoked bound to a fake `this` — no DOM mount,
 * mirroring tests/vitest/bbvLinkerFilterBar.spec.js's own established
 * pattern. `useBudgetChartData` is mocked so these tests exercise pure
 * shaping over a fixed payload, never a real network fetch.
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-005
 */

import { describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

const FIXTURE = {
	months: ['2027-01', '2027-02', '2027-03'],
	accounts: [
		{
			accountNumber: '1000',
			accountType: 'revenue',
			trend: {
				'2027-01': { kind: 'actual', amount: 10000 },
				'2027-02': {
					kind: 'unprojectable',
					reason: 'insufficient-data',
					validSteps: 1,
				},
				'2027-03': {
					kind: 'projected',
					amount: 12000,
					rate: 0.1,
					validSteps: 3,
				},
			},
			cumulative: { '2027-01': 10000, '2027-02': 10000, '2027-03': 22000 },
			budgeted: { '2027-01': 9000, '2027-02': 9000, '2027-03': 9000 },
			budgetedCumulative: {
				'2027-01': 9000,
				'2027-02': 18000,
				'2027-03': 27000,
			},
		},
		{
			accountNumber: '2000',
			accountType: 'assets',
			trend: {
				'2027-01': { kind: 'actual', amount: 50000 },
				'2027-02': { kind: 'actual', amount: 51000 },
				'2027-03': { kind: 'projected', amount: 52000 },
			},
			cumulative: { '2027-01': 50000, '2027-02': 51000, '2027-03': 52000 },
			budgeted: {},
			budgetedCumulative: {},
		},
	],
	ledgerGroups: [
		{
			ledgerGroupKey: 'lg-1',
			name: 'Omzet',
			memberAccountNumbers: ['1000'],
			accountTypes: ['revenue'],
			trend: {
				'2027-01': { kind: 'actual', amount: 10000 },
				'2027-02': { kind: 'unprojectable', reason: 'insufficient-data' },
				'2027-03': { kind: 'projected', amount: 12000, partial: true },
			},
			cumulative: { '2027-01': 10000, '2027-02': 10000, '2027-03': 22000 },
			budgeted: { '2027-01': 9000, '2027-02': 9000, '2027-03': 9000 },
			budgetedCumulative: {
				'2027-01': 9000,
				'2027-02': 18000,
				'2027-03': 27000,
			},
		},
	],
}

vi.mock('../../src/components/budget-charts/useBudgetChartData.js', () => ({
	useBudgetChartData: () => ({
		loading: ref(false),
		data: ref(FIXTURE),
		load: vi.fn(),
	}),
}))

const { default: BudgetTrendChart } =
	await import('../../src/components/budget-charts/BudgetTrendChart.vue')

/**
 * Build a fake `this` bound with every computed property BudgetTrendChart
 * declares, resolved in DEPENDENCY ORDER (a computed that reads another
 * computed calls it explicitly, mirroring how Vue itself would resolve
 * them) — no DOM mount, matching bbvLinkerFilterBar.spec.js's own pattern.
 *
 * @param {object} props Prop overrides.
 * @return {object} The bound context.
 */
function context(props = {}) {
	const ctx = {
		scope: 'account',
		id: '1000',
		name: 'Omzet',
		administrationId: 'adm-1',
		range: { from: '2027-01', to: '2027-03' },
		annualBudgetId: null,
		objectData: null,
		mode: 'trend',
		showTable: false,
		...props,
	}

	for (const [computedName, fn] of Object.entries(BudgetTrendChart.computed)) {
		Object.defineProperty(ctx, computedName, {
			get: () => fn.call(ctx),
			configurable: true,
		})
	}
	for (const [name, fn] of Object.entries(BudgetTrendChart.methods)) {
		ctx[name] = fn.bind(ctx)
	}

	return ctx
}

describe('BudgetTrendChart — effective-prop resolution (§1b sidebar-tab placement)', () => {
	it('uses the direct id/name/administrationId props when supplied (BudgetGrid placement)', () => {
		const ctx = context()

		expect(ctx.effectiveId).toBe('1000')
		expect(ctx.effectiveAdministrationId).toBe('adm-1')
		expect(ctx.isReady).toBe(true)
	})

	it('falls back to objectData when id/name/administrationId are not supplied (sidebar-tab placement)', () => {
		const ctx = context({
			id: null,
			name: null,
			administrationId: null,
			objectData: {
				accountNumber: '1000',
				name: 'Kas',
				administrationId: 'adm-2',
			},
		})

		expect(ctx.effectiveId).toBe('1000')
		expect(ctx.effectiveName).toBe('Kas')
		expect(ctx.effectiveAdministrationId).toBe('adm-2')
		expect(ctx.isReady).toBe(true)
	})

	it('is not ready while objectData has not loaded yet — no premature fetch', () => {
		const ctx = context({
			id: null,
			name: null,
			administrationId: null,
			objectData: null,
		})

		expect(ctx.isReady).toBe(false)
		expect(ctx.chartData).toBeNull()
	})

	it('defaults the range when none is supplied', () => {
		const ctx = context({ range: null })

		expect(ctx.effectiveRange).toHaveProperty('from')
		expect(ctx.effectiveRange).toHaveProperty('to')
	})
})

describe('BudgetTrendChart — Cumulative toggle disabling (REQ-BCH-005)', () => {
	it('is disabled for an all-stock account (assets)', () => {
		const ctx = context({ id: '2000' })

		expect(ctx.accountTypes).toEqual(['assets'])
		expect(ctx.cumulativeDisabled).toBe(true)
	})

	it('is enabled for a flow account (revenue)', () => {
		const ctx = context({ id: '1000' })

		expect(ctx.cumulativeDisabled).toBe(false)
	})

	it('setMode("cumulative") is a no-op when disabled', () => {
		const ctx = context({ id: '2000' })

		ctx.setMode('cumulative')

		expect(ctx.mode).toBe('trend')
	})

	it('setMode("cumulative") switches the mode when enabled', () => {
		const ctx = context({ id: '1000' })

		ctx.setMode('cumulative')

		expect(ctx.mode).toBe('cumulative')
	})

	it('effectiveMode falls back to trend even if `mode` is somehow cumulative while disabled', () => {
		const ctx = context({ id: '2000', mode: 'cumulative' })

		expect(ctx.effectiveMode).toBe('trend')
	})
})

describe('BudgetTrendChart — series shaping end to end against a fixed payload', () => {
	it('splits the account trend into Actual/Projected series with the unprojectable month as a gap', () => {
		const ctx = context({ id: '1000' })

		expect(ctx.actualPoints).toEqual([100, null, null])
		expect(ctx.projectedPoints).toEqual([null, null, 120])
		expect(ctx.unprojectableMonthsList).toEqual(['2027-02'])
	})

	it('the Begroot series is a real, non-null flat series', () => {
		const ctx = context({ id: '1000' })

		expect(ctx.budgetedPoints).toEqual([90, 90, 90])
	})

	it('switches to the cumulative series when toggled', () => {
		const ctx = context({ id: '1000', mode: 'cumulative' })

		expect(ctx.actualPoints[0]).toBe(100)
		expect(ctx.projectedPoints[2]).toBe(220)
		expect(ctx.budgetedPoints).toEqual([90, 180, 270])
	})

	it('the accessible name mentions the scoped entity, not a generic label', () => {
		const ctx = context({ scope: 'ledgerGroup', id: 'lg-1', name: 'Personeel' })

		expect(ctx.accessibleLabel).toContain('Personeel')
	})

	it('a LedgerGroup month carries the partial tag through to the table', () => {
		const ctx = context({ scope: 'ledgerGroup', id: 'lg-1', name: 'Omzet' })

		expect(ctx.isPartialMonth('2027-03')).toBe(true)
		expect(ctx.isPartialMonth('2027-01')).toBe(false)
	})

	it('the Projected table cell reads "Cannot project yet" for the unprojectable month, never blank or €0', () => {
		const ctx = context({ id: '1000' })

		expect(ctx.projectedCellText('2027-02')).toBe('Cannot project yet')
	})
})
