// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useUserStore = defineStore('user', {
	state: () => ({
		users: [],
		currentUser: null,
		loading: false,
	}),

	getters: {
		getUsers: (state) => state.users,
		getCurrentUser: (state) => state.currentUser,
	},

	actions: {
		async fetchUsers() {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/shillinq/api/v1/users'), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.users = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch users:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchUser(id) {
			this.loading = true
			try {
				const response = await fetch(generateUrl(`/apps/shillinq/api/v1/users/${id}`), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.currentUser = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch user:', error)
			} finally {
				this.loading = false
			}
		},

		async updateUser(id, data) {
			this.loading = true
			try {
				const response = await fetch(generateUrl(`/apps/shillinq/api/v1/users/${id}`), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(data),
				})
				if (response.ok) {
					await this.fetchUsers()
					return await response.json()
				}
			} catch (error) {
				console.error('Failed to update user:', error)
			} finally {
				this.loading = false
			}
			return null
		},
	},
})
