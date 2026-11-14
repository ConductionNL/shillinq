/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The booking widget's steps as they render (server-side, so no DOM is
 * needed), its labels in the partner's translation table, and its service
 * load: the request it makes and what each answer leaves on the widget
 * (REQ-WSW-003).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import SelfServiceWidget from '../../src/components/widget/SelfServiceWidget.vue'

const PROPS = { businessId: 'biz-1', apiBase: 'https://api.example.org/apps/shillinq/', apiKey: 'key-1', lang: 'en' }
const SERVICES = [{ serviceId: 'svc-1', name: 'Haircut', duration: 30 }, { serviceId: 'svc-2', name: 'Colour', duration: 90 }]

/**
 * Render the widget with its data replaced by `state`.
 *
 * @param {object} state The data to render with.
 * @param {object} props Extra props.
 * @return {Promise<string>} The HTML.
 */
async function render(state, props = {}) {
	const app = createSSRApp({ ...SelfServiceWidget, data() { return { ...SelfServiceWidget.data.call(this), ...state } } }, { ...PROPS, ...props })
	return renderToString(app)
}

/**
 * A stand-in widget for calling its methods and computed values.
 *
 * @param {object} state Fields to set.
 * @return {object} The widget.
 */
function widget(state = {}) {
	const vm = { ...PROPS, translations: {} }
	for (const [name, fn] of Object.entries(SelfServiceWidget.methods)) {
		vm[name] = fn.bind(vm)
	}
	Object.assign(vm, SelfServiceWidget.data.call(vm), state)
	for (const [name, fn] of Object.entries(SelfServiceWidget.computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm), configurable: true })
	}
	return vm
}

describe('SelfServiceWidget renders each step', () => {
	it('the service step with every service and the load error', async () => {
		const html = await render({ services: SERVICES, servicesError: 'Configuration error. Please contact the website owner.' })
		expect(html).toContain('Book an appointment')
		expect(html).toContain('Haircut (30 minutes)')
		expect(html).toContain('Colour (90 minutes)')
		expect(html).toContain('Configuration error. Please contact the website owner.')
		expect(html).not.toContain('Your name')
	})

	it('the date step with its times, the chosen one checked', async () => {
		const slots = [{ startTime: '2026-11-20T09:00:00Z' }, { startTime: '2026-11-20T10:00:00Z' }]
		const html = await render({ step: 'datetime', slots, selectedSlot: slots[1], slotsError: 'We could not load available times. Please try another date.' })
		expect(html.match(/role="radio"/g)).toHaveLength(2)
		expect(html.match(/aria-checked="true"/g)).toHaveLength(1)
		expect(html).toContain('wsw-widget__slot--selected')
		expect(html).toContain('We could not load available times. Please try another date.')
	})

	it('the details step with the field errors', async () => {
		const html = await render({ step: 'details', errors: { name: 'Please enter your name', email: '', phone: '', notes: '' } })
		expect(html).toContain('Email address')
		expect(html).toContain('Phone (optional)')
		expect(html).toContain('Notes (optional)')
		expect(html).toContain('Please enter your name')
	})

	it('the review step with the choices, the failure and the busy button', async () => {
		const html = await render({
			step: 'review',
			services: SERVICES,
			selectedServiceId: 'svc-2',
			selectedDate: '2026-11-20',
			selectedSlot: { startTime: '2026-11-20T09:00:00Z' },
			customerName: 'Anna',
			email: 'anna@example.org',
			submitError: 'The slot is no longer available.',
			submitting: true,
		})
		expect(html).toContain('<dd>Colour</dd>')
		expect(html).toContain('<dd>2026-11-20</dd>')
		expect(html).toContain('<dd>Anna</dd>')
		expect(html).toContain('<dd>anna@example.org</dd>')
		expect(html).toContain('The slot is no longer available.')
		expect(html).toContain('Submitting…')
		expect(await render({ step: 'review' })).toContain('Confirm booking')
	})

	it('the confirmation, in the partner\'s words and dark mode', async () => {
		const html = await render({ step: 'confirmed', confirmationMessage: 'See you soon.' }, { darkMode: true, translations: { 'Booking confirmed': 'Afspraak bevestigd' } })
		expect(html).toContain('wsw-widget--dark')
		expect(html).toContain('Afspraak bevestigd')
		expect(html).not.toContain('Select a service')
	})
})

describe('SelfServiceWidget labels', () => {
	it('names each step and none for an unknown one', () => {
		const vm = widget()
		expect(['service', 'datetime', 'details', 'review', 'confirmed', 'other'].map((step) => {
			vm.step = step
			return vm.stepLabel
		})).toEqual(['Select a service', 'Select a date', 'Your details', 'Review and confirm', 'Booking confirmed', ''])
	})

	it('names the chosen service, or nothing', () => {
		expect(widget({ services: SERVICES, selectedServiceId: 'svc-1' }).selectedServiceName).toBe('Haircut')
		expect(widget({ services: SERVICES, selectedServiceId: 'svc-9' }).selectedServiceName).toBe('')
	})

	it('marks only the chosen slot', () => {
		const vm = widget({ selectedSlot: { startTime: 'a' } })
		expect(vm.slotClasses({ startTime: 'a' })).toEqual(['wsw-widget__slot', { 'wsw-widget__slot--selected': true }])
		expect(vm.slotClasses({ startTime: 'b' })[1]['wsw-widget__slot--selected']).toBe(false)
	})

	it('checks the phone and the notes only when given', () => {
		const vm = widget({ customerName: 'Anna', email: 'anna@example.org', phone: '0612', notes: 'x'.repeat(501) })
		expect(vm.detailsValid).toBe(false)
		expect(vm.errors).toEqual({ name: '', email: '', phone: 'Phone number must be in international format (e.g. +31612345678)', notes: 'Notes must be at most 500 characters' })
		vm.phone = '+31612345678'
		vm.notes = ''
		expect(vm.detailsValid).toBe(true)
	})
})

describe('SelfServiceWidget loadServices', () => {
	let requests

	/**
	 * Answer every fetch with `answer`, recording the requests.
	 *
	 * @param {Function} answer The answer.
	 * @return {void}
	 */
	function answerWith(answer) {
		globalThis.fetch = vi.fn(async (url, options) => {
			requests.push([url, options])
			return answer()
		})
	}

	beforeEach(() => {
		requests = []
		globalThis.window = { location: { origin: 'https://partner.example.com' } }
	})

	afterEach(() => {
		delete globalThis.fetch
		delete globalThis.window
	})

	it('asks for the business\'s services with the widget key, and lists them', async () => {
		answerWith(() => ({ status: 200, ok: true, json: async () => ({ services: SERVICES }) }))
		const vm = widget()
		await vm.loadServices()
		expect(requests).toEqual([[
			'https://api.example.org/apps/shillinq/api/widget/services?businessId=biz-1',
			{ method: 'GET', headers: { 'Content-Type': 'application/json', Authorization: 'Bearer key-1' } },
		]])
		expect([vm.services, vm.servicesError, vm.loadingServices]).toEqual([SERVICES, '', false])
	})

	it.each([
		[() => ({ status: 401, ok: false }), 'Configuration error. Please contact the website owner.'],
		[() => ({ status: 403, ok: false }), 'Configuration error. Please contact the website owner.'],
		[() => ({ status: 500, ok: false }), 'We could not load the available services. Please try again later.'],
		[() => { throw new TypeError('offline') }, 'Network error. Please check your connection and try again.'],
	])('says why the services did not load (%#)', async (answer, message) => {
		answerWith(answer)
		const vm = widget({ services: [] })
		await vm.loadServices()
		expect([vm.servicesError, vm.loadingServices, vm.services]).toEqual([message, false, []])
	})

	it('lists no services for an answer without them', async () => {
		answerWith(() => ({ status: 200, ok: true, json: async () => null }))
		const vm = widget({ services: SERVICES })
		await vm.loadServices()
		expect(vm.services).toEqual([])
	})

	it('moves to the date step only with a service chosen', async () => {
		answerWith(() => ({ status: 200, ok: true, json: async () => ({ slots: [] }) }))
		const none = widget()
		await none.goToDateStep()
		expect([none.step, requests]).toEqual(['service', []])

		const chosen = widget({ selectedServiceId: 'svc-1' })
		await chosen.goToDateStep()
		expect(chosen.step).toBe('datetime')
		expect(requests).toHaveLength(1)
		expect(requests[0][0]).toContain('/api/widget/')
	})
})
