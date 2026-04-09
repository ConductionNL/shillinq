// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useAccessControlStore = defineStore('accessControl', {
	state: () => ({
		events: [],
		currentEvent: null,
		loading: false,
	}),

	getters: {
		getEvents: (state) => state.events,
		getCurrentEvent: (state) => state.currentEvent,
		getDeniedCount: (state) => {
			const oneDayAgo = new Date(Date.now() - 24 * 60 * 60 * 1000)
			return state.events.filter(
				(e) => e.result === 'denied' && new Date(e.timestamp) > oneDayAgo,
			).length
		},
	},

	actions: {
		async fetchEvents(filters = {}) {
			this.loading = true
			try {
				const url = new URL(generateUrl('/apps/shillinq/api/v1/access-log'), window.location.origin)
				Object.entries(filters).forEach(([k, v]) => {
					if (v) url.searchParams.set(k, v)
				})
				const response = await fetch(url.toString(), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.events = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch access log:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchEvent(id) {
			this.loading = true
			try {
				const response = await fetch(generateUrl(`/apps/shillinq/api/v1/access-log/${id}`), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.currentEvent = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch access event:', error)
			} finally {
				this.loading = false
			}
		},
	},
})
