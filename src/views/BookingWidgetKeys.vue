<template>
	<div class="shillinq-widget-keys">
		<h2>{{ t('shillinq', 'Widget API keys') }}</h2>
		<p>{{ t('shillinq', 'Self-service booking widget') }}</p>

		<div class="shillinq-widget-keys__field">
			<label for="wsw-business-id">{{ t('shillinq', 'Business ID') }}</label>
			<input
				id="wsw-business-id"
				v-model.trim="businessId"
				type="text"
				class="shillinq-widget-keys__input" />
		</div>

		<div class="shillinq-widget-keys__field">
			<label for="wsw-administration-id">{{
				t('shillinq', 'Administration ID')
			}}</label>
			<input
				id="wsw-administration-id"
				v-model.trim="administrationId"
				type="text"
				class="shillinq-widget-keys__input" />
		</div>

		<div class="shillinq-widget-keys__actions">
			<NcButton
				variant="primary"
				:disabled="!businessId || !administrationId || busy"
				@click="generate">
				{{ t('shillinq', 'Generate key') }}
			</NcButton>
			<NcButton
				variant="secondary"
				:disabled="!businessId || busy"
				@click="rotate">
				{{ t('shillinq', 'Rotate key') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="!businessId || busy"
				@click="revoke">
				{{ t('shillinq', 'Revoke key') }}
			</NcButton>
		</div>

		<div v-if="plaintextKey" class="shillinq-widget-keys__key" role="status">
			<strong>{{
				t('shillinq', 'Copy this key now — it will not be shown again')
			}}</strong>
			<code>{{ plaintextKey }}</code>
		</div>

		<div v-if="message" class="shillinq-widget-keys__message">
			{{ message }}
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

/**
 * Admin view for generating, rotating, and revoking widget API keys
 * (REQ-WSW-009). The plaintext key is shown exactly once after generation.
 */
export default {
	name: 'BookingWidgetKeys',
	components: { NcButton },
	data() {
		return {
			businessId: '',
			administrationId: '',
			plaintextKey: '',
			message: '',
			busy: false,
		}
	},

	methods: {
		async post(path, body) {
			this.busy = true
			this.message = ''
			try {
				const response = await fetch(
					generateUrl(`/apps/shillinq/api/${path}`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
						},
						body: JSON.stringify(body),
					},
				)
				return await response.json()
			} catch (error) {
				console.error('Widget key request failed:', error)
				return { success: false, message: String(error) }
			} finally {
				this.busy = false
			}
		},

		/**
		 * Mint the FIRST key for a businessId (REQ-WSW-009 §1).
		 *
		 * This used to post to `keys/rotate`, which refuses with "No active key
		 * found for businessId." whenever the business has no key yet — so the
		 * button labelled "Generate key" could never generate one. It also sent
		 * `administrationId: this.businessId`, a field `rotate` never reads;
		 * `create` genuinely needs the tenant boundary, so it is its own input.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		async generate() {
			this.plaintextKey = ''
			const result = await this.post('widget/admin/keys/create', {
				businessId: this.businessId,
				administrationId: this.administrationId,
			})
			if (result.success && result.apiKey) {
				this.plaintextKey = result.apiKey
			}
			this.message = result.message || ''
		},

		/**
		 * Replace an existing key; the predecessor keeps working for 7 days.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		async rotate() {
			this.plaintextKey = ''
			const result = await this.post('widget/admin/keys/rotate', {
				businessId: this.businessId,
			})
			if (result.success && result.apiKey) {
				this.plaintextKey = result.apiKey
			}
			this.message = result.message || ''
		},

		async revoke() {
			this.plaintextKey = ''
			const result = await this.post('widget/admin/keys/revoke', {
				businessId: this.businessId,
			})
			this.message = result.message || ''
		},
	},
}
</script>

<style scoped>
.shillinq-widget-keys {
	max-width: 720px;
	padding: 16px;
}

.shillinq-widget-keys__field {
	display: flex;
	flex-direction: column;
	margin: 16px 0;
	gap: 4px;
}

.shillinq-widget-keys__input {
	max-width: 320px;
	padding: 8px;
}

.shillinq-widget-keys__actions {
	display: flex;
	gap: 8px;
}

.shillinq-widget-keys__key {
	margin-top: 16px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.shillinq-widget-keys__key code {
	display: block;
	margin-top: 8px;
	word-break: break-all;
}

.shillinq-widget-keys__message {
	margin-top: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
