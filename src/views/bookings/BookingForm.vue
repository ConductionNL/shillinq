<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Booking creation form (bookings-resource-calendar REQ-007). Collects title,
 start/end time, attendee, and status; validates duration (>= 15 minutes) and
 ordering (endTime after startTime); POSTs to the calendar bookings endpoint;
 and surfaces a conflict dialog on a 409 response with an override option.
-->
<template>
	<form class="booking-form" data-testid="bk-form" @submit.prevent="submit">
		<div class="booking-form__field">
			<label for="booking-title">{{ t('shillinq', 'Title') }}</label>
			<input
				id="booking-title"
				v-model="form.title"
				type="text"
				data-testid="bk-form-title"
				:placeholder="t('shillinq', 'Booking title')">
		</div>

		<div class="booking-form__field">
			<label for="booking-start">{{ t('shillinq', 'Start time') }}</label>
			<input
				id="booking-start"
				v-model="form.startTime"
				type="datetime-local"
				data-testid="bk-form-start">
		</div>

		<div class="booking-form__field">
			<label for="booking-end">{{ t('shillinq', 'End time') }}</label>
			<input
				id="booking-end"
				v-model="form.endTime"
				type="datetime-local"
				data-testid="bk-form-end">
		</div>

		<div class="booking-form__field">
			<label for="booking-attendee">{{ t('shillinq', 'Attendee') }}</label>
			<input
				id="booking-attendee"
				v-model="form.attendee"
				type="text"
				data-testid="bk-form-attendee"
				:placeholder="t('shillinq', 'Attendee name')">
		</div>

		<div class="booking-form__field">
			<span class="booking-form__legend">{{ t('shillinq', 'Status') }}</span>
			<label class="booking-form__radio">
				<input
					v-model="form.status"
					type="radio"
					value="pending"
					data-testid="bk-form-status-pending">
				{{ t('shillinq', 'Pending') }}
			</label>
			<label class="booking-form__radio">
				<input
					v-model="form.status"
					type="radio"
					value="confirmed"
					data-testid="bk-form-status-confirmed">
				{{ t('shillinq', 'Confirmed') }}
			</label>
		</div>

		<p v-if="validationError" class="booking-form__error" data-testid="bk-form-error">
			{{ validationError }}
		</p>

		<div class="booking-form__actions">
			<NcButton
				variant="tertiary"
				native-type="button"
				data-testid="bk-form-cancel"
				@click="cancel">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				native-type="submit"
				data-testid="bk-form-submit"
				:disabled="submitting">
				{{ submitting ? t('shillinq', 'Saving…') : t('shillinq', 'Create Booking') }}
			</NcButton>
		</div>

		<BookingConflictDialog
			v-if="showConflictDialog"
			:conflicts="conflicts"
			@confirm="confirmDespiteConflict"
			@cancel="showConflictDialog = false" />
	</form>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
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
		 * Pre-filled field values, supplied by the host page when the operator
		 * opens the form by clicking a calendar slot (REQ-007). `startTime` and
		 * `endTime` are UTC ISO-8601 strings as emitted by CalendarView's
		 * `slot:clicked`; they are converted to the local wall-clock format the
		 * `datetime-local` inputs require.
		 */
		initial: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['booking:created', 'cancel'],

	data() {
		return {
			form: {
				title: '',
				startTime: '',
				endTime: '',
				attendee: '',
				status: 'pending',
			},
			validationError: '',
			submitting: false,
			showConflictDialog: false,
			conflicts: [],
		}
	},

	watch: {
		initial: {
			handler(value) {
				this.applyInitial(value)
			},
			deep: true,
		},
	},

	mounted() {
		this.applyInitial(this.initial)
	},

	methods: {
		/**
		 * Copy the host-supplied slot values into the form. Only keys the
		 * caller actually provided are applied, so an empty `initial` leaves
		 * the operator with a blank form.
		 *
		 * @param {object} initial The initial field values.
		 * @return {void}
		 */
		applyInitial(initial) {
			const source = (initial || {})
			if (typeof source.title === 'string') {
				this.form.title = source.title
			}
			if (typeof source.attendee === 'string') {
				this.form.attendee = source.attendee
			}
			if (source.status === 'pending' || source.status === 'confirmed') {
				this.form.status = source.status
			}
			if (source.startTime) {
				this.form.startTime = this.toLocalInput(source.startTime)
			}
			if (source.endTime) {
				this.form.endTime = this.toLocalInput(source.endTime)
			}
		},

		/**
		 * Convert a UTC ISO-8601 timestamp into the `YYYY-MM-DDTHH:mm` local
		 * wall-clock string a `datetime-local` input accepts. Returns '' when
		 * the input is not parseable, so a bad value blanks the field rather
		 * than writing `Invalid Date` into it.
		 *
		 * @param {string} iso UTC ISO-8601 timestamp.
		 * @return {string} Local `datetime-local` value, or ''.
		 */
		toLocalInput(iso) {
			const date = new Date(iso)
			if (isNaN(date.getTime())) {
				return ''
			}
			const pad = (n) => String(n).padStart(2, '0')
			return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
				+ `T${pad(date.getHours())}:${pad(date.getMinutes())}`
		},

		/**
		 * Abandon the booking without creating it (REQ-007). The host page owns
		 * the panel, so the form reports the intent and the host closes it.
		 *
		 * @return {void}
		 */
		cancel() {
			this.showConflictDialog = false
			this.$emit('cancel')
		},
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
		 * dialog (unless force is set). On 201, emit booking:created so the
		 * host page closes the form (REQ-007).
		 *
		 * The override flag is sent as the `overrideConflict` body field —
		 * that is the parameter name `CalendarController::createBooking()`
		 * reads. It was previously sent as a `?force=1` query string, a name
		 * the controller never looks at, so confirming past a conflict simply
		 * re-ran the identical check and returned the same 409.
		 *
		 * @param {boolean} force Override conflict detection.
		 * @return {Promise<void>}
		 */
		async postBooking(force) {
			this.submitting = true
			try {
				const url = generateUrl(
					'/apps/shillinq/api/v2/calendars/{calendarId}/bookings',
					{ calendarId: this.calendarId },
				)

				const payload = {
					title: this.form.title,
					startTime: this.toUtcIso(this.form.startTime),
					endTime: this.toUtcIso(this.form.endTime),
					attendee: this.form.attendee,
					status: this.form.status,
					overrideConflict: (force === true),
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
					this.showConflictDialog = false
					this.reset()
					// The host page owns the form panel and closes it on this
					// event, so a successful create leaves the calendar — not a
					// blank form the operator has to dismiss by hand (REQ-007).
					this.$emit('booking:created', created)
					return
				}

				const errBody = await response.json().catch(() => ({}))
				this.validationError = errBody.error || t('shillinq', 'Failed to create booking')
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
			this.form = { title: '', startTime: '', endTime: '', attendee: '', status: 'pending' }
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
	gap: 8px;
	justify-content: flex-end;
}
</style>
