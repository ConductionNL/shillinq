<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Booking Form — create/edit a booking on a calendar (REQ-007 of
  bookings-resource-calendar). Posts to
  /api/v2/calendars/{calendarId}/bookings; on 409 Conflict opens the
  BookingConflictDialog (modal isolation per Hydra gate-13, see
  src/modals/BookingConflictDialog.vue) and lets the operator retry
  with overrideConflict=true.

  Validation (REQ-007):
    - title, attendee non-empty,
    - endTime strictly after startTime,
    - duration ≥15 minutes.

  Emits booking:created on a successful POST so the host view can refresh
  the calendar grid.

  @spec openspec/changes/bookings-resource-calendar/tasks.md#task-6
-->

<template>
	<form
		class="bk-form"
		data-testid="bk-form"
		@submit.prevent="submit">
		<div class="bk-form__field">
			<label :for="ids.title">{{ label('Title') }}</label>
			<input
				:id="ids.title"
				v-model="form.title"
				type="text"
				required
				maxlength="255"
				data-testid="bk-form-title">
		</div>
		<div class="bk-form__field">
			<label :for="ids.start">{{ label('Start time') }}</label>
			<input
				:id="ids.start"
				v-model="form.startTime"
				type="datetime-local"
				required
				data-testid="bk-form-start">
		</div>
		<div class="bk-form__field">
			<label :for="ids.end">{{ label('End time') }}</label>
			<input
				:id="ids.end"
				v-model="form.endTime"
				type="datetime-local"
				required
				data-testid="bk-form-end">
		</div>
		<div class="bk-form__field">
			<label :for="ids.attendee">{{ label('Attendee') }}</label>
			<input
				:id="ids.attendee"
				v-model="form.attendee"
				type="text"
				required
				maxlength="255"
				data-testid="bk-form-attendee">
		</div>
		<fieldset class="bk-form__field bk-form__status">
			<legend>{{ label('Status') }}</legend>
			<label>
				<input v-model="form.status"
					type="radio"
					value="pending"
					data-testid="bk-form-status-pending">
				{{ label('Pending') }}
			</label>
			<label>
				<input v-model="form.status"
					type="radio"
					value="confirmed"
					data-testid="bk-form-status-confirmed">
				{{ label('Confirmed') }}
			</label>
		</fieldset>

		<p v-if="error"
			class="bk-form__error"
			role="alert"
			data-testid="bk-form-error">
			{{ error }}
		</p>

		<div class="bk-form__actions">
			<button
				type="button"
				class="bk-form__btn"
				data-testid="bk-form-cancel"
				@click="$emit('cancel')">
				{{ label('Cancel') }}
			</button>
			<button
				type="submit"
				class="bk-form__btn bk-form__btn--primary"
				:disabled="submitting"
				data-testid="bk-form-submit">
				{{ submitting ? label('Submitting…') : label('Create booking') }}
			</button>
		</div>

		<BookingConflictDialog
			:open="conflictOpen"
			:conflicts="conflicts"
			:time-zone="timeZone"
			@confirm="onConflictConfirm"
			@cancel="onConflictCancel" />
	</form>
</template>

<script>
import BookingConflictDialog from '../modals/BookingConflictDialog.vue'

let uidCounter = 0
const nextUid = () => `bk-${++uidCounter}`

/**
 * Booking form (REQ-007).
 *
 * Props:
 *   - calendarId: string (required) — target calendar.
 *   - initial: optional object — seed values from a slot:clicked event.
 *
 * Emits:
 *   - booking:created(booking) — successful POST.
 *   - cancel — user closed the form without saving.
 */
export default {
	name: 'BookingForm',
	components: { BookingConflictDialog },
	props: {
		calendarId: { type: String, required: true },
		initial: { type: Object, default: () => ({}) },
		timeZone: { type: String, default: 'Europe/Amsterdam' },
	},
	emits: ['booking:created', 'cancel'],
	data() {
		const uid = nextUid()
		return {
			ids: {
				title: `${uid}-title`,
				start: `${uid}-start`,
				end: `${uid}-end`,
				attendee: `${uid}-attendee`,
			},
			form: {
				title: this.initial.title || '',
				startTime: this.toLocal(this.initial.startTime || ''),
				endTime: this.toLocal(this.initial.endTime || ''),
				attendee: this.initial.attendee || '',
				status: this.initial.status || 'pending',
			},
			error: '',
			submitting: false,
			conflicts: [],
			conflictOpen: false,
		}
	},
	methods: {
		label(key) {
			if (typeof t === 'function') {
				return t('shillinq', key)
			}
			return key
		},
		toLocal(iso) {
			if (!iso) {
				return ''
			}
			// datetime-local expects YYYY-MM-DDTHH:MM (no seconds, no zone).
			const d = new Date(iso)
			if (Number.isNaN(d.valueOf())) {
				return ''
			}
			const pad = n => n.toString().padStart(2, '0')
			return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}T${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}`
		},
		toIsoUtc(local) {
			if (!local) {
				return ''
			}
			// Interpret the local input as already-UTC (the calendar grid emits
			// UTC slots), so we just append :00Z.
			return `${local}:00Z`
		},
		validate() {
			if (!this.form.title.trim()) {
				return this.label('Title is required')
			}
			if (!this.form.attendee.trim()) {
				return this.label('Attendee is required')
			}
			if (!this.form.startTime || !this.form.endTime) {
				return this.label('Start and end times are required')
			}
			const startMs = new Date(this.toIsoUtc(this.form.startTime)).valueOf()
			const endMs = new Date(this.toIsoUtc(this.form.endTime)).valueOf()
			if (Number.isNaN(startMs) || Number.isNaN(endMs)) {
				return this.label('Start and end times are not valid')
			}
			if (endMs <= startMs) {
				return this.label('End time must be after start time')
			}
			const durationMin = (endMs - startMs) / 60000
			if (durationMin < 15) {
				return this.label('Booking duration must be at least 15 minutes')
			}
			return ''
		},
		async submit(override = false) {
			this.error = this.validate()
			if (this.error) {
				return
			}
			this.submitting = true
			try {
				const url = this.buildUrl()
				const payload = {
					title: this.form.title.trim(),
					startTime: this.toIsoUtc(this.form.startTime),
					endTime: this.toIsoUtc(this.form.endTime),
					attendee: this.form.attendee.trim(),
					status: this.form.status,
					overrideConflict: !!override,
				}
				const headers = {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					requesttoken: this.requestToken(),
				}
				const response = await fetch(url, {
					method: 'POST',
					credentials: 'include',
					headers,
					body: JSON.stringify(payload),
				})
				if (response.status === 201) {
					const body = await response.json()
					this.$emit('booking:created', body)
					this.resetForm()
					return
				}
				if (response.status === 409) {
					const body = await response.json()
					this.conflicts = Array.isArray(body.conflicts) ? body.conflicts : []
					this.conflictOpen = true
					return
				}
				const body = await response.json().catch(() => ({}))
				this.error = body.error || this.label('Could not create booking (HTTP {code})', { code: response.status })
			} catch (err) {
				this.error = this.label('Could not create booking: {message}', { message: err.message })
			} finally {
				this.submitting = false
			}
		},
		buildUrl() {
			if (window.OC && window.OC.generateUrl) {
				return window.OC.generateUrl(`/apps/shillinq/api/v2/calendars/${encodeURIComponent(this.calendarId)}/bookings`)
			}
			return `/apps/shillinq/api/v2/calendars/${encodeURIComponent(this.calendarId)}/bookings`
		},
		requestToken() {
			// Nextcloud exposes the CSRF token on a meta tag and via OC.requestToken.
			if (typeof window !== 'undefined' && window.OC && typeof window.OC.requestToken === 'string') {
				return window.OC.requestToken
			}
			const meta = typeof document !== 'undefined' ? document.querySelector('meta[name="requesttoken"]') : null
			return meta ? meta.getAttribute('content') : ''
		},
		onConflictConfirm() {
			this.conflictOpen = false
			// Retry with overrideConflict = true.
			this.submit(true)
		},
		onConflictCancel() {
			this.conflictOpen = false
		},
		resetForm() {
			this.form.title = ''
			this.form.startTime = ''
			this.form.endTime = ''
			this.form.attendee = ''
			this.form.status = 'pending'
			this.conflicts = []
			this.error = ''
		},
	},
}
</script>

<style scoped>
.bk-form { display: flex; flex-direction: column; gap: 10px; padding: 12px; }

.bk-form__field { display: flex; flex-direction: column; gap: 4px; }

.bk-form__field label { font-weight: 600; }

.bk-form__field input,
.bk-form__field input[type='datetime-local'] {
	padding: 6px;
	border: 1px solid var(--color-border, #ccc);
	border-radius: 4px;
}

.bk-form__status { display: flex; gap: 12px; align-items: center; border: none; padding: 0; }

.bk-form__status legend { font-weight: 600; padding-right: 8px; }

.bk-form__status label { font-weight: normal; display: inline-flex; align-items: center; gap: 4px; }

.bk-form__error { color: var(--color-error, #c62828); }

.bk-form__actions { display: flex; gap: 8px; justify-content: flex-end; }

.bk-form__btn {
	padding: 6px 14px;
	border: 1px solid var(--color-border, #ccc);
	background: transparent;
	cursor: pointer;
}

.bk-form__btn--primary {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border-color: var(--color-primary-element, #0082c9);
}

.bk-form__btn[disabled] { opacity: 0.6; cursor: progress; }
</style>
