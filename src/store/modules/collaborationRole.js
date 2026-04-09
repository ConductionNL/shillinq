// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useCollaborationRoleStore = defineStore('collaborationRole', {
	state: () => ({
		roles: [],
		loading: false,
	}),
	getters: {
		rolesForTarget: (state) => (targetType, targetId) => {
			return state.roles.filter(r => r.targetType === targetType && r.targetId === targetId)
		},
	},
	actions: {
		async fetchRoles(targetType, targetId) {
			this.loading = true
			try {
				const url = generateUrl(`/apps/shillinq/api/v1/collaboration/roles?targetType=${targetType}&targetId=${targetId}`)
				const response = await fetch(url, { headers: { requesttoken: OC.requestToken } })
				if (response.ok) {
					const data = await response.json()
					// Merge, don't replace all
					const otherRoles = this.roles.filter(r => !(r.targetType === targetType && r.targetId === targetId))
					this.roles = [...otherRoles, ...(data.results || data)]
				}
			} catch (error) {
				console.error('Failed to fetch collaboration roles:', error)
			} finally {
				this.loading = false
			}
		},
		async createRole(role) {
			const url = generateUrl('/apps/shillinq/api/v1/collaboration/roles')
			const response = await fetch(url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken },
				body: JSON.stringify(role),
			})
			if (response.ok) {
				const data = await response.json()
				this.roles.push(data)
				return data
			}
			throw new Error('Failed to create collaboration role')
		},
		async deleteRole(id) {
			const url = generateUrl(`/apps/shillinq/api/v1/collaboration/roles/${id}`)
			const response = await fetch(url, {
				method: 'DELETE',
				headers: { requesttoken: OC.requestToken },
			})
			if (response.ok) {
				this.roles = this.roles.filter(r => r.id !== id)
			}
		},
	},
})
