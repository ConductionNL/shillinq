// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useTeamStore = defineStore('team', {
	state: () => ({
		teams: [],
		currentTeam: null,
		loading: false,
	}),

	getters: {
		getTeams: (state) => state.teams,
		getCurrentTeam: (state) => state.currentTeam,
	},

	actions: {
		async fetchTeams() {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/shillinq/api/v1/teams'), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.teams = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch teams:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchTeam(id) {
			this.loading = true
			try {
				const response = await fetch(generateUrl(`/apps/shillinq/api/v1/teams/${id}`), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.currentTeam = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch team:', error)
			} finally {
				this.loading = false
			}
		},

		async saveTeam(data) {
			this.loading = true
			try {
				const isUpdate = !!data.id
				const url = isUpdate
					? generateUrl(`/apps/shillinq/api/v1/teams/${data.id}`)
					: generateUrl('/apps/shillinq/api/v1/teams')
				const response = await fetch(url, {
					method: isUpdate ? 'PUT' : 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(data),
				})
				if (response.ok) {
					await this.fetchTeams()
					return await response.json()
				}
			} catch (error) {
				console.error('Failed to save team:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		async deleteTeam(id) {
			this.loading = true
			try {
				const response = await fetch(generateUrl(`/apps/shillinq/api/v1/teams/${id}`), {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					await this.fetchTeams()
				}
			} catch (error) {
				console.error('Failed to delete team:', error)
			} finally {
				this.loading = false
			}
		},

		async inviteMember(teamId, email, roleId) {
			try {
				const response = await fetch(generateUrl(`/apps/shillinq/api/v1/teams/${teamId}/invite`), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ email, roleId }),
				})
				return response.ok
			} catch (error) {
				console.error('Failed to invite member:', error)
				return false
			}
		},
	},
})
