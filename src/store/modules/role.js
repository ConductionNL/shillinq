// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useRoleStore = defineStore('role', {
	state: () => ({
		roles: [],
		currentRole: null,
		loading: false,
	}),

	getters: {
		getRoles: (state) => state.roles,
		getCurrentRole: (state) => state.currentRole,
	},

	actions: {
		async fetchRoles() {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/shillinq/api/v1/roles'), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.roles = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch roles:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchRole(id) {
			this.loading = true
			try {
				const response = await fetch(generateUrl(`/apps/shillinq/api/v1/roles/${id}`), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.currentRole = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch role:', error)
			} finally {
				this.loading = false
			}
		},

		async saveRole(data) {
			this.loading = true
			try {
				const isUpdate = !!data.id
				const url = isUpdate
					? generateUrl(`/apps/shillinq/api/v1/roles/${data.id}`)
					: generateUrl('/apps/shillinq/api/v1/roles')
				const response = await fetch(url, {
					method: isUpdate ? 'PUT' : 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(data),
				})
				if (response.ok) {
					await this.fetchRoles()
					return await response.json()
				}
			} catch (error) {
				console.error('Failed to save role:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		async deleteRole(id) {
			this.loading = true
			try {
				const response = await fetch(generateUrl(`/apps/shillinq/api/v1/roles/${id}`), {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					await this.fetchRoles()
					return true
				}
				return await response.json()
			} catch (error) {
				console.error('Failed to delete role:', error)
			} finally {
				this.loading = false
			}
			return null
		},
	},
})
