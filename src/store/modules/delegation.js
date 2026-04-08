// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

export const useDelegationStore = defineStore('delegation', {
	state: () => ({
		delegations: [],
		loading: false,
	}),

	actions: {
		async createDelegation(delegationData) {
			const response = await fetch(
				generateUrl('/apps/shillinq/api/v1/delegations'),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(delegationData),
				},
			)
			return response.json()
		},

		async revokeDelegation(id) {
			const response = await fetch(
				generateUrl(`/apps/shillinq/api/v1/delegations/${id}`),
				{
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken },
				},
			)
			return response.json()
		},
	},
})
