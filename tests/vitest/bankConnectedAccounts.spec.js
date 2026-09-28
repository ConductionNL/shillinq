/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * banking-connected-accounts: the liquidity dashboard reads a real cash
 * position. The "Group cash position" stat was a literal count of 0; it and
 * the per-account table now read /api/v1/cash-position, which must be routed.
 *
 * @spec openspec/changes/banking-connected-accounts/tasks.md#task-4.2
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const treasury = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.d/30-treasury-ihb.json'), 'utf8'),
)
const multiCurrency = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'src/manifest.d/bookkeeping-multi-currency.json'),
		'utf8',
	),
)
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')

/**
 * A page by id.
 *
 * @param {object} fragment The manifest fragment.
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(fragment, id) {
	return fragment.pages.find((p) => p.id === id)
}

describe('group liquidity dashboard', () => {
	const widgets = page(treasury, 'GroupLiquidityDashboard').config.widgets

	it('reads the group cash position from the cash position endpoint', () => {
		const stat = widgets.find((w) => w.id === 'group-cash-position')
		expect(stat.type).toBe('stat')
		expect(stat.content.endpointSource.url).toBe(
			'/apps/shillinq/api/v1/cash-position',
		)
		expect(stat.content.valueField).toBe('total')
		expect(stat.props).toBeUndefined()
	})

	it('lists the cash per bank account from the same endpoint', () => {
		const table = widgets.find((w) => w.id === 'cash-by-account')
		expect(table.content.endpointSource).toEqual({
			url: '/apps/shillinq/api/v1/cash-position',
			responsePath: 'accounts',
		})
		const layout = page(treasury, 'GroupLiquidityDashboard').config.layout
		expect(layout.some((l) => l.widgetId === 'cash-by-account')).toBe(true)
	})

	it('the endpoint is routed', () => {
		expect(routes).toContain("'url' => '/api/v1/cash-position', 'verb' => 'GET'")
	})
})

describe('bank accounts', () => {
	it('show the ledger account and the last sync, and offer to connect a bank', () => {
		const config = page(multiCurrency, 'BankAccounts').config
		const keys = config.columns.map((c) => c.key)
		expect(keys).toContain('ledgerAccountNumber')
		expect(keys).toContain('lastSyncAt')
		expect(
			config.headerActions.find((a) => a.id === 'connect-bank').handler,
		).toBe('openIntegriqConnections')
	})
})
