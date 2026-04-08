// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useRoleStore = defineStore('role', {
	state: () => ({
		roles: [],
		role: null,
		loading: false,
	}),

	actions: {
		async fetchRoles() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/v1/roles'),
					{ headers: { requesttoken: OC.requestToken } },
				)
				const data = await response.json()
				this.roles = data.results || []
			} catch (error) {
				console.error('Failed to fetch roles:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchRole(id) {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl(`/apps/shillinq/api/v1/roles/${id}`),
					{ headers: { requesttoken: OC.requestToken } },
				)
				this.role = await response.json()
			} catch (error) {
				console.error('Failed to fetch role:', error)
			} finally {
				this.loading = false
			}
		},

		async saveRole(roleData) {
			const url = roleData.id
				? generateUrl(`/apps/shillinq/api/v1/roles/${roleData.id}`)
				: generateUrl('/apps/shillinq/api/v1/roles')
			const method = roleData.id ? 'PUT' : 'POST'

			const response = await fetch(url, {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
				},
				body: JSON.stringify(roleData),
			})
			return response.json()
		},

		async deleteRole(id) {
			const response = await fetch(
				generateUrl(`/apps/shillinq/api/v1/roles/${id}`),
				{
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				},
			)
			return response.json()
		},
	},
})
