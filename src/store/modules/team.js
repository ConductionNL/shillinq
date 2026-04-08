// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useTeamStore = defineStore('team', {
	state: () => ({
		teams: [],
		team: null,
		loading: false,
	}),

	actions: {
		async fetchTeams() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/v1/teams'),
					{ headers: { requesttoken: OC.requestToken } },
				)
				const data = await response.json()
				this.teams = data.results || []
			} catch (error) {
				console.error('Failed to fetch teams:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchTeam(id) {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl(`/apps/shillinq/api/v1/teams/${id}`),
					{ headers: { requesttoken: OC.requestToken } },
				)
				this.team = await response.json()
			} catch (error) {
				console.error('Failed to fetch team:', error)
			} finally {
				this.loading = false
			}
		},

		async saveTeam(teamData) {
			const url = teamData.id
				? generateUrl(`/apps/shillinq/api/v1/teams/${teamData.id}`)
				: generateUrl('/apps/shillinq/api/v1/teams')
			const method = teamData.id ? 'PUT' : 'POST'

			const response = await fetch(url, {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
				},
				body: JSON.stringify(teamData),
			})
			return response.json()
		},

		async inviteMember(teamId, email, roleId) {
			const response = await fetch(
				generateUrl(`/apps/shillinq/api/v1/teams/${teamId}/invite`),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ email, roleId }),
				},
			)
			return response.json()
		},
	},
})
