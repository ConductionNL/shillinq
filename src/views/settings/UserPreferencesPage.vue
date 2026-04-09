<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-10.1
-->
<template>
	<div class="user-preferences">
		<Breadcrumb :items="breadcrumbs" />
		<h2>{{ t('shillinq', 'User Preferences') }}</h2>

		<NcLoadingIcon v-if="loading" :size="64" />

		<form v-else @submit.prevent="save">
			<div class="user-preferences__field">
				<label for="pref-language">{{ t('shillinq', 'Language') }}</label>
				<select id="pref-language" v-model="form.language">
					<option value="">{{ t('shillinq', 'Use system default') }}</option>
					<option value="en">English</option>
					<option value="nl">Nederlands</option>
				</select>
			</div>

			<div class="user-preferences__field">
				<label for="pref-dateFormat">{{ t('shillinq', 'Date Format') }}</label>
				<select id="pref-dateFormat" v-model="form.dateFormat">
					<option value="">{{ t('shillinq', 'Use system default') }}</option>
					<option value="DD-MM-YYYY">DD-MM-YYYY</option>
					<option value="YYYY-MM-DD">YYYY-MM-DD</option>
					<option value="MM/DD/YYYY">MM/DD/YYYY</option>
				</select>
			</div>

			<div class="user-preferences__field">
				<label>{{ t('shillinq', 'Email Notifications') }}</label>
				<NcCheckboxRadioSwitch
					:checked="form.notificationEmail === 'true'"
					type="switch"
					@update:checked="val => form.notificationEmail = String(val)" />
			</div>

			<div class="user-preferences__field">
				<label>{{ t('shillinq', 'In-App Notifications') }}</label>
				<NcCheckboxRadioSwitch
					:checked="form.notificationInApp === 'true'"
					type="switch"
					@update:checked="val => form.notificationInApp = String(val)" />
			</div>

			<div v-if="successMessage" class="user-preferences__success">
				{{ successMessage }}
			</div>
			<div v-if="errorMessage" class="user-preferences__error">
				{{ errorMessage }}
			</div>

			<NcButton type="primary" native-type="submit" :disabled="saving">
				{{ saving ? t('shillinq', 'Saving...') : t('shillinq', 'Save Preferences') }}
			</NcButton>
		</form>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import Breadcrumb from '../../components/Breadcrumb.vue'

export default {
	name: 'UserPreferencesPage',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		Breadcrumb,
	},
	data() {
		return {
			loading: true,
			saving: false,
			successMessage: '',
			errorMessage: '',
			form: {
				language: '',
				dateFormat: '',
				notificationEmail: 'true',
				notificationInApp: 'true',
			},
		}
	},
	computed: {
		breadcrumbs() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Settings'), route: '/settings' },
				{ label: t('shillinq', 'Preferences') },
			]
		},
	},
	async mounted() {
		try {
			const response = await fetch(generateUrl('/apps/shillinq/api/preferences'), {
				headers: { requesttoken: OC.requestToken },
			})
			if (response.ok) {
				const data = await response.json()
				Object.keys(this.form).forEach(key => {
					if (data[key]) this.form[key] = data[key]
				})
			}
		} catch {
			// Use defaults
		}
		this.loading = false
	},
	methods: {
		async save() {
			this.saving = true
			this.successMessage = ''
			this.errorMessage = ''
			try {
				const response = await fetch(generateUrl('/apps/shillinq/api/preferences'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(this.form),
				})
				if (response.ok) {
					this.successMessage = t('shillinq', 'Preferences saved successfully')
				} else {
					this.errorMessage = t('shillinq', 'Failed to save preferences. Please try again.')
				}
			} catch {
				this.errorMessage = t('shillinq', 'Failed to save preferences. Please try again.')
			}
			this.saving = false
		},
	},
}
</script>

<style scoped>
.user-preferences {
	padding: 8px 4px 24px;
	max-width: 600px;
}

.user-preferences h2 {
	margin: 0 0 16px;
	font-size: 22px;
	font-weight: 600;
}

.user-preferences__field {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 12px;
}

.user-preferences__field label {
	min-width: 180px;
	font-weight: 500;
}

.user-preferences__success {
	color: var(--color-success);
	margin-bottom: 8px;
}

.user-preferences__error {
	color: var(--color-error);
	margin-bottom: 8px;
}
</style>
