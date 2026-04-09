// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

let heartbeatInterval = null

export const usePresenceStore = defineStore('presence', {
	state: () => ({
		records: [],
		loading: false,
	}),
	actions: {
		async fetchPresence(targetType, targetId) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/shillinq/api/v1/presence?targetType=${targetType}&targetId=${targetId}`)
				const response = await fetch(url, { headers: { requesttoken: OC.requestToken } })
				if (response.ok) {
					const data = await response.json()
					this.records = data.results || data
				}
			} catch (error) {
				console.error('Failed to fetch presence:', error)
			} finally {
				this.loading = false
			}
		},
		async ping(targetType, targetId) {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/presence')
				await fetch(url, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
					body: JSON.stringify({ targetType, targetId }),
				})
			} catch (error) {
				console.error('Failed to send presence ping:', error)
			}
		},
	},
})

export function startHeartbeat(targetType, targetId) {
	stopHeartbeat()
	const store = usePresenceStore()
	// Send initial ping immediately
	store.ping(targetType, targetId)
	store.fetchPresence(targetType, targetId)
	// Then repeat every 30 seconds
	heartbeatInterval = setInterval(() => {
		store.ping(targetType, targetId)
		store.fetchPresence(targetType, targetId)
	}, 30000)
}

export function stopHeartbeat() {
	if (heartbeatInterval) {
		clearInterval(heartbeatInterval)
		heartbeatInterval = null
	}
}
