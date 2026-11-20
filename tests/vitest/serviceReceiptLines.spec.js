/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * serviceReceiptLines: which order lines are services, and what a confirmed
 * line sends (purchasing-order-dispatch-and-receipt, task 4.2).
 */

import { describe, expect, it } from 'vitest'
import { isServiceLine, linePayload } from '../../src/utils/serviceReceiptLines.js'

const PERIOD = { periodStart: '2026-09-01', periodEnd: '2026-09-30' }

describe('isServiceLine', () => {
	it('takes time and service units as services, whatever the case', () => {
		expect(isServiceLine({ unitOfMeasure: 'HUR' })).toBe(true)
		expect(isServiceLine({ unitOfMeasure: 'day' })).toBe(true)
		expect(isServiceLine({ unitOfMeasure: 'E48' })).toBe(true)
	})

	it('takes goods units and a missing unit as goods', () => {
		expect(isServiceLine({ unitOfMeasure: 'EA' })).toBe(false)
		expect(isServiceLine({})).toBe(false)
		expect(isServiceLine(null)).toBe(false)
	})
})

describe('linePayload', () => {
	it('sends 25 of 40 hours as a confirmed quantity for the period', () => {
		expect(linePayload({ poLineId: 'pol-1', mode: 'quantityConfirmed', value: '25' }, PERIOD, 'adm-1')).toEqual({
			administrationId: 'adm-1',
			poLineId: 'pol-1',
			quantityConfirmed: 25,
			periodStart: '2026-09-01',
			periodEnd: '2026-09-30',
		})
	})

	it('sends an amount in euros as cents', () => {
		expect(linePayload({ poLineId: 'pol-1', mode: 'amountConfirmedCents', value: '1250.5' }, PERIOD, 'adm-1').amountConfirmedCents).toBe(125050)
	})

	it('sends a whole percentage up to 100 and refuses anything else', () => {
		expect(linePayload({ poLineId: 'pol-1', mode: 'percentageComplete', value: 60 }, PERIOD, 'adm-1').percentageComplete).toBe(60)
		expect(linePayload({ poLineId: 'pol-1', mode: 'percentageComplete', value: 120 }, PERIOD, 'adm-1')).toBeNull()
		expect(linePayload({ poLineId: 'pol-1', mode: 'percentageComplete', value: 12.5 }, PERIOD, 'adm-1')).toBeNull()
	})

	it('adds no line for an empty, zero, negative or unknown entry', () => {
		expect(linePayload({ poLineId: 'pol-1', mode: 'quantityConfirmed', value: '' }, PERIOD, 'adm-1')).toBeNull()
		expect(linePayload({ poLineId: 'pol-1', mode: 'quantityConfirmed', value: 0 }, PERIOD, 'adm-1')).toBeNull()
		expect(linePayload({ poLineId: 'pol-1', mode: 'quantityConfirmed', value: -3 }, PERIOD, 'adm-1')).toBeNull()
		expect(linePayload({ poLineId: 'pol-1', mode: 'hours', value: 3 }, PERIOD, 'adm-1')).toBeNull()
	})

	it('sends a missing period as null', () => {
		expect(linePayload({ poLineId: 'pol-1', mode: 'quantityConfirmed', value: 1 }, {}, 'adm-1').periodStart).toBeNull()
	})
})
