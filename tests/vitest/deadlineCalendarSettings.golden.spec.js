/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the deadline calendar settings normalisation and save
 * payload over generated, partly malformed settings and form rows.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 */

import { describe, it } from 'vitest'
import {
	buildSavePayload,
	normaliseSettings,
} from '../../src/views/deadlineCalendarSettingsHelpers.js'
import { golden, outcome, seeded } from './goldenHarness.js'

const check = golden('deadlineCalendarSettings')
const IDS = ['filing', 'payment-run', 'ar-due', 'contract', 'other', '', null]
const LEAD = [0, 3, '5', '7 days', -1, 'x', null, undefined, 2.9, '010']

describe('deadline calendar settings golden', () => {
	it('matches the record', () => {
		const r = seeded(17)
		for (let i = 0; i < 300; i++) {
			const categories = {}
			for (const id of IDS.slice(0, 5)) {
				if (r.next() > 0.3) {
					categories[id] =
						r.next() < 0.15
							? r.pick([null, 'on', 5, []])
							: {
									enabled: r.pick([
										true,
										false,
										'true',
										1,
										undefined,
									]),
									leadDays: r.pick(LEAD),
								}
				}
			}
			const data =
				r.next() < 0.1
					? r.pick([
							null,
							undefined,
							'x',
							{ categories: null },
							{ categories: 'x' },
						])
					: { categories }
			check(
				`normalise ${i}`,
				outcome(() => normaliseSettings(data)),
			)
			const rows =
				r.next() < 0.1
					? r.pick([null, 'x', {}])
					: Array.from({ length: r.int(6) }, () =>
							r.next() < 0.1
								? r.pick([null, 0])
								: {
										id: r.pick(IDS),
										enabled: r.pick([true, false, 'true', 1]),
										leadDays: r.pick(LEAD),
									},
						)
			check(
				`save ${i}`,
				outcome(() => buildSavePayload(rows)),
			)
		}
	})
})
