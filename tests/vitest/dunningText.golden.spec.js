/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the dunning channel names and the last-run line.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 */

import { describe, it } from 'vitest'
import { channelLabel, reportLine } from '../../src/utils/dunningApi.js'
import { golden, outcome } from './goldenHarness.js'

const check = golden('dunningText')

const CHANNELS = [
	'EMAIL',
	'eMAILPostRegistration',
	'REGISTERED_POST',
	'COLLECTION_AGENCY_API',
	'email',
	'LETTER',
	'',
	null,
	undefined,
	0,
	'toString',
	'__proto__',
	'constructor',
	'hasOwnProperty',
]

const REPORTS = [
	null,
	undefined,
	{},
	{ ranAt: '2026-11-02T06:00:00Z', locked: true },
	{
		ranAt: '2026-11-02T06:00:00Z',
		locked: 'true',
		markedOverdue: 3,
		sent: '2',
		manual: null,
		failed: 'x',
		skipped: 0,
	},
	{ ranAt: null, markedOverdue: 1, sent: 1, manual: 1, failed: 1, skipped: 1 },
	{ ranAt: 20261102, locked: false, sent: -1 },
	{ ranAt: '2026-11', markedOverdue: '07' },
]

describe('dunning text golden', () => {
	it('matches the record', () => {
		for (const channel of CHANNELS) {
			check(
				`channel ${String(channel)}`,
				outcome(() => channelLabel(channel)),
			)
		}
		REPORTS.forEach((report, i) =>
			check(
				`report ${i}`,
				outcome(() => reportLine(report)),
			),
		)
	})
})
