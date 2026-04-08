// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useUserStore = defineStore('user', {
	state: () => ({
		users: [],
		user: null,
		loading: false,
	}),

	actions: {
		async fetchUsers() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/v1/users'),
					{ headers: { requesttoken: OC.requestToken } },
				)
				const data = await response.json()
				this.users = data.results || []
			} catch (error) {
				console.error('Failed to fetch users:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchUser(id) {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl(`/apps/shillinq/api/v1/users/${id}`),
					{ headers: { requesttoken: OC.requestToken } },
				)
				this.user = await response.json()
			} catch (error) {
				console.error('Failed to fetch user:', error)
			} finally {
				this.loading = false
			}
		},

		async saveUser(userData) {
			const response = await fetch(
				generateUrl(`/apps/shillinq/api/v1/users/${userData.id}`),
				{
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(userData),
				},
			)
			return response.json()
		},
	},
})
