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

			<div class="form-group">
				<label for="portal-return-address">{{
					t('shillinq', 'Portal return address')
				}}</label>
				<input
					id="portal-return-address"
					v-model="form.portal_payment_redirect_url"
					type="url"
					inputmode="url"
					autocomplete="url"
					aria-describedby="portal-return-address-hint" />
				<p id="portal-return-address-hint" class="hint">
					{{
						t(
							'shillinq',
							'Where the checkout sends a person back after paying. Use the address of your portal, starting with https://. Leave it empty to send them to Nextcloud.',
						)
					}}
				</p>
			</div>

			<div v-if="successMessage" class="success-message">
				{{ successMessage }}
			</div>

			<div v-if="errorMessage" class="error-message" role="alert">
				{{ errorMessage }}
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
				portal_payment_redirect_url: '',
			},

			saving: false,
			successMessage: '',
			errorMessage: '',
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
		this.form.portal_payment_redirect_url =
			settingsStore.settings?.portal_payment_redirect_url || ''
	},

	methods: {
		/**
		 * Persist the configuration form via the settings store and show a
		 * success or a failure message.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-app-administration/tasks.md#task-1
		 * @spec openspec/changes/portal-pay-row-action-keys/specs/portal-payment-initiation/spec.md (REQ-SPPI-010)
		 */
		async save() {
			this.saving = true
			this.successMessage = ''
			this.errorMessage = ''
			const settingsStore = useSettingsStore()
			const result = await settingsStore.saveSettings(this.form)
			if (result) {
				this.successMessage = t('shillinq', 'Settings saved successfully')
			} else {
				this.errorMessage = t(
					'shillinq',
					'The settings were not saved. Check that the portal return address starts with https://.',
				)
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

.hint {
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.error-message {
	color: var(--color-error);
	margin-bottom: 8px;
}
</style>
