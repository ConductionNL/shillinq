// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useAccessControlStore = defineStore('accessControl', {
	state: () => ({
		entries: [],
		entry: null,
		loading: false,
	}),

	actions: {
		async fetchEntries(filters = {}) {
			this.loading = true
			try {
				const url = new URL(
					generateUrl('/apps/shillinq/api/v1/access-log'),
					window.location.origin,
				)
				Object.entries(filters).forEach(([k, v]) => url.searchParams.set(k, v))

				const response = await fetch(url.toString(), {
					headers: { requesttoken: OC.requestToken },
				})
				const data = await response.json()
				this.entries = data.results || []
			} catch (error) {
				console.error('Failed to fetch access log:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchEntry(id) {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl(`/apps/shillinq/api/v1/access-log/${id}`),
					{ headers: { requesttoken: OC.requestToken } },
				)
				this.entry = await response.json()
			} catch (error) {
				console.error('Failed to fetch access log entry:', error)
			} finally {
				this.loading = false
			}
		},
	},
})
