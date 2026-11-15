/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The contract leaf panel rendered from its real template: the loading, error
 * and empty states, each contract row with its title, status, counterparty,
 * term, remaining value and the incomplete-cost notice, and the parts a carded
 * or summarised placement leaves to the host.
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-005)
 * @spec openspec/changes/fees-payments-and-the-contract-register/specs/fees-payments-and-the-contract-register/spec.md (REQ-FPCR-006)
 */

import { describe, expect, it } from 'vitest'
import { renderTemplate, standIn } from './helpers/renderTemplate.js'

const { default: Panel } = await import('../../src/integrations/ShillinqContractPanel.vue')

const components = { NcLoadingIcon: standIn('NcLoadingIcon') }

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<!--[\s\S]*?-->/g, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

/**
 * Euros as the panel writes them.
 *
 * @param {number} amount The amount.
 * @return {string} The text.
 */
const eur = (amount) => text(new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount))

/**
 * The HTML of one contract row.
 *
 * @param {string} html The panel HTML.
 * @param {string} id The contract id.
 * @return {string} The row HTML.
 */
function row(html, id) {
	const start = html.indexOf(`data-testid="shillinq-contract-${id}"`)
	return '<li ' + html.slice(start, html.indexOf('</li>', start))
}

/**
 * Render the panel for a state.
 *
 * @param {object} data Preset data.
 * @param {object} props Props.
 * @return {Promise<string>} The HTML.
 */
const render = (data, props = {}) => renderTemplate(Panel, { props, data, components })

const CONTRACTS = [
	{
		id: 'k1',
		title: 'Cleaning 2026',
		status: 'active',
		needsAttention: true,
		counterpartyReference: 'Schoon BV',
		startDate: '2026-01-01T00:00:00Z',
		endDate: '2026-12-31',
		remainingValue: 1500,
		incurredCostComplete: false,
	},
	{ id: 'k2', contractNumber: 'C-0042', status: 'draft', startDate: '2026-03-01' },
	{ id: 'k3', status: 'ended', endDate: '2025-06-30', remainingValue: 0, currency: 'EUR', incurredCostComplete: true },
]

describe('ShillinqContractPanel template', () => {
	it('shows only a loading icon while reading', async () => {
		const html = await render({ loading: true, error: 'ignored', contracts: CONTRACTS })

		expect(html).toContain('data-name="NcLoadingIcon"')
		expect(html).not.toContain('role="alert"')
		expect(html).not.toContain('shillinq-contract-k1')
	})

	it('shows the read failure as an alert', async () => {
		const html = await render({ error: 'The contract could not be read.' })

		expect(html).toContain('role="alert"')
		expect(text(html)).toBe('The contract could not be read.')
		expect(html).not.toContain('data-name="NcLoadingIcon"')
	})

	it('says the record has no contract, unless a host draws the card', async () => {
		const html = await render({})
		expect(html).toContain('data-testid="shillinq-contract-empty"')
		expect(text(html)).toBe('This record is not handled under a contract.')

		const carded = await render({}, { onHeader: () => {} })
		expect(carded).toContain('data-testid="shillinq-contract-panel"')
		expect(carded).not.toContain('shillinq-contract-empty')
		expect(text(carded)).toBe('')
	})

	it('draws nothing at all as a summary', async () => {
		const html = await render({ contracts: CONTRACTS, loading: true }, { onSummary: () => {} })

		expect(html).not.toContain('shillinq-contract-panel')
		expect(text(html)).toBe('')
	})

	it('lists each contract with its title, status, counterparty, term and remaining value', async () => {
		const html = await render({ contracts: CONTRACTS })

		const first = row(html, 'k1')
		expect(text(first)).toBe(`Cleaning 2026 active With Schoon BV Runs from 2026-01-01 until 2026-12-31 Remaining value ${eur(1500)} ${text(Panel.computed.incompleteLabel.call({}))}`)
		expect(first).toContain('shillinq-leaf__state--attention')
		expect(first).toContain('role="status"')

		const second = row(html, 'k2')
		expect(text(second)).toBe('C-0042 draft Runs from 2026-03-01 The remaining value has not been rolled up yet.')
		expect(second).not.toContain('shillinq-leaf__state--attention')

		const third = row(html, 'k3')
		expect(text(third)).toBe(`Contract ended Runs until 2025-06-30 Remaining value ${eur(0)}`)
		expect(third).not.toContain('role="status"')
		expect(html).not.toContain('shillinq-contract-empty')
	})

	it('still lists the contracts when a host draws the card', async () => {
		const html = await render({ contracts: CONTRACTS.slice(1, 2) }, { onHeader: () => {} })

		expect(html).toContain('data-testid="shillinq-contract-k2"')
		expect(text(html)).toBe('C-0042 draft Runs from 2026-03-01 The remaining value has not been rolled up yet.')
	})
})
