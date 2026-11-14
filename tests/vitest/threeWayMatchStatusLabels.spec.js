/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The three-way match list and exception panel name every match status,
 * including a delivery outside the date tolerance.
 *
 * @spec openspec/changes/three-way-match-delivery-date-exception/specs/bookkeeping-three-way-matching-engine/spec.md
 */

import { describe, expect, it } from 'vitest'
import ThreeWayMatchExceptionPanel, {
	EXCEPTION_STATUSES,
} from '../../src/components/three-way-match/ThreeWayMatchExceptionPanel.vue'
import ThreeWayMatchIndex from '../../src/components/three-way-match/ThreeWayMatchIndex.vue'

const vm = { t: (app, text) => text }

describe('three-way match status labels', () => {
	it('offers the delivery date exception as a list filter', () => {
		const options = ThreeWayMatchIndex.computed.statusOptions.call(vm)
		expect(options.find((option) => option.value === 'exception_date')).toEqual({
			value: 'exception_date',
			label: 'Delivery date exception',
		})
	})

	it('names the delivery date exception in the list', () => {
		expect(
			ThreeWayMatchIndex.methods.statusLabel.call(vm, 'exception_date'),
		).toBe('Delivery date exception')
	})

	it('treats the delivery date exception as an exception in the panel', () => {
		expect(EXCEPTION_STATUSES).toContain('exception_date')
		expect(
			ThreeWayMatchExceptionPanel.methods.statusLabel.call(
				vm,
				'exception_date',
			),
		).toBe('Delivery date exception')
	})
})
