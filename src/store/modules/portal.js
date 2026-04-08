// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Portal store for PortalToken management.
 *
 * @see openspec/changes/general/tasks.md#task-3.2
 */
export const usePortalStore = defineStore('portal', {
	state: () => ({
		tokens: [],
		loading: false,
	}),

	getters: {
		getTokens: (state) => state.tokens,
	},

	actions: {
		async fetchTokens() {
			this.loading = true
			try {
				const objectStore = (await import('./object.js')).useObjectStore()
				this.tokens = await objectStore.fetchObjects('PortalToken')
				return this.tokens
			} catch (error) {
				console.error('Failed to fetch portal tokens:', error)
			} finally {
				this.loading = false
			}
			return []
		},

		async generateToken(organizationId, description = null, expiresAt = null, permissions = []) {
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/v1/portal/generate'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify({ organizationId, description, expiresAt, permissions }),
					},
				)
				if (response.ok) {
					const data = await response.json()
					await this.fetchTokens()
					return data
				}
			} catch (error) {
				console.error('Failed to generate portal token:', error)
			}
			return null
		},
	},
})
