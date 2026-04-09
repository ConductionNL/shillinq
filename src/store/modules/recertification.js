// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useRecertificationStore = defineStore('recertification', {
	state: () => ({
		campaigns: [],
		loading: false,
	}),

	getters: {
		getCampaigns: (state) => state.campaigns,
	},

	actions: {
		async fetchCampaigns() {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/shillinq/api/v1/recertifications'), {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					this.campaigns = await response.json()
				}
			} catch (error) {
				console.error('Failed to fetch recertification campaigns:', error)
			} finally {
				this.loading = false
			}
		},

		async createCampaign(data) {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/shillinq/api/v1/recertifications'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(data),
				})
				if (response.ok) {
					await this.fetchCampaigns()
					return await response.json()
				}
			} catch (error) {
				console.error('Failed to create campaign:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		async submitReview(campaignId, decisions) {
			this.loading = true
			try {
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
				if (response.ok) {
					return await response.json()
				}
			} catch (error) {
				console.error('Failed to submit review:', error)
			} finally {
				this.loading = false
			}
			return null
		},
	},
})
