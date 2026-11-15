/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */
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
				const answer = await settingsRequest(tokenGet())
				if (answer !== null) {
					applyLoaded(this, answer.data)
					return answer.data
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
				const answer = await settingsRequest(jsonPost(settings))
				if (answer !== null) {
					this.settings = answer.data
					return answer.data
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

/**
 * Call the shillinq settings endpoint.
 *
 * @param {object} init The fetch options.
 * @return {Promise<{data: object|null}|null>} The parsed body when the answer is ok, else null.
 */
async function settingsRequest(init) {
	const response = await fetch(generateUrl('/apps/shillinq/api/settings'), init)
	if (!response.ok) {
		return null
	}
	return { data: await response.json() }
}

/**
 * The fetch options that POST settings as JSON with the request token.
 *
 * @param {object} settings The settings to send.
 * @return {object}
 */
function jsonPost(settings) {
	return {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
		},
		body: JSON.stringify(settings),
	}
}

/**
 * Store loaded settings with the flags they carry.
 *
 * @param {object} store The settings store.
 * @param {object|null} data The loaded settings.
 * @return {void}
 */
function applyLoaded(store, data) {
	store.settings = data
	store.hasOpenRegisters = !!data?.openregisters
	store.isAdmin = !!data?.isAdmin
}

/**
 * The fetch options of a GET with the request token.
 *
 * @return {object}
 */
function tokenGet() {
	return { headers: { requesttoken: OC.requestToken } }
}
