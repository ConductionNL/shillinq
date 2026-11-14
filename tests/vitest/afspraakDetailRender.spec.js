/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * What AfspraakDetail.vue renders (server-side, so no DOM is needed) in its
 * loading, error and loaded states, plus its mount and route-change hooks and
 * its label translation.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-06-profile-card-ui/tasks.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/components/pipelinq/PipelinqProfileCard.vue', () => ({
	default: {
		name: 'PipelinqProfileCard',
		props: ['payload', 'booking', 'pipelinqBaseUrl'],
		render() { return h('aside', { 'data-stub': 'profile', 'data-base': this.pipelinqBaseUrl, 'data-ref': this.booking.appointmentId || '' }) },
	},
}))
vi.mock('../../src/components/pipelinq/KlantbeeldTimeline.vue', () => ({
	default: {
		name: 'KlantbeeldTimeline',
		props: ['payload', 'hasMore', 'loading'],
		render() { return h('ol', { 'data-stub': 'timeline', 'data-more': String(this.hasMore), 'data-count': String(this.payload?.klantbeeld?.transactions?.length ?? 'none') }) },
	},
}))

const { default: AfspraakDetail } = await import('../../src/views/bookings/AfspraakDetail.vue')

/**
 * Render the view with its data replaced by `state`, without running its fetches.
 *
 * @param {object} state The data.
 * @param {object} extra Extra component options (e.g. a $router stub via mixins).
 * @return {Promise<string>} The HTML, without the scoped-style attributes.
 */
async function render(state, extra = {}) {
	const view = { ...AfspraakDetail, data: () => ({ ...AfspraakDetail.data.call({}), ...state }), created() {}, ...extra }
	const app = createSSRApp(view, { id: 'b-1' })
	app.component('router-link', { props: ['to'], render() { return h('a', { 'data-to': this.to.name }, this.$slots.default?.()) } })
	return (await renderToString(app)).replace(/ data-v-[0-9a-f]+/g, '')
}

describe('AfspraakDetail render', () => {
	afterEach(() => {
		delete globalThis.t
	})

	it('shows only the loading line while loading', async () => {
		const html = await render({ loading: true })

		expect(html).toContain('data-testid="afspraak-detail-loading"')
		expect(html).toContain('Loading booking...')
		expect(html).not.toContain('afspraak-detail-error')
		expect(html).not.toContain('afspraak-detail-summary')
		expect(html).toContain('<span class="afspraak-detail__reference">b-1</span>')
		expect(html).not.toContain('afspraak-detail-back')
	})

	it('shows the error with a retry button', async () => {
		const html = await render({ loading: false, loadError: 'Booking not found' })

		expect(html).toContain('<p>Booking not found</p>')
		expect(html).toContain('data-testid="afspraak-detail-retry"')
		expect(html).toContain('Retry')
		expect(html).not.toContain('afspraak-detail-summary')
	})

	it('shows the summary, the profile card, the timeline and a paging error once loaded', async () => {
		const html = await render({
			loading: false,
			payload: {
				booking: { appointmentId: 'APP-7', startTime: '2026-11-13T09:00:00Z', status: 'confirmed', notes: 0 },
				klantbeeld: { transactions: [1, 2], limit: 2 },
			},
			accumulatedTransactions: [1, 2, 3],
			pipelinqBaseUrl: 'https://crm.example',
			pagingError: 'Could not load more',
		})

		expect(html).toContain('<span class="afspraak-detail__reference">APP-7</span>')
		expect(html).toMatch(/data-testid="afspraak-detail-appointmentId">\s*APP-7\s*</)
		expect(html).toMatch(/data-testid="afspraak-detail-status">\s*confirmed\s*</)
		expect(html).toMatch(/data-testid="afspraak-detail-notes">\s*0\s*</)
		expect(html).toMatch(/data-testid="afspraak-detail-serviceId">\s*—\s*</)
		expect(html).toContain('Start (UTC)')
		expect(html).toContain('data-stub="profile" data-base="https://crm.example" data-ref="APP-7"')
		expect(html).toContain('data-stub="timeline" data-more="true" data-count="3"')
		expect(html).toContain('data-testid="afspraak-detail-paging-error"')
		expect(html).toContain('Could not load more')
	})

	it('links back to the bookings index when that route exists', async () => {
		const html = await render(
			{ loading: true },
			{ computed: { ...AfspraakDetail.computed, hasIndexRoute: () => true } },
		)

		expect(html).toContain('data-testid="afspraak-detail-back"')
		expect(html).toContain('data-to="Afspraken"')
		expect(html).toContain('Back to bookings')
	})
})

describe('AfspraakDetail hooks', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	afterEach(() => {
		delete globalThis.t
	})

	it('loads the pipelinq settings then the booking on creation', async () => {
		const calls = []
		const self = {
			loadConfig: vi.fn(async () => { calls.push('config') }),
			reload: vi.fn(async () => { calls.push('reload') }),
		}

		await AfspraakDetail.created.call(self)

		expect(calls).toEqual(['config', 'reload'])
	})

	it('reloads when the id changes', () => {
		const self = { reload: vi.fn() }

		AfspraakDetail.watch.id.handler.call(self)

		expect(self.reload).toHaveBeenCalledTimes(1)
		expect(AfspraakDetail.watch.id.immediate).toBe(false)
	})

	it('translates labels through t() when it is available', () => {
		globalThis.t = vi.fn((app, key) => `${app}:${key}`)

		expect(AfspraakDetail.methods.label('Retry')).toBe('shillinq:Retry')
		expect(globalThis.t).toHaveBeenCalledWith('shillinq', 'Retry')

		delete globalThis.t
		expect(AfspraakDetail.methods.label('Retry')).toBe('Retry')
	})
})
