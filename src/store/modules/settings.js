import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		settings: {},
		loading: false,
		hasOpenRegisters: false,
		isAdmin: false,
	}),

	getters: {
		getSettings: (state) => state.settings,
		getIsAdmin: (state) => state.isAdmin,
	},

	actions: {
		/**
		 * Load app settings from the shillinq settings endpoint and derive
		 * the hasOpenRegisters / isAdmin flags from the response.
		 *
		 * @return {Promise<object|null>} The settings payload, or null on error.
		 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
		 */
		async fetchSettings() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/settings'),
					{
						headers: { requesttoken: OC.requestToken },
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.settings = data
					this.hasOpenRegisters = !!data?.openregisters
					this.isAdmin = !!data?.isAdmin
					return data
				}
			} catch (error) {
				console.error('Failed to fetch settings:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Persist app settings to the shillinq settings endpoint.
		 *
		 * @param {object} settings The settings to save.
		 * @return {Promise<object|null>} The refreshed settings, or null on error.
		 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
		 */
		async saveSettings(settings) {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/settings'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify(settings),
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.settings = data
					return data
				}
			} catch (error) {
				console.error('Failed to save settings:', error)
			} finally {
				this.loading = false
			}
			return null
		},
	},
})
