/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the booking-line guidance rows and the object list URL
 * over generated lines, accounts and filters.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/bookkeeping-chart-of-accounts/spec.md
 */

import { describe, it, vi } from 'vitest'
import { golden, outcome, seeded } from './goldenHarness.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/index.php${url}` }))
vi.mock('@nextcloud/axios', () => ({ default: {} }))

const check = golden('accountGuidance')
const NUMBERS = ['8000', '4000', 8000, '', null, undefined]
const TEXT = ['Rent', ' Use for rent \n', '', null, undefined, 5]

describe('account guidance golden', () => {
	it('matches the record', async () => {
		const { guidanceRows, objectsUrl } =
			await import('../../src/utils/accountGuidance.js')
		const r = seeded(23)
		for (let i = 0; i < 200; i++) {
			const accounts =
				r.next() < 0.1
					? r.pick([null, undefined])
					: Array.from({ length: r.int(5) }, () =>
							r.next() < 0.1
								? null
								: {
										accountNumber: r.pick(NUMBERS),
										name: r.pick(TEXT),
										description: r.pick(TEXT),
										controlAccountFor: r.pick(['ar', '', null]),
									},
						)
			const lines =
				r.next() < 0.1
					? r.pick([null, undefined])
					: Array.from({ length: r.int(5) }, () =>
							r.next() < 0.1
								? null
								: {
										id: r.pick(['l1', 0, null, undefined]),
										accountNumber: r.pick(NUMBERS),
										side: r.pick(['debit', null, undefined]),
										amount: r.pick([
											10,
											'2.5',
											'x',
											null,
											undefined,
										]),
										costCenterCode: r.pick(TEXT),
										projectCode: r.pick(TEXT),
									},
						)
			check(
				`rows ${i}`,
				outcome(() => guidanceRows(lines, accounts)),
			)
			const filters = Object.fromEntries(
				['a', 'b', 'c']
					.filter(() => r.next() > 0.4)
					.map((k) => [
						k,
						r.pick(['x', '', 0, false, null, undefined, 'a b&c']),
					]),
			)
			check(
				`url ${i}`,
				outcome(() =>
					r.next() < 0.2
						? objectsUrl('Account')
						: objectsUrl('Account', filters),
				),
			)
		}
	})
})
