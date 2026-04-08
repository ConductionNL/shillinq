<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="app-settings-page">
		<Breadcrumb :items="breadcrumbItems" />

		<h2>{{ t('shillinq', 'App Settings') }}</h2>

		<div v-for="category in categories" :key="category" class="app-settings-page__section">
			<h3>{{ categoryLabel(category) }}</h3>
			<div v-for="setting in settingsByCategory(category)"
				:key="setting.id"
				class="app-settings-page__field">
				<label :for="'setting-' + setting.key">{{ setting.key }}</label>
				<div v-if="setting.editable !== false">
					<NcCheckboxRadioSwitch
						v-if="setting.dataType === 'boolean'"
						:checked="setting.value === 'true'"
						@update:checked="val => updateSetting(setting, String(val))">
						{{ setting.key }}
					</NcCheckboxRadioSwitch>
					<input
						v-else
						:id="'setting-' + setting.key"
						:value="setting.value"
						type="text"
						class="app-settings-page__input"
						@change="e => updateSetting(setting, e.target.value)">
				</div>
				<div v-else class="app-settings-page__readonly">
					{{ setting.value }}
					<span class="app-settings-page__readonly-hint">
						{{ t('shillinq', 'Managed by configuration') }}
					</span>
				</div>
			</div>
		</div>

		<div v-if="successMessage" class="app-settings-page__success">
			{{ successMessage }}
		</div>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useAppSettingsStore } from '../../store/modules/appSettings.js'
import Breadcrumb from '../../components/Breadcrumb.vue'

export default {
	name: 'AppSettingsPage',
	components: {
		NcCheckboxRadioSwitch,
		Breadcrumb,
	},

	data() {
		return {
			successMessage: '',
		}
	},

	computed: {
		appSettingsStore() {
			return useAppSettingsStore()
		},
		allSettings() {
			return this.appSettingsStore.objectList ?? []
		},
		categories() {
			const cats = new Set(this.allSettings.map(s => s.category || 'general'))
			return [...cats].sort()
		},
		breadcrumbItems() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Settings') },
			]
		},
	},

	async mounted() {
		await this.appSettingsStore.fetchObjects()
	},

	methods: {
		settingsByCategory(category) {
			return this.allSettings.filter(s => (s.category || 'general') === category)
		},
		categoryLabel(category) {
			const labels = {
				general: t('shillinq', 'General'),
				appearance: t('shillinq', 'Appearance'),
				notifications: t('shillinq', 'Notifications'),
				integrations: t('shillinq', 'Integrations'),
			}
			return labels[category] || category
		},
		async updateSetting(setting, value) {
			await this.appSettingsStore.saveObject({ ...setting, value })
			this.successMessage = t('shillinq', 'Setting saved successfully')
			setTimeout(() => { this.successMessage = '' }, 3000)
		},
	},
}
</script>

<style scoped>
.app-settings-page {
	padding: 8px 4px 24px;
	max-width: 800px;
}

.app-settings-page__section {
	margin-bottom: 24px;
}

.app-settings-page__section h3 {
	margin: 0 0 12px;
	font-size: 16px;
	font-weight: 600;
	text-transform: capitalize;
}

.app-settings-page__field {
	margin-bottom: 12px;
}

.app-settings-page__field label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.app-settings-page__input {
	width: 100%;
	max-width: 400px;
}

.app-settings-page__readonly {
	color: var(--color-text-maxcontrast);
}

.app-settings-page__readonly-hint {
	font-size: 12px;
	font-style: italic;
	margin-left: 8px;
}

.app-settings-page__success {
	color: var(--color-success);
	margin-top: 12px;
}
</style>
