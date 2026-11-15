/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The administration switcher and the multi-year budget, rendered from their
 * real templates for a given state: when the switcher shows at all, which
 * administration is marked active and which can be chosen; what the
 * multi-year section shows while loading, without budgets, and with a table
 * of ledger groups per fiscal year.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-13
 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/specs/budget-grid-view/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { renderTemplate, standIn } from './helpers/renderTemplate.js'

vi.mock('../../src/api/administrationApi.js', () => ({ fetchAdministrationContext: vi.fn(), switchAdministration: vi.fn() }))
vi.mock('../../src/utils/budgetEditingApi.js', () => ({ loadMultiYear: vi.fn(), refusal: vi.fn(), startNextYear: vi.fn() }))

globalThis.t = (_app, value, vars = {}) => value.replace(/{(\w+)}/g, (m, key) => (key in vars ? String(vars[key]) : m))

const { default: AdministratieSwitcher } = await import('../../src/components/AdministratieSwitcher.vue')
const { default: MultiYearBudget } = await import('../../src/components/MultiYearBudget.vue')

const switcherParts = Object.fromEntries(['NcActions', 'NcActionButton', 'NcActionCaption', 'CheckBold', 'OfficeBuilding', 'OfficeBuildingOutline'].map((name) => [name, standIn(name)]))
const multiYearParts = Object.fromEntries(['NcButton', 'NcEmptyContent', 'NcLoadingIcon'].map((name) => [name, standIn(name)]))

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

const ADMINISTRATIONS = [
	{ administrationId: 'a1', administrationCode: '001', name: 'Gemeente Noord' },
	{ administrationId: 'a2', name: 'Stichting Zuid' },
	{ administrationId: 'a3', administrationCode: '003' },
	{ administrationId: 'a4' },
]

describe('AdministratieSwitcher template', () => {
	const render = (data) => renderTemplate(AdministratieSwitcher, { data, components: switcherParts })

	it('stays hidden with one administration or none', async () => {
		expect(text(await render({ administrations: [] }))).toBe('')
		expect(await render({ administrations: ADMINISTRATIONS.slice(0, 1), activeId: 'a1' })).not.toContain('administratie-switcher')
	})

	it('lists every administration by code and name, marks the active one and disables choosing it', async () => {
		const html = await render({ administrations: ADMINISTRATIONS, activeId: 'a2' })

		expect(html).toContain('menuName="Stichting Zuid"')
		expect(html).toContain('name="4 accessible administrations"')
		expect(html).toContain('aria-busy="false"')
		const items = html.split('<cn-stub data-name="NcActionButton"').slice(1)
			.map((part) => [text('<x' + part), part.slice(0, part.indexOf('>')).includes('data-disabled="true"'), part.includes('data-name="CheckBold"')])
		expect(items).toEqual([
			['001 — Gemeente Noord', false, false],
			['Stichting Zuid', true, true],
			['003', false, false],
			['a4', false, false],
		])
	})

	it('names the menu generically without an active administration, and shows a failure', async () => {
		const html = await render({ administrations: ADMINISTRATIONS.slice(0, 2), activeId: null, loading: true, errorMessage: 'Administration not found' })

		expect(html).toContain('menuName="Switch administration"')
		expect(html).toContain('aria-busy="true"')
		expect(html.match(/data-name="NcActionButton" data-disabled="true"/g)).toHaveLength(2)
		expect(text(html)).toContain('Administration not found')
	})
})

describe('MultiYearBudget template', () => {
	const render = (data) => renderTemplate(MultiYearBudget, { props: { administrationId: 'adm' }, data, components: multiYearParts })
	const startButton = (html) => {
		const tag = html.match(/<cn-stub data-name="NcButton"[^>]*>/)[0]
		expect(text(html.slice(html.indexOf(tag)))).toMatch(/^Start next year/)
		return tag.includes('data-disabled="true"') ? 'disabled' : 'enabled'
	}
	const euro = (cents) => new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(cents / 100)

	it('shows a loading icon, then an empty state without budgets, with Start disabled', async () => {
		const loading = await render({ loading: true })
		expect(loading).toContain('data-name="NcLoadingIcon"')
		expect(loading).not.toContain('multi-year-table')

		const empty = await render({ loading: false })
		expect(empty).toContain('name="No budgets in these years"')
		expect(startButton(empty)).toBe('disabled')
	})

	it('offers each budget to start from and tables every ledger group per year', async () => {
		const html = await render({
			loading: false,
			sourceId: 'b-2026',
			message: 'Draft budget 2027 started.',
			budgets: [{ annualBudgetId: 'b-2026', fiscalYear: 2026, name: 'Begroting 2026' }, { annualBudgetId: 'b-2027', fiscalYear: 2027, name: 'Begroting 2027' }],
			years: [{ fiscalYear: 2026, state: 'active' }, { fiscalYear: 2027, state: 'draft' }, { fiscalYear: 2028, state: 'proposed' }],
			rows: [
				{ ledgerGroupId: 'g1', name: 'Personeel', depth: 0, amounts: { 2026: 1000000, 2027: 1030000 } },
				{ ledgerGroupId: 'g2', name: 'Salarissen', depth: 1, amounts: {} },
			],
		})
		const shown = text(html)

		expect(shown).toContain('2026: Begroting 2026 2027: Begroting 2027')
		expect(shown).toContain('Draft budget 2027 started.')
		expect(shown).toContain('Ledger group 2026 active 2027 draft 2028 proposed')
		expect(shown).toContain(`Personeel ${euro(1000000)} ${euro(1030000)} ${euro(0)}`)
		expect(shown).toContain(`Salarissen ${euro(0)} ${euro(0)} ${euro(0)}`)
		expect(html).toContain('padding-inline-start:28px')
		expect(startButton(html)).toBe('enabled')
		expect(html.match(/data-testid="multi-year-row"/g)).toHaveLength(2)
	})
})
