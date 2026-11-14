/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * AfspraakDetail as it renders (server-side, so no DOM is needed) in its
 * loading, failed and loaded states, what it hands the profile card and the
 * timeline, and its lifecycle: the pipelinq link lookup and booking load on
 * creation, the reload when the id changes, and the label fallback.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-06-profile-card-ui/tasks.md
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString, ssrRenderSlot } from 'vue/server-renderer'

const captured = vi.hoisted(() => ({ card: [], timeline: [] }))

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('../../src/components/pipelinq/PipelinqProfileCard.vue', () => ({
	default: {
		name: 'PipelinqProfileCard',
		props: ['payload', 'booking', 'pipelinqBaseUrl'],
		ssrRender(ctx, push) {
			captured.card.push({ ...ctx.$props })
			push('<aside data-stub="PipelinqProfileCard"></aside>')
		},
	},
}))
vi.mock('../../src/components/pipelinq/KlantbeeldTimeline.vue', () => ({
	default: {
		name: 'KlantbeeldTimeline',
		props: ['payload', 'hasMore', 'loading'],
		ssrRender(ctx, push) {
			captured.timeline.push({ props: { ...ctx.$props }, onLoadMore: ctx.$attrs.onLoadMore })
			push('<ol data-stub="KlantbeeldTimeline"></ol>')
		},
	},
}))

const { default: AfspraakDetail } = await import('../../src/views/bookings/AfspraakDetail.vue')

const RouterLink = {
	name: 'RouterLink',
	props: ['to'],
	ssrRender(ctx, push, parent) {
		push(`<a data-to="${ctx.to.name}">`)
		ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
		push('</a>')
	},
}

/**
 * Render the detail page for `id` with its data replaced by `state`.
 *
 * @param {object} state The data.
 * @param {object} options The id and the routes the router knows.
 * @return {Promise<string>} The HTML.
 */
function render(state, { id = 'b-1', routes = [{ name: 'Afspraken' }] } = {}) {
	const app = createSSRApp({ ...AfspraakDetail, data: () => ({ ...AfspraakDetail.data.call({}), ...state }) }, { id })
	app.component('router-link', RouterLink)
	app.config.globalProperties.$router = { options: { routes } }
	return renderToString(app)
}

/**
 * A fake AfspraakDetail with its methods bound.
 *
 * @param {object} over Values to set first.
 * @return {object}
 */
function detail(over = {}) {
	const self = { ...AfspraakDetail.data.call({}), id: 'b/1', ...over }
	for (const [name, method] of Object.entries(AfspraakDetail.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

const SETTINGS = '/index.php/apps/shillinq/api/settings/pipelinq'
const BOOKING = '/index.php/apps/shillinq/api/v1/bookings/b%2F1'

describe('AfspraakDetail render', () => {
	beforeEach(() => {
		// The page's own created() load stays pending, so the state rendered is the one given.
		axios.get.mockReset().mockReturnValue(new Promise(() => {}))
		captured.card.length = 0
		captured.timeline.length = 0
	})

	it('shows the back link, the reference and the loading text while loading', async () => {
		const html = await render({ loading: true })

		expect(html).toMatch(/<a data-to="Afspraken"><!--\[--> ← Back to bookings<!--\]--><\/a>/)
		expect(html).toMatch(/class="afspraak-detail__reference"[^>]*>\s*b-1\s*</)
		expect(html).toContain('data-testid="afspraak-detail-loading"')
		expect(html).toContain('Loading booking...')
		expect(html).not.toContain('afspraak-detail-error')
		expect(axios.get).toHaveBeenCalledWith(SETTINGS)
	})

	it('drops the back link without the index route and the reference without an id', async () => {
		const html = await render({ loading: true }, { id: '', routes: [] })

		expect(html).not.toContain('Back to bookings')
		expect(html).not.toContain('afspraak-detail__reference')
		expect(html).toContain('Appointment')
	})

	it('shows a failed load with a retry button', async () => {
		const html = await render({ loading: false, loadError: 'Booking not found', payload: { booking: {} } })

		expect(html).toMatch(/data-testid="afspraak-detail-error"[^>]*><p[^>]*>Booking not found<\/p>/)
		expect(html).toMatch(/data-testid="afspraak-detail-retry"[^>]*>\s*Retry\s*<\/button>/)
		expect(html).not.toContain('afspraak-detail-summary')
		expect(captured.card).toEqual([])
	})

	it('renders the summary, the profile card and the timeline of a loaded booking', async () => {
		const payload = { booking: { appointmentId: 'a-9', status: 'confirmed' }, klantbeeld: { limit: 1, transactions: [{ id: 1 }] } }
		const html = await render({
			loading: false,
			payload,
			accumulatedTransactions: [{ id: 1 }],
			pipelinqBaseUrl: 'https://p',
			pagingLoading: true,
			pagingError: 'Nope',
		})

		expect(html).toMatch(/<dt[^>]*>\s*Reference\s*<\/dt><dd data-testid="afspraak-detail-appointmentId"[^>]*>\s*a-9\s*<\/dd>/)
		expect(html).toMatch(/data-testid="afspraak-detail-status"[^>]*>\s*confirmed\s*</)
		expect(html).toMatch(/data-testid="afspraak-detail-notes"[^>]*>\s*—\s*</)
		expect(html).toMatch(/class="afspraak-detail__reference"[^>]*>\s*a-9\s*</)
		expect(html).toMatch(/data-testid="afspraak-detail-paging-error"[^>]*>\s*Nope\s*</)

		expect(captured.card).toEqual([{ payload, booking: payload.booking, pipelinqBaseUrl: 'https://p' }])
		const [timeline] = captured.timeline
		expect(timeline.props).toEqual({
			payload: { ...payload, klantbeeld: { limit: 1, transactions: [{ id: 1 }], empty: false } },
			hasMore: true,
			loading: true,
		})
		expect(timeline.onLoadMore).toEqual(expect.any(Function))
	})

	it('gives the profile card an empty booking and leaves out a paging error it does not have', async () => {
		const html = await render({ loading: false, payload: { id: 'x' } })

		expect(captured.card[0].booking).toEqual({})
		expect(captured.timeline[0].props).toEqual({ payload: { id: 'x' }, hasMore: false, loading: false })
		expect(html).not.toContain('afspraak-detail-paging-error')
	})

	it('loads more transactions through the timeline\'s load-more event', async () => {
		await render({ loading: false, payload: { klantbeeld: { limit: 1, transactions: [] } } }, { id: 'b/1' })
		axios.get.mockReset().mockResolvedValue({ data: { klantbeeld: { transactions: [] } } })

		await captured.timeline[0].onLoadMore({ limit: 1, offset: 1 })

		expect(axios.get).toHaveBeenCalledWith(BOOKING, { params: { limit: 1, offset: 1 } })
	})
})

describe('AfspraakDetail lifecycle', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	afterEach(() => {
		delete globalThis.t
	})

	it('looks up the pipelinq link, then loads the booking, when created', async () => {
		axios.get
			.mockResolvedValueOnce({ data: { baseUrl: 'https://pipelinq' } })
			.mockResolvedValueOnce({ data: { booking: { appointmentId: 'a-1' } } })
		const vm = detail()

		await AfspraakDetail.created.call(vm)

		expect(axios.get.mock.calls).toEqual([[SETTINGS], [BOOKING]])
		expect([vm.pipelinqBaseUrl, vm.payload, vm.loading]).toEqual(['https://pipelinq', { booking: { appointmentId: 'a-1' } }, false])
	})

	it('reads the link from either field, and drops a non-string or failed one', async () => {
		const links = []
		for (const outcome of [{ data: { pipelinqBaseUrl: 'https://legacy' } }, { data: { baseUrl: 42 } }, { data: null }, new Error('403')]) {
			if (outcome instanceof Error) {
				axios.get.mockRejectedValueOnce(outcome)
			} else {
				axios.get.mockResolvedValueOnce(outcome)
			}
			const vm = detail({ pipelinqBaseUrl: 'stale' })
			await vm.loadConfig()
			links.push(vm.pipelinqBaseUrl)
		}

		expect(links).toEqual(['https://legacy', '', '', ''])
	})

	it('reloads the booking when its id changes', async () => {
		axios.get.mockResolvedValue({ data: { id: 'b/1' } })
		const vm = detail({ accumulatedTransactions: [1] })

		AfspraakDetail.watch.id.handler.call(vm)
		expect(vm.loading).toBe(true)
		await vi.waitFor(() => expect(vm.loading).toBe(false))

		expect(AfspraakDetail.watch.id.immediate).toBe(false)
		expect(axios.get).toHaveBeenCalledWith(BOOKING)
		expect([vm.payload, vm.accumulatedTransactions]).toEqual([{ id: 'b/1' }, []])
	})

	it('translates a label through the global t, else returns it as is', () => {
		expect(detail().label('Retry')).toBe('Retry')

		globalThis.t = vi.fn((app, text) => `${app}:${text}`)
		expect(detail().label('Retry')).toBe('shillinq:Retry')
		expect(globalThis.t).toHaveBeenCalledWith('shillinq', 'Retry')
	})
})
