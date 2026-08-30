<template>
	<CnSettingsSection
		:name="t('shillinq', 'Pipelinq integration')"
		:description="
			t(
				'shillinq',
				'Configure the pipelinq customer-management connection used to enrich bookings with customer context.',
			)
		">
		<form @submit.prevent="save">
			<div class="form-group">
				<label for="pipelinq-endpoint">{{
					t('shillinq', 'API endpoint')
				}}</label>
				<input
					id="pipelinq-endpoint"
					v-model="form.endpoint"
					type="url"
					autocomplete="off"
					:placeholder="
						t('shillinq', 'https://pipelinq.example.com/api')
					" />
			</div>

			<div class="form-group">
				<label for="pipelinq-token">{{ t('shillinq', 'API token') }}</label>
				<input
					id="pipelinq-token"
					v-model="form.token"
					type="password"
					autocomplete="off"
					:placeholder="
						hasToken
							? maskedPlaceholder
							: t('shillinq', 'Paste your pipelinq API token')
					" />
				<p class="form-hint">
					{{
						hasToken
							? t(
									'shillinq',
									'A token is stored. Leave empty to keep the current token, or paste a new one to rotate it.',
								)
							: t(
									'shillinq',
									'The token is stored in the Nextcloud secrets store and never returned to the browser.',
								)
					}}
				</p>
			</div>

			<div v-if="feedback" class="feedback" :class="[feedback.kind]">
				{{ feedback.message }}
			</div>

			<div class="actions">
				<NcButton variant="primary" type="submit" :disabled="saving">
					{{ saving ? t('shillinq', 'Saving…') : t('shillinq', 'Save') }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="testing || !form.endpoint"
					@click="test">
					{{
						testing
							? t('shillinq', 'Testing…')
							: t('shillinq', 'Test connection')
					}}
				</NcButton>
			</div>
		</form>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

/**
 * Admin settings panel for the pipelinq integration connection
 * (bookings-pipelinq-customer-bridge member 01).
 *
 * Loads the current endpoint + a hasToken flag — the token itself
 * is never returned by the backend, so the token input is rendered
 * masked on reload. Saving without a token preserves the stored
 * one; entering a new token rotates it.
 *
 * @spec openspec/changes/bookings-pipelinq-customer-bridge-01-config-contact-link/tasks.md
 */
export default {
	name: 'PipelinqIntegration',
	components: {
		NcButton,
		CnSettingsSection,
	},

	data() {
		return {
			form: {
				endpoint: '',
				token: '',
			},

			hasToken: false,
			saving: false,
			testing: false,
			feedback: null,
			maskedPlaceholder: '••••••••••••',
		}
	},

	async created() {
		await this.fetchSettings()
	},

	methods: {
		async fetchSettings() {
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/pipelinq/settings'),
					{
						headers: { requesttoken: OC.requestToken },
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.form.endpoint = data.endpoint || ''
					this.hasToken = !!data.hasToken
				}
			} catch (error) {
				console.error('Failed to fetch pipelinq settings:', error)
			}
		},

		async save() {
			this.saving = true
			this.feedback = null
			const body = { endpoint: this.form.endpoint }
			// Absent token => preserve; explicit value (even '') => write through.
			if (this.form.token !== '') {
				body.token = this.form.token
			}
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/pipelinq/settings'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify(body),
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.hasToken = !!data.hasToken
					this.form.token = ''
					this.feedback = {
						kind: 'success',
						message: t('shillinq', 'Pipelinq settings saved.'),
					}
				} else {
					this.feedback = {
						kind: 'error',
						message: t(
							'shillinq',
							'Failed to save pipelinq settings (HTTP {status}).',
							{ status: response.status },
						),
					}
				}
			} catch (error) {
				this.feedback = {
					kind: 'error',
					message: t(
						'shillinq',
						'Failed to save pipelinq settings: {message}.',
						{ message: error.message },
					),
				}
			} finally {
				this.saving = false
			}
		},

		async test() {
			this.testing = true
			this.feedback = null
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/pipelinq/settings/test'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.feedback = {
						kind: data.success ? 'success' : 'error',
						message: data.message,
					}
				} else {
					this.feedback = {
						kind: 'error',
						message: t(
							'shillinq',
							'Test connection failed (HTTP {status}).',
							{ status: response.status },
						),
					}
				}
			} catch (error) {
				this.feedback = {
					kind: 'error',
					message: t('shillinq', 'Test connection failed: {message}.', {
						message: error.message,
					}),
				}
			} finally {
				this.testing = false
			}
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

.form-group input {
	width: 100%;
	max-width: 480px;
}

.form-hint {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.feedback {
	margin: 12px 0;
}

.feedback.success {
	color: var(--color-success);
}

.feedback.error {
	color: var(--color-error);
}

.actions {
	display: flex;
	gap: 8px;
}
</style>
