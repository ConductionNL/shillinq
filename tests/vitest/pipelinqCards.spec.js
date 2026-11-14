/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Pins the Pipelinq profile card and klantbeeld timeline against a fake
 * instance: the view state each payload selects, the contact kind and
 * fields, the link into Pipelinq, the transaction rows and their
 * formatting, and the next-page request the timeline emits.
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import KlantbeeldTimeline from '../../src/components/pipelinq/KlantbeeldTimeline.vue'
import PipelinqProfileCard from '../../src/components/pipelinq/PipelinqProfileCard.vue'

/**
 * Evaluate a computed of the profile card.
 *
 * @param {string} name The computed.
 * @param {object} props The props.
 * @return {*} The value.
 */
function card(name, props) {
	return PipelinqProfileCard.computed[name].call({ booking: {}, pipelinqBaseUrl: '', ...props })
}

/**
 * Evaluate a computed of the timeline.
 *
 * @param {string} name The computed.
 * @param {object} payload The payload.
 * @return {*} The value.
 */
function timeline(name, payload) {
	return KlantbeeldTimeline.computed[name].call({ payload })
}

const contact = {
	found: true,
	externalId: 'c/42',
	legalName: '  Bakkerij Jansen ',
	kvkNumber: '12345678',
	email: 'info@jansen.example',
	phone: '+31 6 1234 5678',
	address: '',
}

afterEach(() => {
	delete globalThis.t
})

describe('PipelinqProfileCard', () => {
	it('selects the view state from the payload', () => {
		expect(card('state', { payload: { notLinkedToPipelinq: true } })).toBe('unlinked')
		expect(card('state', { payload: { contactError: 'timeout', contact } })).toBe('error')
		expect(card('state', { payload: { contact: { found: false } } })).toBe('notfound')
		expect(card('state', { payload: { contact } })).toBe('ok')
		expect(card('state', { payload: null })).toBe('error')
	})

	it('classifies the contact by its KvK number', () => {
		expect(card('kind', { payload: { contact } })).toBe('organization')
		expect(card('kind', { payload: { contact: { found: true, kvkNumber: '  ' } } })).toBe('individual')
		expect(card('kind', { payload: { contact: { found: false } } })).toBeNull()
		expect(card('kind', { payload: {} })).toBeNull()
	})

	it('lists the non-empty fields in order, with mail and phone links', () => {
		expect(card('fields', { payload: { contact } })).toEqual([
			{ key: 'legalName', label: 'Name', value: 'Bakkerij Jansen', emphasis: true },
			{ key: 'kvkNumber', label: 'KvK', value: '12345678' },
			{ key: 'email', label: 'Email', value: 'info@jansen.example', href: 'mailto:info@jansen.example' },
			{ key: 'phone', label: 'Phone', value: '+31 6 1234 5678', href: 'tel:+31612345678' },
		])
		expect(card('fields', { payload: {} })).toEqual([])
	})

	it('exposes the contact error, empty when there is none', () => {
		expect(card('contactError', { payload: { contactError: 'Pipelinq is down' } })).toBe('Pipelinq is down')
		expect(card('contactError', { payload: {} })).toBe('')
	})

	it('reads the booking pipelinq contact id, trimmed', () => {
		expect(card('pipelinqContactId', { payload: {}, booking: { pipelinqContactId: ' 77 ' } })).toBe('77')
		expect(card('pipelinqContactId', { payload: {}, booking: null })).toBe('')
	})

	it('links into Pipelinq when both the base URL and the contact id are known', () => {
		expect(card('pipelinqLink', { payload: { contact }, pipelinqBaseUrl: 'https://crm.example/apps/pipelinq//' }))
			.toBe('https://crm.example/apps/pipelinq/contacts/c%2F42')
		expect(card('pipelinqLink', { payload: { contact }, pipelinqBaseUrl: '' })).toBeNull()
		expect(card('pipelinqLink', { payload: { contact: { found: true } }, pipelinqBaseUrl: 'https://crm.example' })).toBeNull()
	})

	it('translates labels when t is available, and returns the key otherwise', () => {
		expect(PipelinqProfileCard.methods.label('Name')).toBe('Name')
		globalThis.t = (app, key) => `${app}:${key}`
		expect(PipelinqProfileCard.methods.label('Name')).toBe('shillinq:Name')
	})
})

describe('KlantbeeldTimeline', () => {
	const ok = { contact }

	it('selects the history state', () => {
		expect(timeline('state', { ...ok, klantbeeld: { transactions: [{ amount: 1 }] } })).toBe('ok')
		expect(timeline('state', { ...ok, klantbeeld: { transactions: [] } })).toBe('empty')
		expect(timeline('state', { ...ok, klantbeeld: { empty: true } })).toBe('empty')
		expect(timeline('state', { ...ok, klantbeeld: { unavailable: true } })).toBe('unavailable')
		expect(timeline('state', ok)).toBe('hidden')
		expect(timeline('state', { notLinkedToPipelinq: true, klantbeeld: { transactions: [] } })).toBe('hidden')
	})

	it('lists the transactions, or none when they are missing or not a list', () => {
		const rows = [{ amount: 1 }]
		expect(timeline('transactions', { klantbeeld: { transactions: rows } })).toBe(rows)
		expect(timeline('transactions', { klantbeeld: { transactions: 'x' } })).toEqual([])
		expect(timeline('transactions', null)).toEqual([])
	})

	it('formats dates and amounts', () => {
		const { methods } = KlantbeeldTimeline
		expect(methods.formatDate('2026-02-03T23:00:00Z')).toBe('2026-02-03')
		expect(methods.formatDate('not a date')).toBe('not a date')
		expect(methods.formatDate(null)).toBe('')
		expect(methods.formatAmount({ amount: 12.345, currency: 'USD' })).toBe('USD 12.35')
		expect(methods.formatAmount({ amount: 'x' })).toBe('EUR 0.00')
	})

	it('keys each row by date, description and position', () => {
		expect(KlantbeeldTimeline.methods.rowKey({ date: '2026-01-01', description: 'Knippen' }, 2)).toBe('2026-01-01-Knippen-2')
		expect(KlantbeeldTimeline.methods.rowKey(null, 0)).toBe('--0')
	})

	it('asks for the page after the current one', () => {
		const vm = { payload: { klantbeeld: { limit: 10, offset: 20 } }, $emit: vi.fn() }
		KlantbeeldTimeline.methods.onLoadMore.call(vm)
		expect(vm.$emit).toHaveBeenCalledWith('load-more', { limit: 10, offset: 30 })

		const first = { payload: {}, $emit: vi.fn() }
		KlantbeeldTimeline.methods.onLoadMore.call(first)
		expect(first.$emit).toHaveBeenCalledWith('load-more', { limit: 5, offset: 5 })
	})

	it('translates labels when t is available, and returns the key otherwise', () => {
		expect(KlantbeeldTimeline.methods.label('History')).toBe('History')
		globalThis.t = (app, key) => `${app}:${key}`
		expect(KlantbeeldTimeline.methods.label('History')).toBe('shillinq:History')
	})
})
