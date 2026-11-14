/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * public-sector-quarterly-returns 1.2: the BCF claim page shows the claim
 * quarter, the computed compensable VAT and the breakdown per account, and
 * offers the compute action (REQ-BCF-010).
 *
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-bcf-vat-compensation/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
)
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(
			ROOT,
			'lib/Settings/register.d/bookkeeping-bcf-vat-compensation.json',
		),
		'utf8',
	),
)

describe('BCF claim page', () => {
	const detail = manifest.pages.find((p) => p.id === 'BcfClaimDetail')
	const schema = fragment.components.schemas.BcfClaim

	it('shows the quarter, the computed total and the breakdown', () => {
		const keys = detail.config.fields.map((f) => f.key)
		expect(keys).toEqual(
			expect.arrayContaining([
				'claimQuarter',
				'totalCompensableAmount',
				'breakdown',
			]),
		)
		for (const key of ['claimQuarter', 'totalCompensableAmount', 'breakdown']) {
			expect(schema.properties[key], key).toBeDefined()
		}
	})

	it('offers compute on a draft claim through the declared action', () => {
		const compute = schema['x-openregister-lifecycle'].transitions.compute
		expect(compute.from).toBe('draft')
		expect(compute.to).toBe('draft')
		expect(compute.actions[0].action).toBe(
			'OCA\\Shillinq\\Lifecycle\\Action\\ComputeBcfClaimAction',
		)
		expect(
			fs.existsSync(
				path.join(ROOT, 'lib/Lifecycle/Action/ComputeBcfClaimAction.php'),
			),
		).toBe(true)
	})
})
