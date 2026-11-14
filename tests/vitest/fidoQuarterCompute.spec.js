/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * public-sector-quarterly-returns 2.2: compute on the quarterly Fido report,
 * and the treasury dashboard reads the computed cash limit and interest risk
 * norm of the latest year instead of a fixed zero (REQ-FDO-011).
 *
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-wet-fido-treasury/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const fragment = read('src/manifest.d/bookkeeping-wet-fido-treasury.json')
const register = read('lib/Settings/register.d/bookkeeping-wet-fido-treasury.json')

describe('Fido quarter on the treasury dashboard', () => {
	const dashboard = fragment.pages.find((p) => p.id === 'TreasuryDashboard')
	const widget = (id) => dashboard.config.widgets.find((w) => w.id === id)

	it.each([
		[
			'kasgeld-headroom',
			'KasgeldLimiet',
			['calculatedCeiling', 'currentExposure', 'headroom', 'status'],
		],
		[
			'rente-risico-headroom',
			'RenteRisicoNorm',
			['calculatedCeiling', 'headroomPerYear', 'status'],
		],
	])('%s reads the latest %s record', (id, schema, keys) => {
		const w = widget(id)
		expect(w.type).toBe('table')
		expect(w.dataSource.schema).toBe(schema)
		expect(w.dataSource.sort).toBe('-auditYear')
		expect(w.dataSource.limit).toBe(1)
		const columns = w.columns.map((c) => c.key)
		expect(columns).toEqual(expect.arrayContaining(keys))
		for (const key of columns) {
			expect(
				register.components.schemas[schema].properties[key],
				key,
			).toBeDefined()
		}
	})

	it('offers compute on a draft quarterly report', () => {
		const compute =
			register.components.schemas.QuartaalrapportageFido[
				'x-openregister-lifecycle'
			].transitions.compute
		expect(compute.from).toBe('draft')
		expect(compute.actions[0].action).toBe(
			'OCA\\Shillinq\\Lifecycle\\Action\\ComputeFidoQuarterAction',
		)
	})
})
