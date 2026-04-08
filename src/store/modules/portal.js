// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { createObjectStore } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'

/**
 * Portal store for PortalToken management.
 *
 * @see openspec/changes/general/tasks.md#task-3.2
 */
const portalPlugin = {
	name: 'portal',
	getters: {
		tokens: (state) => state.collections?.PortalToken || [],
		tokenLoading: (state) => state.loading?.PortalToken || false,
	},
	actions: {
		async fetchTokens() {
			return this.fetchCollection('PortalToken')
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
}

export const usePortalStore = createObjectStore('PortalToken', { plugins: [portalPlugin] })
