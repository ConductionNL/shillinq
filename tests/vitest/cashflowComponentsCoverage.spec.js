/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * CashflowDashboard and ScenarioCreator rendered server-side with their
 * props: the crisis banner over the first four weeks only, the scenario
 * switcher options, the week detail, the export-pdf and create-scenario
 * payloads, and the adjustment fields each adjustment type shows.
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-25
 */

import { describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import CashflowDashboard from '../../src/components/cashflow/CashflowDashboard.vue'
import ScenarioCreator from '../../src/components/cashflow/ScenarioCreator.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
}))

/**
 * Render a component with props, optionally laying state over its data.
 *
 * @param {object} component The component.
 * @param {object} props Its props, listeners included.
 * @param {object} [state] Data to lay over its own.
 * @return {Promise<{html: string, vm: object}>}
 */
async function render(component, props, state = {}) {
	let vm = null
	const app = createSSRApp(
		{
			...component,
			mixins: [{ created() { vm = this; Object.assign(this.$data, state) } }],
		},
		props,
	)
	app.config.warnHandler = () => {}
	const html = await renderToString(app)
	return { html, vm }
}

/**
 * A forecast week.
 *
 * @param {number} weekNumber The week.
 * @param {number|string} closingBalance Its closing balance.
 * @return {object} The week.
 */
function week(weekNumber, closingBalance) {
	return {
		weekNumber,
		closingBalance,
		inflows_ar_geprognosticeerd: 100 * weekNumber,
		outflows_ap_geprognosticeerd: 40 * weekNumber,
		netMovement: 60 * weekNumber,
	}
}

describe('CashflowDashboard', () => {
	it('raises the crisis banner when one of the first four weeks closes negative', async () => {
		const weeks = [week(1, 500), week(2, 200), week(3, -10), week(4, 50)]

		const { html, vm } = await render(CashflowDashboard, { horizon: { horizonId: 'h1' }, weeks })

		expect(vm.crisisActive).toBe(true)
		expect(html).toContain('role="alert"')
		expect(html).toContain('CRISIS ACTIVE')
	})

	it('ignores a negative week after the fourth and a weeks value that is not a list', async () => {
		const later = await render(CashflowDashboard, {
			horizon: { horizonId: 'h1' },
			weeks: [week(1, 1), week(2, 1), week(3, 1), week(4, '0'), week(5, -900)],
		})
		expect(later.vm.crisisActive).toBe(false)
		expect(later.html).not.toContain('CRISIS ACTIVE')

		const broken = await render(CashflowDashboard, { horizon: { horizonId: 'h1' }, weeks: 'none' })
		expect(broken.vm.crisisActive).toBe(false)
	})

	it('lists the baseline and every scenario, and shows the selected week', async () => {
		const { html } = await render(
			CashflowDashboard,
			{
				horizon: { horizonId: 'h1' },
				scenarios: [{ scenarioId: 's1', name: 'Late payer' }, { scenarioId: 's2', name: 'New deal' }],
			},
			{ selectedWeek: week(3, 750) },
		)

		expect(html).toContain('<option value="baseline"')
		expect(html).toContain('Late payer')
		expect(html).toContain('<option value="s2"')
		expect(html).toContain('cashflow-dashboard__week-detail')
		expect(html).toContain('Inflows AR')
		expect(html).toContain('300')
		expect(html).toContain('750')
	})

	it('emits export-pdf with the horizon and the selected scenario', async () => {
		const onExportPdf = vi.fn()
		const { html, vm } = await render(CashflowDashboard, { horizon: { horizonId: 'h7' }, onExportPdf }, { selectedScenarioId: 's2' })

		expect(html).not.toContain('cashflow-dashboard__week-detail')
		vm.exportPdf()

		expect(onExportPdf).toHaveBeenCalledWith({ horizonId: 'h7', scenarioId: 's2' })
	})
})

describe('ScenarioCreator', () => {
	const props = { horizonId: 'h1', administrationId: 'adm-1' }

	it('starts empty, bound to its horizon and administration', async () => {
		const { html, vm } = await render(ScenarioCreator, props)

		expect(vm.scenario).toEqual({ horizonId: 'h1', name: '', description: '', aanpassingen: [], administrationId: 'adm-1' })
		expect(html).toContain('Create scenario')
		expect(html).not.toContain('scenario-creator__adjustment"')
	})

	it('adds an AR override by default and removes the one asked for', async () => {
		const { vm } = await render(ScenarioCreator, props)

		vm.addAdjustment()
		vm.addAdjustment()
		vm.scenario.aanpassingen[1].type = 'NEW_REVENUE'
		vm.removeAdjustment(0)

		expect(vm.scenario.aanpassingen).toEqual([{ type: 'NEW_REVENUE' }])
	})

	it('shows the fields of each adjustment type', async () => {
		const aanpassingen = [
			{ type: 'AR_PROJECTION_OVERRIDE' },
			{ type: 'RECURRING_COST_ADJUSTMENT' },
			{ type: 'NEW_REVENUE' },
			{ type: 'BUFFER_POLICY_OVERRIDE' },
		]

		const { html } = await render(ScenarioCreator, props, {
			scenario: { horizonId: 'h1', name: 'X', description: '', aanpassingen, administrationId: 'adm-1' },
		})

		for (const label of ['AR invoice ID', 'Week shift', 'Probability (0-1)', 'Recurring ID', 'Adjustment direction', 'Deal name', 'Amount EUR', 'Expected week', 'Buffer EUR']) {
			expect(html).toContain(`aria-label="${label}"`)
		}
		expect(html.match(/scenario-creator__adjustment"/g)).toHaveLength(4)
	})

	it('shows only the AR override fields for an AR override', async () => {
		const { html } = await render(ScenarioCreator, props, {
			scenario: { horizonId: 'h1', name: '', description: '', aanpassingen: [{ type: 'AR_PROJECTION_OVERRIDE' }], administrationId: 'adm-1' },
		})

		expect(html).toContain('aria-label="Week shift"')
		expect(html).not.toContain('aria-label="Deal name"')
		expect(html).not.toContain('aria-label="Buffer EUR"')
	})

	it('emits create-scenario with the scenario and a creation time', async () => {
		const onCreateScenario = vi.fn()
		const { vm } = await render(ScenarioCreator, { ...props, onCreateScenario })
		vm.scenario.name = 'Acme pays late'
		vm.addAdjustment()

		vm.handleSubmit()

		const [payload] = onCreateScenario.mock.calls[0]
		expect(payload).toMatchObject({ horizonId: 'h1', administrationId: 'adm-1', name: 'Acme pays late', aanpassingen: [{ type: 'AR_PROJECTION_OVERRIDE' }] })
		expect(new Date(payload.createdAt).toISOString()).toBe(payload.createdAt)
	})
})
