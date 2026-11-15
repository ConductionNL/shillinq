/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins PeriodCloseDetail's `stateLabel` computed: the translated label for
 * each known FiscalPeriod state, `open` when there is no state, and the raw
 * state for any other value.
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-9
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import PeriodCloseDetail from '../../src/components/period-close/PeriodCloseDetail.vue'

/**
 * Evaluate stateLabel against a fake instance.
 *
 * @param {object|null} period The period on the instance.
 * @return {*} The computed value.
 */
function stateLabel(period) {
	return PeriodCloseDetail.computed.stateLabel.call({ period })
}

describe('PeriodCloseDetail stateLabel', () => {
	beforeEach(() => {
		globalThis.t = (app, text) => `${app}:${text}`
	})

	afterEach(() => {
		delete globalThis.t
	})

	it.each([
		['open', 'shillinq:Open'],
		['closing', 'shillinq:Closing'],
		['closed', 'shillinq:Closed'],
		['audit-locked', 'shillinq:Audit locked'],
	])('labels the %s state', (state, label) => {
		expect(stateLabel({ state })).toBe(label)
	})

	it('treats a missing period or state as open', () => {
		expect(stateLabel(null)).toBe('shillinq:Open')
		expect(stateLabel({})).toBe('shillinq:Open')
		expect(stateLabel({ state: '' })).toBe('shillinq:Open')
	})

	it('returns any other state as it is', () => {
		expect(stateLabel({ state: 'archived' })).toBe('archived')
		expect(stateLabel({ state: 'Open' })).toBe('Open')
		expect(stateLabel({ state: 'constructor' })).toBe('constructor')
		expect(stateLabel({ state: 7 })).toBe(7)
	})
})
