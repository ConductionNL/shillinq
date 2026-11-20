/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Every cash flow figure opens its ledger lines (reporting-cash-flow-statement
 * task 3.1, REQ-RCF-003).
 *
 * The statement page's section tables route a row click to `CashFlowLines`
 * with the row's `linesKey`; that page reads the lines endpoint with the key
 * from its route and opens each line's transaction. This test holds the
 * manifest fragment, the route table and the controller to that contract, so
 * a renamed page, route parameter or endpoint fails here instead of on a
 * live instance.
 *
 * @spec openspec/changes/reporting-cash-flow-statement/tasks.md#task-3.1
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '..', '..')
const fragment = JSON.parse(fs.readFileSync(path.join(root, 'src/manifest.d/reporting-cash-flow-statement.json'), 'utf8'))
const routes = fs.readFileSync(path.join(root, 'appinfo/routes.php'), 'utf8')
const calculator = fs.readFileSync(path.join(root, 'lib/Reporting/CashFlowCalculator.php'), 'utf8')
const page = (id) => fragment.pages.find((candidate) => candidate.id === id)

describe('cash flow figure links (REQ-RCF-003)', () => {
	it('routes every section row to the lines page by its lines key', () => {
		const sections = page('FinancialStatementCashFlow').config.widgets
			.filter((widget) => widget.content.endpointSource.responsePath.endsWith('.rows'))

		expect(sections.map((widget) => widget.id)).toEqual([
			'cash-flow-operating',
			'cash-flow-investing',
			'cash-flow-financing',
			'cash-flow-unclassified',
		])
		for (const widget of sections) {
			expect([widget.id, widget.content.rowRoute, widget.content.rowIdField])
				.toEqual([widget.id, 'CashFlowLines', 'linesKey'])
		}
	})

	it('has the lines page read the key from its own route', () => {
		const lines = page('CashFlowLines')

		expect(lines.route).toBe('/reports/cash-flow/lines/:id')
		const [table] = lines.config.widgets
		expect(table.content.endpointSource).toEqual({
			url: '/apps/shillinq/api/statements/cash-flow/lines',
			params: { key: '@route.id' },
			responsePath: 'lines',
		})
		expect([table.content.rowRoute, table.content.rowIdField]).toEqual(['GeneralLedgerDetail', 'transactionId'])
		expect(lines.config.layout.map((cell) => cell.widgetId)).toEqual([table.id])
	})

	it('serves that endpoint from the controller that checks the administration', () => {
		expect(routes).toMatch(/'financialStatement#cashFlowLines', 'url' => '\/api\/statements\/cash-flow\/lines', 'verb' => 'GET'/)
		expect(calculator).toMatch(/'linesKey' => self::linesKey\(scope: \$account/)
	})
})
