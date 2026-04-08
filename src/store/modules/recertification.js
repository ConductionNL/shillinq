// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useRecertificationStore = defineStore('recertification', {
	state: () => ({
		campaigns: [],
		loading: false,
	}),

	actions: {
		async fetchCampaigns() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/v1/recertifications'),
					{ headers: { requesttoken: OC.requestToken } },
				)
				const data = await response.json()
				this.campaigns = data.results || []
			} catch (error) {
				console.error('Failed to fetch recertification campaigns:', error)
			} finally {
				this.loading = false
			}
		},

		async createCampaign(campaignData) {
			const response = await fetch(
				generateUrl('/apps/shillinq/api/v1/recertifications'),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(campaignData),
				},
			)
			return response.json()
		},

		async submitReview(campaignId, decisions) {
			const response = await fetch(
				generateUrl(`/apps/shillinq/api/v1/recertifications/${campaignId}/review`),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify({ decisions }),
				},
			)
			return response.json()
		},
	},
})
