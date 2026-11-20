/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * budget-scenarios task group 8 (Q-shillinq-1, answered 9 Oct): the budget
 * grid gets a scenario selector. Choosing a scenario asks the grid endpoint
 * for that scenario's figures (optional scenarioId) and shows a
 * "Scenario: X" label; "Base budget" sends no scenarioId.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-011
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { gridRequestParams } from '../../src/views/budgetGridHelpers.js'

const grid = fs.readFileSync(
	path.resolve(__dirname, '../../src/views/BudgetGrid.vue'),
	'utf8',
)

describe('Budget grid scenario selector', () => {
	const state = {
		administrationId: 'adm-1',
		startPeriod: '2027-01',
		endPeriod: '2027-12',
		granularity: 'month',
	}

	it('sends no scenarioId for the base budget', () => {
		expect(gridRequestParams({ ...state, scenarioId: '' })).toEqual(state)
	})

	it('sends the chosen scenarioId', () => {
		expect(gridRequestParams({ ...state, scenarioId: 'sc-krimp' })).toEqual({
			...state,
			scenarioId: 'sc-krimp',
		})
	})

	it('renders a labelled selector and the scenario label', () => {
		expect(grid).toContain('data-testid="budget-grid-scenario"')
		expect(grid).toContain('for="budget-grid-scenario"')
		expect(grid).toContain('data-testid="budget-grid-scenario-label"')
		expect(grid).toContain("'Scenario: {name}'")
		expect(grid).toContain('gridRequestParams(')
	})
})
