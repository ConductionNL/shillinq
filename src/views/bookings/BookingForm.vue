<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Booking creation form (bookings-resource-calendar REQ-007). Collects title,
 start/end time, attendee, and status; validates duration (>= 15 minutes) and
 ordering (endTime after startTime); POSTs to the calendar bookings endpoint;
 and surfaces a conflict dialog on a 409 response with an override option.
-->
<template>
	<form class="booking-form" data-testid="booking-form" @submit.prevent="submit">
		<div class="booking-form__field">
			<label for="booking-title">{{ t('shillinq', 'Title') }}</label>
			<input
				id="booking-title"
				v-model="form.title"
				type="text"
				data-testid="booking-form-title"
				:placeholder="t('shillinq', 'Booking title')" />
		</div>

		<div class="booking-form__field">
			<label for="booking-start">{{ t('shillinq', 'Start time') }}</label>
			<input
				id="booking-start"
				v-model="form.startTime"
				type="datetime-local"
				data-testid="booking-form-start" />
		</div>

		<div class="booking-form__field">
			<label for="booking-end">{{ t('shillinq', 'End time') }}</label>
			<input
				id="booking-end"
				v-model="form.endTime"
				type="datetime-local"
				data-testid="booking-form-end" />
		</div>

		<div class="booking-form__field">
			<label for="booking-attendee">{{ t('shillinq', 'Attendee') }}</label>
			<input
				id="booking-attendee"
				v-model="form.attendee"
				type="text"
				data-testid="booking-form-attendee"
				:placeholder="t('shillinq', 'Attendee name')" />
		</div>

		<div class="booking-form__field">
			<span class="booking-form__legend">{{ t('shillinq', 'Status') }}</span>
			<label class="booking-form__radio">
				<input
					v-model="form.status"
					type="radio"
					value="pending"
					data-testid="booking-form-status-pending" />
				{{ t('shillinq', 'Pending') }}
			</label>
			<label class="booking-form__radio">
				<input
					v-model="form.status"
					type="radio"
					value="confirmed"
					data-testid="booking-form-status-confirmed" />
				{{ t('shillinq', 'Confirmed') }}
			</label>
		</div>

		<p
			v-if="validationError"
			class="booking-form__error"
			data-testid="booking-form-error">
			{{ validationError }}
		</p>

		<div class="booking-form__actions">
			<NcButton data-testid="booking-form-cancel" @click="$emit('cancel')">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				type="submit"
				data-testid="booking-form-submit"
				:disabled="submitting">
				{{
					submitting
						? t('shillinq', 'Saving…')
						: t('shillinq', 'Create Booking')
				}}
			</NcButton>
		</div>

		<!--
			`:open` is REQUIRED, not decorative. BookingConflictDialog gates its
			entire template on `v-if="open"` with `default: false`, so mounting it
			behind only the parent's `v-if` created a component that rendered
			NOTHING. The 409 override flow was therefore unreachable: the API
			correctly answers 409 (asserted directly in
			tests/e2e/bookings-resource-calendar.spec.ts) and the operator saw no
			dialog, no error, and no created booking.
		-->
		<BookingConflictDialog
			v-if="showConflictDialog"
			:open="showConflictDialog"
			:conflicts="conflicts"
			@confirm="confirmDespiteConflict"
			@cancel="showConflictDialog = false" />
	</form>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import BookingConflictDialog from '../../modals/BookingConflictDialog.vue'

const MIN_DURATION_MS = 15 * 60 * 1000

export default {
	name: 'BookingForm',

	components: {
		NcButton,
		BookingConflictDialog,
	},

	props: {
		/**
		 * The calendar UUID/slug to create the booking on.
		 */
		calendarId: {
			type: String,
			required: true,
		},

		/**
		 * `datetime-local` value to pre-fill the start field with — the host
		 * passes the clicked slot's window so the operator does not retype it.
		 */
		initialStart: {
			type: String,
			default: '',
		},

		/**
		 * `datetime-local` value to pre-fill the end field with.
		 */
		initialEnd: {
			type: String,
			default: '',
		},
	},

	emits: ['booking:created', 'cancel'],

	data() {
		return {
			form: {
				title: '',
				startTime: this.initialStart,
				endTime: this.initialEnd,
				attendee: '',
				status: 'pending',
			},

			validationError: '',
			submitting: false,
			showConflictDialog: false,
			conflicts: [],
		}
	},

	methods: {
		/**
		 * Validate the form. Returns an error string, or '' when valid.
		 *
		 * @return {string} The first validation error, or '' when valid.
		 */
		validate() {
			if (!this.form.title.trim()) {
				return t('shillinq', 'Title is required')
			}
			if (!this.form.attendee.trim()) {
				return t('shillinq', 'Attendee is required')
			}
			if (!this.form.startTime || !this.form.endTime) {
				return t('shillinq', 'Start and end time are required')
			}
			const start = new Date(this.form.startTime).getTime()
			const end = new Date(this.form.endTime).getTime()
			if (isNaN(start) || isNaN(end)) {
				return t('shillinq', 'Start and end time must be valid')
			}
			if (end <= start) {
				return t('shillinq', 'End time must be after start time')
			}
			if (end - start < MIN_DURATION_MS) {
				return t('shillinq', 'Booking duration must be at least 15 minutes')
			}
			return ''
		},

		/**
		 * Validate and submit the booking (without overriding conflicts).
		 *
		 * @return {Promise<void>}
		 */
		async submit() {
			this.validationError = this.validate()
			if (this.validationError) {
				return
			}
			await this.postBooking(false)
		},

		/**
		 * Re-submit the booking forcing past a detected conflict.
		 *
		 * @return {Promise<void>}
		 */
		async confirmDespiteConflict() {
			this.showConflictDialog = false
			await this.postBooking(true)
		},

		/**
		 * POST the booking to the API. On a 409 conflict, show the conflict
		 * dialog (unless force is set). On 201, emit booking:created and reset.
		 *
		 * @param {boolean} force Override conflict detection.
		 * @return {Promise<void>}
		 */
		async postBooking(force) {
			this.submitting = true
			try {
				const url =
					generateUrl(
						'/apps/shillinq/api/v2/calendars/{calendarId}/bookings',
						{ calendarId: this.calendarId },
					) + (force ? '?force=1' : '')

				const payload = {
					title: this.form.title,
					startTime: this.toUtcIso(this.form.startTime),
					endTime: this.toUtcIso(this.form.endTime),
					attendee: this.form.attendee,
					status: this.form.status,
				}

				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
					},
					body: JSON.stringify(payload),
				})

				if (response.status === 409) {
					const body = await response.json()
					this.conflicts = body.conflicts || []
					this.showConflictDialog = true
					return
				}

				if (response.status === 201) {
					const created = await response.json()
					this.$emit('booking:created', created)
					this.reset()
					return
				}

				const errBody = await response.json().catch(() => ({}))
				this.validationError =
					errBody.error || t('shillinq', 'Failed to create booking')
			} catch (error) {
				this.validationError = t('shillinq', 'Failed to create booking')
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Convert a datetime-local value (local wall time) to a UTC ISO string.
		 *
		 * @param {string} local The datetime-local input value.
		 * @return {string} UTC ISO-8601 timestamp.
		 */
		toUtcIso(local) {
			return new Date(local).toISOString()
		},

		/**
		 * Reset the form to its empty state.
		 *
		 * @return {void}
		 */
		reset() {
			this.form = {
				title: '',
				startTime: this.initialStart,
				endTime: this.initialEnd,
				attendee: '',
				status: 'pending',
			}
			this.validationError = ''
			this.conflicts = []
		},
	},
}
</script>

<style scoped>
.booking-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
	padding: 16px;
}

.booking-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.booking-form__legend {
	font-weight: bold;
}

.booking-form__radio {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-right: 12px;
}

.booking-form__error {
	color: var(--color-error);
}

.booking-form__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
