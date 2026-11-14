/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the Pipelinq profile card helpers over generated
 * contacts and controller payloads: the fields shown, the classification and
 * the profile and history view states.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-06-profile-card-ui/tasks.md
 */

import { describe, it } from 'vitest'
import {
	buildProfileFields,
	classifyContact,
	selectHistoryState,
	selectProfileState,
} from '../../src/composables/usePipelinqProfile.js'
import { golden, outcome, seeded } from './goldenHarness.js'

const check = golden('pipelinqProfile')

const TEXT = [
	'',
	'  ',
	' Acme BV ',
	'x@y.nl',
	'+31 6 12 34 56 78',
	'Dorpsstraat 1',
	12345678,
	null,
	undefined,
]

/**
 * A generated contact.
 *
 * @param {object} r Random source.
 * @return {object|null}
 */
function contact(r) {
	if (r.next() < 0.1) return r.pick([null, undefined, 0, ''])
	return {
		found: r.pick([true, false, undefined, 'no']),
		legalName: r.pick(TEXT),
		kvkNumber: r.pick(TEXT),
		email: r.pick(TEXT),
		phone: r.pick(TEXT),
		address: r.pick(TEXT),
	}
}

/**
 * A generated controller payload.
 *
 * @param {object} r Random source.
 * @return {object|null}
 */
function payload(r) {
	if (r.next() < 0.08) return r.pick([null, undefined])
	const klantbeeld =
		r.next() < 0.15
			? r.pick([null, undefined, 0])
			: {
					unavailable: r.pick([true, false, 'yes', undefined]),
					empty: r.pick([true, false, undefined]),
					transactions: r.pick([[], [{ id: 1 }], 'none', undefined]),
				}
	return {
		notLinkedToPipelinq: r.pick([true, false, 'true', undefined]),
		contactError: r.pick(['', 'Pipelinq down', 7, null, undefined, undefined]),
		contact: contact(r),
		klantbeeld,
	}
}

describe('pipelinq profile golden', () => {
	it('matches the record', () => {
		const r = seeded(7)
		for (let i = 0; i < 300; i++) {
			const c = contact(r)
			check(
				`${i} fields`,
				outcome(() => buildProfileFields(c)),
			)
			check(
				`${i} classify`,
				outcome(() => classifyContact(c)),
			)
			const p = payload(r)
			check(
				`${i} profile`,
				outcome(() => selectProfileState(p)),
			)
			check(
				`${i} history`,
				outcome(() => selectHistoryState(p)),
			)
		}
	})
})
