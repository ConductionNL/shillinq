<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="user-preferences">
		<Breadcrumb :items="breadcrumbItems" />

		<h2>{{ t('shillinq', 'User Preferences') }}</h2>

		<div class="user-preferences__field">
			<label for="pref-language">{{ t('shillinq', 'Language') }}</label>
			<select id="pref-language" v-model="preferences.language" @change="save">
				<option value="en">
					English
				</option>
				<option value="nl">
					Nederlands
				</option>
			</select>
		</div>

		<div class="user-preferences__field">
			<label for="pref-dateformat">{{ t('shillinq', 'Date Format') }}</label>
			<select id="pref-dateformat" v-model="preferences.dateFormat" @change="save">
				<option value="DD-MM-YYYY">
					DD-MM-YYYY
				</option>
				<option value="YYYY-MM-DD">
					YYYY-MM-DD
				</option>
				<option value="MM/DD/YYYY">
					MM/DD/YYYY
				</option>
			</select>
		</div>

		<div class="user-preferences__field">
			<NcCheckboxRadioSwitch
				:checked="preferences.notificationEmail"
				@update:checked="val => { preferences.notificationEmail = val; save() }">
				{{ t('shillinq', 'Email notifications') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div class="user-preferences__field">
			<NcCheckboxRadioSwitch
				:checked="preferences.notificationInApp"
				@update:checked="val => { preferences.notificationInApp = val; save() }">
				{{ t('shillinq', 'In-app notifications') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div v-if="successMessage" class="user-preferences__success">
			{{ successMessage }}
		</div>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import Breadcrumb from '../../components/Breadcrumb.vue'

export default {
	name: 'UserPreferencesPage',
	components: {
		NcCheckboxRadioSwitch,
		Breadcrumb,
	},

	data() {
		return {
			preferences: {
				language: 'en',
				dateFormat: 'DD-MM-YYYY',
				notificationEmail: true,
				notificationInApp: true,
			},
			successMessage: '',
		}
	},

	computed: {
		breadcrumbItems() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Settings'), route: '/settings' },
				{ label: t('shillinq', 'Preferences') },
			]
		},
	},

	async mounted() {
		await this.loadPreferences()
	},

	methods: {
		async loadPreferences() {
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/preferences'),
					{ headers: { requesttoken: OC.requestToken } },
				)
				if (response.ok) {
					const data = await response.json()
					this.preferences = { ...this.preferences, ...data }
				}
			} catch (error) {
				console.error('Failed to load preferences:', error)
			}
		},

		async save() {
			this.successMessage = ''
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/preferences'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify(this.preferences),
					},
				)
				if (response.ok) {
					this.successMessage = t('shillinq', 'Preferences saved successfully')
					setTimeout(() => { this.successMessage = '' }, 3000)
				}
			} catch (error) {
				console.error('Failed to save preferences:', error)
			}
		},
	},
}
</script>

<style scoped>
.user-preferences {
	padding: 8px 4px 24px;
	max-width: 600px;
}

.user-preferences__field {
	margin-bottom: 16px;
}

.user-preferences__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.user-preferences__success {
	color: var(--color-success);
	margin-top: 12px;
}
</style>
