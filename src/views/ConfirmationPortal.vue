<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Customer-facing appointment confirmation portal (REQ-BCF-007).

 Custom page component (registry kind:"page", ADR-036/ADR-024 justified):
 the portal is token-driven — it validates an unguessable confirmation token
 on load (dry-run, no side effects), renders the appointment in the customer's
 local timezone, and confirms on a single button press. This flow has no
 register list/detail equivalent, so it does not fit a built-in declarative
 page type and is registered explicitly in src/registry.js.

 @spec openspec/changes/bookings-confirm-flow/tasks.md#task-13
-->
<template>
	<div class="confirmation-portal">
		<div v-if="loading" class="confirmation-portal__state">
			<NcLoadingIcon :size="44" />
			<p>{{ t('shillinq', 'Checking your confirmation link…') }}</p>
		</div>

		<NcEmptyContent
			v-else-if="error"
			:name="t('shillinq', 'This confirmation link is no longer valid')"
			:description="errorDescription">
			<template #icon>
				<AlertCircleOutlineIcon :size="44" />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="confirmed"
			:name="t('shillinq', 'Appointment confirmed!')"
			:description="t('shillinq', 'Your appointment is confirmed. You can close this page.')">
			<template #icon>
				<CheckCircleOutlineIcon :size="44" />
			</template>
		</NcEmptyContent>

		<div v-else-if="appointment" class="confirmation-portal__card">
			<h2>{{ t('shillinq', 'Confirm Your Appointment') }}</h2>
			<dl class="confirmation-portal__details">
				<dt>{{ t('shillinq', 'Service') }}</dt>
				<dd>{{ appointment.serviceName }}</dd>
				<dt v-if="appointment.providerName">
					{{ t('shillinq', 'Provider') }}
				</dt>
				<dd v-if="appointment.providerName">
					{{ appointment.providerName }}
				</dd>
				<dt>{{ t('shillinq', 'When') }}</dt>
				<dd>{{ localStartTime }}</dd>
				<dt v-if="appointment.location">
					{{ t('shillinq', 'Location') }}
				</dt>
				<dd v-if="appointment.location">
					{{ appointment.location }}
				</dd>
				<dt v-if="appointment.notes">
					{{ t('shillinq', 'Notes') }}
				</dt>
				<dd v-if="appointment.notes">
					{{ appointment.notes }}
				</dd>
				<dt>{{ t('shillinq', 'Time zone') }}</dt>
				<dd>{{ displayTimezone }}</dd>
			</dl>
			<NcButton
				type="primary"
				:disabled="submitting"
				@click="onConfirm">
				<template v-if="submitting" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('shillinq', 'Confirm Appointment') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircleOutlineIcon from 'vue-material-design-icons/AlertCircleOutline.vue'
import CheckCircleOutlineIcon from 'vue-material-design-icons/CheckCircleOutline.vue'
import { validateConfirmationToken, confirmAppointment } from '../api/confirmationApi.js'

export default {
	name: 'ConfirmationPortal',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		AlertCircleOutlineIcon,
		CheckCircleOutlineIcon,
	},

	data() {
		return {
			loading: true,
			submitting: false,
			error: false,
			errorReason: '',
			confirmed: false,
			appointment: null,
		}
	},

	computed: {
		/**
		 * The raw token read from the URL query string.
		 *
		 * @return {string} The token, or empty string.
		 */
		token() {
			const params = new URLSearchParams(window.location.search)
			return params.get('token') || ''
		},

		/**
		 * The appointment id read from the URL query string.
		 *
		 * @return {string} The appointment id, or empty string.
		 */
		appointmentId() {
			const params = new URLSearchParams(window.location.search)
			return params.get('appointmentId') || ''
		},

		/**
		 * The timezone shown to the customer.
		 *
		 * @return {string} An IANA timezone name.
		 */
		displayTimezone() {
			return (this.appointment && this.appointment.customerTimezone)
				|| Intl.DateTimeFormat().resolvedOptions().timeZone
				|| 'Europe/Amsterdam'
		},

		/**
		 * The appointment start formatted in the customer's local timezone.
		 *
		 * @return {string} The formatted local time.
		 */
		localStartTime() {
			if (!this.appointment || !this.appointment.startTime) {
				return ''
			}
			try {
				return new Intl.DateTimeFormat(undefined, {
					dateStyle: 'full',
					timeStyle: 'short',
					timeZone: this.displayTimezone,
				}).format(new Date(this.appointment.startTime))
			} catch {
				return this.appointment.startTime
			}
		},

		/**
		 * A human-readable error description for the empty state.
		 *
		 * @return {string} The localized description.
		 */
		errorDescription() {
			if (this.errorReason === 'expired') {
				return this.t('shillinq', 'Token has expired. Please request a new confirmation email.')
			}
			if (this.errorReason === 'redeemed') {
				return this.t('shillinq', 'Token has already been used.')
			}
			if (this.errorReason === 'revoked') {
				return this.t('shillinq', 'Token is no longer valid; request a new confirmation email.')
			}
			return this.t('shillinq', 'Please request a new confirmation email.')
		},
	},

	async mounted() {
		await this.validate()
	},

	methods: {
		/**
		 * Validate the token on load (dry-run; no side effects).
		 *
		 * @return {Promise<void>}
		 */
		async validate() {
			this.loading = true
			if (!this.token || !this.appointmentId) {
				this.error = true
				this.loading = false
				return
			}
			try {
				const result = await validateConfirmationToken(this.appointmentId, this.token)
				if (result && result.valid) {
					this.appointment = result.appointment
				} else {
					this.error = true
					this.errorReason = (result && result.reason) || ''
				}
			} catch (e) {
				this.error = true
				this.errorReason = (e.response && e.response.data && e.response.data.reason) || ''
			} finally {
				this.loading = false
			}
		},

		/**
		 * Confirm the appointment.
		 *
		 * @return {Promise<void>}
		 */
		async onConfirm() {
			this.submitting = true
			try {
				const result = await confirmAppointment(this.appointmentId, this.token)
				if (result && result.success) {
					this.confirmed = true
				} else {
					this.error = true
					this.errorReason = (result && result.reason) || ''
				}
			} catch (e) {
				this.error = true
				this.errorReason = (e.response && e.response.data && e.response.data.reason) || ''
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.confirmation-portal {
	max-width: 540px;
	margin: 0 auto;
	padding: 2rem 1rem;
}

.confirmation-portal__state {
	text-align: center;
	padding: 3rem 0;
}

.confirmation-portal__card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 1.5rem;
}

.confirmation-portal__details {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 0.5rem 1rem;
	margin: 1rem 0 1.5rem;
}

.confirmation-portal__details dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.confirmation-portal__details dd {
	margin: 0;
}
</style>
