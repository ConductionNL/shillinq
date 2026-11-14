/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins the joint arrangement allocation run pages
 * (public-sector-task-field-allocation REQ-GRC-007 / REQ-GRC-008) against the
 * register: the menu entry next to the allocation keys reaches the index, the
 * index opens the detail, every action on the detail page fires a transition
 * GRAllocationRun declares and is shown only in the state it leaves, and the
 * widgets show fields the schema has.
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const root = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'))

const manifest = read('src/manifest.d/public-sector-task-field-allocation.json')
const register = read('lib/Settings/register.d/public-sector-task-field-allocation.json')
const run = register.components.schemas.GRAllocationRun
const page = (id) => manifest.pages.find((p) => p.id === id)

describe('GR allocation run pages', () => {
	it('puts the runs in the Bookkeeping menu right after the allocation keys', () => {
		const base = read('src/manifest.json')
		const bookkeeping = base.menu.find((m) => m.id === 'Bookkeeping')
		const keys = bookkeeping.children.find((c) => c.id === 'GRVerdeelsleutels')
		const consolidated = bookkeeping.children.find((c) => c.id === 'GRConsolidated')

		const entry = manifest.menu.find((m) => m.id === 'Bookkeeping').children.find((c) => c.id === 'GRAllocationRuns')
		expect(entry.route).toBe('GRAllocationRuns')
		expect(entry.order).toBeGreaterThan(keys.order)
		expect(entry.order).toBeLessThan(consolidated.order)
	})

	it('lists the runs and opens one in its detail page', () => {
		const index = page('GRAllocationRuns')
		const detail = page('GRAllocationRunDetail')

		expect(index).toMatchObject({ type: 'index', route: '/gr/verdeelruns' })
		expect(index.config.schema).toBe('GRAllocationRun')
		expect(index.config.detailRoute).toBe(detail.id)
		expect(detail).toMatchObject({ type: 'detail', route: '/gr/verdeelruns/:id' })
		expect(detail.config.indexRoute).toBe(index.id)
		for (const column of index.config.columns) {
			expect(run.properties).toHaveProperty(column.key)
		}
	})

	it('offers Propose, Confirm and Invoice participants as the declared transitions, each in the state it leaves', () => {
		const actions = page('GRAllocationRunDetail').config.actions
		const transitions = run['x-openregister-lifecycle'].transitions

		expect(actions.map((a) => a.transition)).toEqual(['propose', 'confirm', 'invoice'])
		for (const action of actions) {
			expect(action.type).toBe('lifecycle-transition')
			expect(transitions).toHaveProperty(action.transition)
			expect(action.visibleWhen).toEqual({ field: 'state', op: 'eq', value: transitions[action.transition].from })
		}
	})

	it('has the propose action run the allocation executor with the propose step', () => {
		const propose = page('GRAllocationRunDetail').config.actions.find((a) => a.id === 'propose-allocation')
		const declared = run['x-openregister-lifecycle'].transitions[propose.transition]

		expect(declared.actions).toEqual([
			expect.objectContaining({
				action: 'OCA\\Shillinq\\Lifecycle\\Action\\JointArrangementAllocationAction',
				actionParameters: { step: 'propose' },
			}),
		])
	})

	it('shows only fields the schema has', () => {
		for (const widget of page('GRAllocationRunDetail').config.widgets) {
			for (const field of widget.content.include) {
				expect(run.properties).toHaveProperty(field)
			}
		}
	})
})
