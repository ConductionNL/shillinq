<template>
	<CnSettingsSection
		:name="t('shillinq', 'Configuration')"
		:description="t('shillinq', 'Configure the app settings')">
		<form @submit.prevent="save">
			<div class="form-group">
				<label for="register">{{ t('shillinq', 'Register') }}</label>
				<input
					id="register"
					v-model="form.register"
					type="text"
					:placeholder="t('shillinq', 'OpenRegister register ID')" />
			</div>

			<div v-if="successMessage" class="success-message">
				{{ successMessage }}
			</div>

			<NcButton variant="primary" type="submit" :disabled="saving">
				{{ saving ? t('shillinq', 'Saving…') : t('shillinq', 'Save') }}
			</NcButton>
		</form>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'Settings',
	components: {
		NcButton,
		CnSettingsSection,
	},

	data() {
		return {
			form: {
				register: '',
			},

			saving: false,
			successMessage: '',
		}
	},

	/**
	 * Prefill the register form field from the loaded settings store.
	 *
	 * @spec exclude UI glue — one-line form prefill from store state; no
	 * observable app behavior beyond seeding the input.
	 */
	created() {
		const settingsStore = useSettingsStore()
		this.form.register = settingsStore.settings?.register || ''
	},

	methods: {
		/**
		 * Persist the configuration form via the settings store and show a
		 * success message.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
		 */
		async save() {
			this.saving = true
			this.successMessage = ''
			const settingsStore = useSettingsStore()
			const result = await settingsStore.saveSettings(this.form)
			if (result) {
				this.successMessage = t('shillinq', 'Settings saved successfully')
			}
			this.saving = false
		},
	},
}
</script>

<style scoped>
.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.success-message {
	color: var(--color-success);
	margin-bottom: 8px;
}
</style>
