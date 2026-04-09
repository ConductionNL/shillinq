// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useDelegationStore = defineStore('delegation', {
	state: () => ({
		delegations: [],
		loading: false,
	}),

	getters: {
		getDelegations: (state) => state.delegations,
	},

	actions: {
		async createDelegation(data) {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/shillinq/api/v1/delegations'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(data),
				})
				if (response.ok) {
					return await response.json()
				}
				return await response.json()
			} catch (error) {
				console.error('Failed to create delegation:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		async revokeDelegation(id) {
			this.loading = true
			try {
				const response = await fetch(generateUrl(`/apps/shillinq/api/v1/delegations/${id}`), {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				return response.ok
			} catch (error) {
				console.error('Failed to revoke delegation:', error)
			} finally {
				this.loading = false
			}
			return false
		},
	},
})
