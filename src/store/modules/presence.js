// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Heartbeat interval timer reference.
 * @type {number|null}
 */
let heartbeatTimer = null

/**
 * Pinia store for PresenceRecord objects with heartbeat support.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-3.3
 */
export const usePresenceStore = defineStore('presence', {
	state: () => ({
		records: [],
		loading: false,
	}),

	actions: {
		/**
		 * Fetch active viewers for a target.
		 *
		 * @param {string} targetType - Entity type
		 * @param {string} targetId - Object ID
		 * @return {Promise<Array>} Active presence records
		 */
		async fetchActiveViewers(targetType, targetId) {
			this.loading = true
			try {
				const url = generateUrl('/apps/shillinq/api/v1/presence')
					+ `?targetType=${encodeURIComponent(targetType)}&targetId=${encodeURIComponent(targetId)}`
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.records = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch presence records:', error)
			} finally {
				this.loading = false
			}
			return this.records
		},
	},
})

/**
 * Start a heartbeat that pings the presence endpoint every 30 seconds.
 *
 * @param {string} targetType - Entity type
 * @param {string} targetId - Object ID
 * @param {boolean} [isEditing=false] - Whether the user is editing
 */
export function startHeartbeat(targetType, targetId, isEditing = false) {
	stopHeartbeat()

	const sendPing = async () => {
		try {
			const url = generateUrl('/apps/shillinq/api/v1/presence/ping')
			await fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
				},
				body: JSON.stringify({ targetType, targetId, isEditing }),
			})
		} catch (error) {
			console.error('Presence heartbeat failed:', error)
		}
	}

	// Send immediately, then every 30 seconds.
	sendPing()
	heartbeatTimer = setInterval(sendPing, 30000)
}

/**
 * Stop the presence heartbeat.
 */
export function stopHeartbeat() {
	if (heartbeatTimer !== null) {
		clearInterval(heartbeatTimer)
		heartbeatTimer = null
	}
}
