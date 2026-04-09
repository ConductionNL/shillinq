<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-6.1
-->
<template>
	<div class="app-settings-page">
		<Breadcrumb :items="breadcrumbs" />
		<header class="app-settings-page__header">
			<h2>{{ t('shillinq', 'App Settings') }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="64" />

		<template v-else>
			<div v-for="category in categories"
				:key="category"
				class="app-settings-page__group">
				<h3 class="app-settings-page__category">{{ categoryLabel(category) }}</h3>
				<div v-for="setting in settingsByCategory(category)"
					:key="setting.id"
					class="app-settings-page__field">
					<label :for="'setting-' + setting.key">{{ setting.key }}</label>
					<template v-if="setting.dataType === 'boolean'">
						<NcCheckboxRadioSwitch
							:id="'setting-' + setting.key"
							:checked="setting.value === 'true'"
							:disabled="setting.editable === false"
							type="switch"
							@update:checked="val => updateSetting(setting, String(val))" />
					</template>
					<template v-else>
						<input
							:id="'setting-' + setting.key"
							:value="setting.value"
							:disabled="setting.editable === false"
							type="text"
							class="app-settings-page__input"
							@change="e => updateSetting(setting, e.target.value)">
					</template>
					<span v-if="setting.editable === false" class="app-settings-page__readonly">
						{{ t('shillinq', 'Managed by configuration') }}
					</span>
				</div>
			</div>

			<div v-if="successMessage" class="app-settings-page__success">
				{{ successMessage }}
			</div>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useAppSettingsStore } from '../../store/modules/appSettings.js'
import Breadcrumb from '../../components/Breadcrumb.vue'

export default {
	name: 'AppSettingsPage',
	components: {
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		Breadcrumb,
	},
	data() {
		return {
			loading: true,
			successMessage: '',
		}
	},
	computed: {
		appSettingsStore() {
			return useAppSettingsStore()
		},
		settings() {
			return this.appSettingsStore.collections?.appSettings || []
		},
		categories() {
			const cats = [...new Set(this.settings.map(s => s.category || 'general'))]
			return cats.sort()
		},
		breadcrumbs() {
			return [
				{ label: t('shillinq', 'Shillinq'), route: '/' },
				{ label: t('shillinq', 'Settings') },
			]
		},
	},
	async mounted() {
		await this.appSettingsStore.fetchCollection('appSettings')
		this.loading = false
	},
	methods: {
		categoryLabel(category) {
			const labels = {
				appearance: t('shillinq', 'Appearance'),
				notifications: t('shillinq', 'Notifications'),
				integrations: t('shillinq', 'Integrations'),
				general: t('shillinq', 'General'),
			}
			return labels[category] || category
		},
		settingsByCategory(category) {
			return this.settings.filter(s => (s.category || 'general') === category)
		},
		async updateSetting(setting, newValue) {
			this.successMessage = ''
			await this.appSettingsStore.saveObject('appSettings', {
				...setting,
				value: newValue,
			})
			this.successMessage = t('shillinq', 'Setting saved successfully')
			setTimeout(() => {
				this.successMessage = ''
			}, 3000)
		},
	},
}
</script>

<style scoped>
.app-settings-page {
	padding: 8px 4px 24px;
	max-width: 800px;
}

.app-settings-page__header h2 {
	margin: 0 0 16px;
	font-size: 22px;
	font-weight: 600;
}

.app-settings-page__group {
	margin-bottom: 24px;
}

.app-settings-page__category {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 12px;
	padding-bottom: 4px;
	border-bottom: 1px solid var(--color-border);
	text-transform: capitalize;
}

.app-settings-page__field {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 8px;
}

.app-settings-page__field label {
	min-width: 180px;
	font-weight: 500;
}

.app-settings-page__input {
	flex: 1;
	max-width: 300px;
	padding: 6px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
}

.app-settings-page__readonly {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-style: italic;
}

.app-settings-page__success {
	color: var(--color-success);
	margin-top: 12px;
}
</style>
