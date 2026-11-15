/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The fee schedule admin page: a finance officer sees every fee with its
 * legal basis beside the amount (fees-payments-and-the-contract-register
 * 1.3, leges-at-intake 1.2), reachable from the Government menu.
 *
 * @spec openspec/changes/fees-payments-and-the-contract-register/tasks.md
 * @spec openspec/changes/leges-at-intake/design.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.d/fee-schedules.json'), 'utf8'),
)
const register = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'lib/Settings/register.d/leges-at-intake.json'),
		'utf8',
	),
)

describe('Fee schedule page', () => {
	const index = fragment.pages.find((p) => p.id === 'FeeSchedules')

	it('lists FeeSchedule with the basis right after the amount', () => {
		expect(index.type).toBe('index')
		expect(index.config.schema).toBe('FeeSchedule')
		const keys = index.config.columns.map((c) => c.key)
		const amount = keys.indexOf('amount')
		expect(amount).toBeGreaterThan(-1)
		expect(keys.slice(amount + 1, amount + 4)).toEqual([
			'legalBasis.regulation',
			'legalBasis.article',
			'legalBasis.effectiveDate',
		])
	})

	it('reads only properties the schema has', () => {
		const props = register.components.schemas.FeeSchedule.properties
		for (const { key } of index.config.columns) {
			const [head, tail] = key.split('.')
			expect(props[head], key).toBeDefined()
			if (tail) {
				expect(props[head].properties[tail], key).toBeDefined()
			}
		}
	})

	it('is reachable from the Government menu', () => {
		const gov = fragment.menu.find((m) => m.id === 'Overheid')
		expect(gov.children.map((c) => c.route)).toContain('FeeSchedules')
		expect(
			fragment.pages.find((p) => p.id === index.config.detailRoute).type,
		).toBe('detail')
	})
})
