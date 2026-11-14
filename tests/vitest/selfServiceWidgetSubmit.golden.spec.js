/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of SelfServiceWidget's `submit` method. Each scenario runs
 * it on a fake instance (the component's data plus its methods) against a
 * fake fetch, and compares every request, the step, the error and the
 * confirmation afterwards with
 * tests/vitest/fixtures/selfServiceWidgetSubmit.golden.json.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/specs/bookings-self-service-widget/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterAll, afterEach, beforeEach, describe, expect, it } from 'vitest'
import SelfServiceWidget from '../../src/components/widget/SelfServiceWidget.vue'

const GOLDEN = path.join(__dirname, 'fixtures/selfServiceWidgetSubmit.golden.json')
const RECORD = process.env.SHILLINQ_GOLDEN_RECORD === '1'
const recorded = {}

const SLOT = { startTime: '2026-11-02T09:00:00Z', endTime: '2026-11-02T09:30:00Z' }

const VALID = {
	step: 'review',
	selectedServiceId: 'svc-1',
	selectedDate: '2026-11-02',
	selectedSlot: SLOT,
	customerName: 'Ada',
	email: 'ada@example.org',
	phone: '+31612345678',
	notes: 'Window seat',
	submitError: 'stale',
	confirmationMessage: '',
}

const PROPS = {
	businessId: 'biz-1',
	apiBase: 'https://api.example.org/',
	apiKey: 'key-1',
	resourceId: 'res-9',
	lang: 'en',
	translations: { 'Booking confirmed': 'Geboekt' },
}

/**
 * A fake fetch response.
 *
 * @param {number} status The HTTP status.
 * @param {Function} json What json() does.
 * @return {object} The response.
 */
function reply(status, json) {
	return { status, ok: status >= 200 && status < 300, json }
}

/**
 * A json() that resolves to the given payload.
 *
 * @param {*} payload The parsed body.
 * @return {Function} The json method.
 */
function body(payload) {
	return async () => payload
}

/**
 * A json() that rejects, as for a body that is not JSON.
 *
 * @return {Promise<never>} Always rejects.
 */
async function badJson() {
	throw new SyntaxError('Unexpected token')
}

const SLOTS_REPLY = reply(200, body({ slots: [SLOT] }))

const SCENARIOS = {
	'no slot selected': { fields: { selectedSlot: null } },
	'confirmed with a message': {
		post: reply(201, body({ confirmationMessage: 'See you soon' })),
	},
	'confirmed without a message': { post: reply(200, body({})) },
	'confirmed with a null payload': { post: reply(200, body(null)) },
	'confirmed but the body is not JSON': { post: reply(200, badJson) },
	'confirmed without phone or notes': {
		fields: { phone: '', notes: '' },
		post: reply(201, body({ confirmationMessage: 'ok' })),
	},
	'slot just taken': { post: reply(409, body({})) },
	'slot just taken and the slot reload fails': {
		post: reply(409, body({})),
		slots: reply(500, body({})),
	},
	unauthorised: { post: reply(401, body({})) },
	forbidden: { post: reply(403, body({})) },
	'service gone': { post: reply(404, body({})) },
	'server error': { post: reply(500, body({})) },
	'gateway error': { post: reply(503, body({})) },
	'rejected with a message': { post: reply(422, body({ message: 'Bad email' })) },
	'rejected without a message': { post: reply(400, body({})) },
	'rejected with a null payload': { post: reply(400, body(null)) },
	'rejected with a body that is not JSON': { post: reply(400, badJson) },
	'not ok below 200': { post: reply(199, body({ message: 'odd' })) },
	'redirect status': { post: reply(302, body({ message: 'moved' })) },
	'network failure': { post: 'throw' },
}

/**
 * Run submit for one scenario and return what it did.
 *
 * @param {object} scenario Fields and fake responses.
 * @return {Promise<object>} The requests and the resulting state.
 */
async function run(scenario) {
	const requests = []
	const vm = { ...PROPS }
	for (const [name, fn] of Object.entries(SelfServiceWidget.methods)) {
		vm[name] = fn.bind(vm)
	}
	Object.assign(vm, SelfServiceWidget.data.call(vm), VALID, scenario.fields)
	globalThis.fetch = async (url, init) => {
		requests.push({
			url,
			method: init.method,
			headers: init.headers,
			body: init.body === undefined ? null : JSON.parse(init.body),
			submitting: vm.submitting,
		})
		if (init.method === 'GET') {
			await new Promise((resolve) => setTimeout(resolve, 0))
			return scenario.slots ?? SLOTS_REPLY
		}
		if (scenario.post === 'throw') {
			throw new TypeError('Failed to fetch')
		}
		return scenario.post
	}
	const returned = await vm.submit()
	return {
		requests,
		returned: returned === undefined ? '(undefined)' : returned,
		step: vm.step,
		submitting: vm.submitting,
		submitError: vm.submitError,
		confirmationMessage: vm.confirmationMessage,
		slots: vm.slots,
		slotsError: vm.slotsError,
		selectedSlot: vm.selectedSlot,
	}
}

const golden = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('SelfServiceWidget submit', () => {
	beforeEach(() => {
		globalThis.window = { location: { origin: 'https://host.example' } }
	})

	afterEach(() => {
		delete globalThis.window
		delete globalThis.fetch
	})

	it.each(Object.keys(SCENARIOS))('%s', async (title) => {
		const result = await run(SCENARIOS[title])
		if (RECORD) {
			recorded[title] = result
			return
		}
		expect(result).toEqual(golden[title])
	})

	it('has no recorded scenario the spec no longer runs', () => {
		if (!RECORD) {
			expect(Object.keys(golden).sort()).toEqual(Object.keys(SCENARIOS).sort())
		}
	})
})

afterAll(() => {
	if (RECORD) {
		fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
