// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Pinia store for CollaborationRole objects.
 *
 * @spec openspec/changes/collaboration/tasks.md#task-3.2
 */
export const useCollaborationRoleStore = defineStore('collaborationRole', {
	state: () => ({
		roles: [],
		loading: false,
	}),

	getters: {
		/**
		 * Filter roles by target.
		 *
		 * @param {object} state - Store state
		 * @return {Function} Filter function accepting targetType and targetId
		 */
		rolesForTarget: (state) => (targetType, targetId) => {
			return state.roles.filter(
				(r) => r.targetType === targetType && r.targetId === targetId,
			)
		},
	},

	actions: {
		/**
		 * Fetch roles for a specific target.
		 *
		 * @param {string} targetType - Entity type
		 * @param {string} targetId - Object ID
		 * @return {Promise<Array>} Roles list
		 */
		async fetchByTarget(targetType, targetId) {
			this.loading = true
			try {
				const url = generateUrl('/apps/shillinq/api/v1/collaboration/roles')
					+ `?targetType=${encodeURIComponent(targetType)}&targetId=${encodeURIComponent(targetId)}`
				const response = await fetch(url, {
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
			return this.roles
		},

		/**
		 * Assign a new role.
		 *
		 * @param {object} data - Role data
		 * @return {Promise<object|null>} Created role or null
		 */
		async assignRole(data) {
			try {
				const url = generateUrl('/apps/shillinq/api/v1/collaboration/roles')
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(data),
				})
				if (response.ok) {
					const role = await response.json()
					this.roles.push(role)
					return role
				}
			} catch (error) {
				console.error('Failed to assign role:', error)
			}
			return null
		},

		/**
		 * Revoke a role.
		 *
		 * @param {string} id - Role ID
		 * @return {Promise<boolean>} Success status
		 */
		async revokeRole(id) {
			try {
				const url = generateUrl(`/apps/shillinq/api/v1/collaboration/roles/${id}`)
				const response = await fetch(url, {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.roles = this.roles.filter((r) => r.id !== id)
					return true
				}
			} catch (error) {
				console.error('Failed to revoke role:', error)
			}
			return false
		},
	},
})
