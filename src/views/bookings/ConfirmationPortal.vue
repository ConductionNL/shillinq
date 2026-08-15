<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Confirmation Portal — host view for the appointment confirmation flow.
  Mounted by the v2 manifest at /confirm/:appointmentId so a customer can
  open the link from the email, see their appointment details (rendered
  in their local timezone), and confirm the appointment with one click.

  - On mount, calls validateConfirmationToken (REQ-BCF-007 dry-run) so the
    customer never sees a "Confirm" button for an already-expired token.
  - On confirm, posts to the confirm endpoint (REQ-BCF-004), shows a
    success card and offers a redirect back to the bookings dashboard.
  - The portal is wired through the public confirmationApi client so it
    works for an anonymous customer (the token is the auth factor).

  @spec openspec/changes/bookings-confirm-flow/tasks.md#task-13
-->

<template>
	<div class="bookings-confirmation-portal" data-testid="bk-confirm-portal">
		<header class="bookings-confirmation-portal__header">
			<h1>{{ label('Confirm your appointment') }}</h1>
			<p class="bookings-confirmation-portal__subtitle">
				{{ label('Please confirm your appointment to lock the booking.') }}
			</p>
		</header>

		<section
			v-if="loading"
			class="bookings-confirmation-portal__loading"
			data-testid="bk-confirm-loading">
			{{ label('Validating confirmation link…') }}
		</section>

		<section
			v-else-if="error"
			class="bookings-confirmation-portal__error"
			data-testid="bk-confirm-error">
			<h2>{{ label('We could not confirm this appointment') }}</h2>
			<p>{{ errorMessage }}</p>
			<button
				class="bookings-confirmation-portal__resend"
				:disabled="resending"
				data-testid="bk-confirm-resend"
				@click="resend">
				{{ label('Request a new confirmation email') }}
			</button>
		</section>

		<section
			v-else-if="confirmed"
			class="bookings-confirmation-portal__success"
			data-testid="bk-confirm-success">
			<h2>{{ label('Appointment confirmed!') }}</h2>
			<p>
				{{
					label('Your appointment is confirmed. A copy is in your inbox.')
				}}
			</p>
			<dl class="bookings-confirmation-portal__details">
				<dt>{{ label('Service') }}</dt>
				<dd>{{ appointment.serviceName }}</dd>
				<dt>{{ label('Date & time') }}</dt>
				<dd>{{ localTimeLabel }}</dd>
				<dt>{{ label('Timezone') }}</dt>
				<dd>{{ timezoneLabel }}</dd>
				<dt v-if="appointment.location">
					{{ label('Location') }}
				</dt>
				<dd v-if="appointment.location">
					{{ appointment.location }}
				</dd>
			</dl>
		</section>

		<section
			v-else
			class="bookings-confirmation-portal__form"
			data-testid="bk-confirm-form">
			<h2>{{ appointment.serviceName || label('Your appointment') }}</h2>
			<dl class="bookings-confirmation-portal__details">
				<dt>{{ label('Date & time') }}</dt>
				<dd>{{ localTimeLabel }}</dd>
				<dt>{{ label('Timezone') }}</dt>
				<dd>{{ timezoneLabel }}</dd>
				<dt v-if="appointment.location">
					{{ label('Location') }}
				</dt>
				<dd v-if="appointment.location">
					{{ appointment.location }}
				</dd>
				<dt v-if="appointment.notes">
					{{ label('Notes') }}
				</dt>
				<dd v-if="appointment.notes">
					{{ appointment.notes }}
				</dd>
			</dl>

			<button
				class="bookings-confirmation-portal__confirm"
				:disabled="confirming"
				data-testid="bk-confirm-button"
				@click="confirm">
				{{
					confirming ? label('Confirming…') : label('Confirm appointment')
				}}
			</button>
		</section>
	</div>
</template>

<script>
import confirmationApi from '../../api/confirmationApi.js'

const REASON_MESSAGES = {
	expired: 'This confirmation link has expired. Please request a new one.',
	already_redeemed: 'This appointment has already been confirmed.',
	revoked: 'This confirmation link is no longer valid; a newer one was sent.',
	invalid: 'This confirmation link is not valid.',
	not_found: 'We could not find this appointment. Please check your email link.',
	missing_appointment_id:
		'This confirmation link is missing required information.',
	invalid_input: 'This confirmation link is incomplete.',
	or_unavailable:
		'The booking service is temporarily unavailable. Please try again later.',
}

export default {
	name: 'ConfirmationPortal',
	data() {
		return {
			loading: true,
			confirming: false,
			resending: false,
			confirmed: false,
			error: null,
			appointment: {
				appointmentId: '',
				serviceName: '',
				startTime: '',
				endTime: '',
				location: '',
				notes: '',
				status: '',
				confirmationDeadline: '',
			},
		}
	},

	computed: {
		token() {
			const params = new URLSearchParams(window.location.search)
			return params.get('token') || ''
		},

		appointmentIdFromUrl() {
			// Manifest route is /confirm/:appointmentId so the segment lives in
			// the URL path immediately after `/confirm/`. Fall back to query.
			const match = window.location.pathname.match(/\/confirm\/([^/?#]+)/)
			if (match) {
				return decodeURIComponent(match[1])
			}
			const params = new URLSearchParams(window.location.search)
			return params.get('appointmentId') || ''
		},

		localTimeLabel() {
			if (!this.appointment.startTime) {
				return ''
			}
			try {
				const start = new Date(this.appointment.startTime)
				return start.toLocaleString(undefined, {
					weekday: 'short',
					year: 'numeric',
					month: 'short',
					day: '2-digit',
					hour: '2-digit',
					minute: '2-digit',
					timeZoneName: 'short',
				})
			} catch {
				return this.appointment.startTime
			}
		},

		timezoneLabel() {
			try {
				return Intl.DateTimeFormat().resolvedOptions().timeZone
			} catch {
				return 'UTC'
			}
		},

		errorMessage() {
			if (!this.error) {
				return ''
			}
			return this.label(REASON_MESSAGES[this.error] || REASON_MESSAGES.invalid)
		},
	},

	mounted() {
		this.validate()
	},

	methods: {
		label(key) {
			if (typeof t === 'function') {
				return t('shillinq', key)
			}
			return key
		},

		async validate() {
			this.loading = true
			this.error = null
			try {
				if (!this.token || !this.appointmentIdFromUrl) {
					this.error = 'invalid_input'
					return
				}
				const result = await confirmationApi.validateConfirmationToken(
					this.appointmentIdFromUrl,
					this.token,
				)
				if (!result || result.ok !== true) {
					this.error = (result && result.reason) || 'invalid'
					return
				}
				if (result.appointment) {
					this.appointment = { ...this.appointment, ...result.appointment }
				}
			} catch (e) {
				this.error = 'or_unavailable'
			} finally {
				this.loading = false
			}
		},

		async confirm() {
			if (this.confirming) {
				return
			}
			this.confirming = true
			try {
				const result = await confirmationApi.confirmAppointment(
					this.appointmentIdFromUrl,
					this.token,
				)
				if (result && result.appointment) {
					this.appointment = { ...this.appointment, ...result.appointment }
				}
				this.confirmed = true
			} catch (e) {
				const status = (e && e.response && e.response.status) || 0
				if (status === 403) {
					this.error = 'expired'
				} else if (status === 401) {
					this.error = 'invalid'
				} else {
					this.error = 'or_unavailable'
				}
			} finally {
				this.confirming = false
			}
		},

		async resend() {
			if (this.resending) {
				return
			}
			this.resending = true
			try {
				await confirmationApi.resendConfirmationEmail(
					this.appointmentIdFromUrl,
				)
				this.error = null
				this.confirmed = false
				this.appointment.status = 'pending_confirmation'
			} catch (e) {
				// Anonymous users cannot resend — keep the expired error.
			} finally {
				this.resending = false
			}
		},
	},
}
</script>

<style scoped>
.bookings-confirmation-portal {
	max-width: 36rem;
	margin: 2rem auto;
	padding: 1.5rem;
	font-family: var(--font-family, system-ui), sans-serif;
}

.bookings-confirmation-portal__header {
	margin-bottom: 1rem;
}

.bookings-confirmation-portal__subtitle {
	color: var(--color-text-maxcontrast, #555);
}

.bookings-confirmation-portal__details {
	display: grid;
	grid-template-columns: 8rem 1fr;
	gap: 0.25rem 1rem;
	margin: 1rem 0;
}

.bookings-confirmation-portal__details dt {
	font-weight: 600;
	color: var(--color-text-maxcontrast, #555);
}

.bookings-confirmation-portal__confirm,
.bookings-confirmation-portal__resend {
	display: inline-block;
	padding: 0.5rem 1.25rem;
	border: 0;
	border-radius: var(--border-radius-pill, 0.5rem);
	background: var(--color-primary, #0066cc);
	color: var(--color-primary-text, #fff);
	font-weight: 600;
	cursor: pointer;
}

.bookings-confirmation-portal__confirm:disabled,
.bookings-confirmation-portal__resend:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.bookings-confirmation-portal__error h2 {
	color: var(--color-error, #c00);
}

.bookings-confirmation-portal__success h2 {
	color: var(--color-success, #0a0);
}
</style>
