/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Board ShBank: the Bank and payments page. The board navigation opens it,
 * the Banking & Cashflow landing page keeps its own route and menu group,
 * the accounts read the cash position endpoint, the board tabs are a tabs
 * widget with a count each, and the actions reach what they name (the statement wizard in the registry, the bank connection handler).
 *
 * @spec openspec/specs/shillinq-bank-statement-wizard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (f) => fs.readFileSync(path.join(ROOT, f), 'utf8')
const fragment = JSON.parse(read('src/manifest.d/bank-and-payments-board.json'))
const nav = JSON.parse(read('src/manifest.d/nav-board-sections.json'))
const shell = JSON.parse(read('src/manifest.d.shell.json'))
const registry = read('src/registry.js')
const actions = read('src/manifestActions.js')
const routes = read('appinfo/routes.php')
const page = fragment.pages[0]
const widgets = Object.fromEntries(page.config.widgets.map((w) => [w.id, w]))

describe('Bank and payments board', () => {
	it('is the page the board navigation opens', () => {
		expect(page.id).toBe('BankAndPayments')
		expect(page.route).toBe('/bank')
		expect(nav.menu.find((m) => m.id === 'NavBankAndPayments').route).toBe(
			'BankAndPayments',
		)
		const slim = shell.fragments.flatMap((f) => f.pages || [])
		expect(slim.some((p) => p.id === 'BankAndPayments')).toBe(true)
	})

	it('leaves the Banking & Cashflow landing page in its menu group', () => {
		const entries = []
		const walk = (items) => {
			for (const item of items || []) {
				entries.push(item)
				walk(item.children)
			}
		}
		shell.fragments.forEach((f) => walk(f.menu))
		const group = entries.find((m) => m.id === 'BankingCashflow')
		expect(group.route).toBe('BankingCashflowOverview')
	})

	it('opens the statement wizard and the bank connection from its header', () => {
		const [importStatement, connect] = page.config.headerActions
		expect(importStatement.type).toBe('open-modal')
		expect(importStatement.target).toBe('BankStatementWizard')
		expect(registry).toMatch(
			/BankStatementWizard: \{ kind: 'modal', component: BankStatementWizard \}/,
		)
		expect(connect.handler).toBe('openIntegriqConnections')
		expect(actions).toContain('openIntegriqConnections,')
	})

	it('draws the board tabs as a tabs widget with a count on each tab', () => {
		expect(page.config.views).toBeUndefined()
		const tabs = widgets['bank-tabs'].content.tabs
		expect(tabs.map((t) => t.widgetId)).toEqual([
			'bank-accounts',
			'bank-statements',
			'bank-unmatched',
			'bank-fx-rates',
		])
		for (const tab of tabs) {
			expect(widgets[tab.widgetId].type).toBe('object-table')
			expect(tab.countFrom).toBeTruthy()
		}
		const placed = page.config.layout.map((l) => l.widgetId)
		expect(placed).toEqual(['bank-account-cards', 'bank-tabs'])
		expect(page.config.kpiRow.every((id) => widgets[id]?.type === 'stat')).toBe(
			true,
		)
	})

	it('lists the accounts with their ledger balance from the cash position', () => {
		const cards = widgets['bank-account-cards']
		expect(cards.type).toBe('record-list')
		expect(cards.content.endpointSource).toEqual({
			url: '/apps/shillinq/api/v1/cash-position',
			responsePath: 'accounts',
		})
		expect(cards.content.trailing).toBe('{ledgerBalance|currency}')
		expect(cards.content.rowIdField).toBe('bankAccountId')
	})

	it('reads the account balances from the cash position endpoint', () => {
		expect(widgets['bank-accounts'].content.endpointSource).toEqual({
			url: '/apps/shillinq/api/v1/cash-position',
			responsePath: 'accounts',
		})
		expect(routes).toContain("'url' => '/api/v1/cash-position'")
		const keys = widgets['bank-accounts'].content.columns.map((c) => c.key)
		expect(keys).toEqual([
			'accountName',
			'iban',
			'ledgerBalance',
			'bankBalance',
			'bankBalanceDate',
		])
	})
})
