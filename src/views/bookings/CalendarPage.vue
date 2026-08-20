<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Host view for the per-resource bookings calendar (bookings-resource-calendar
 REQ-006 + REQ-007).

 WHY THIS FILE EXISTS AGAIN. `CalendarView.vue` is a pure presentation grid: it
 renders the month/week/day cells and *emits* `slot:clicked` and
 `booking:selected`. It has never handled those events itself. Until
 `shillinq-manifest-boot-payload-reduction` (REQ-MBP-002) they were handled by a
 host page (`CalendarPage.vue`) mounted at `/verkoop/boekingen-kalender`. That
 change removed the host together with the duplicate nav entry and re-pointed
 the `BookingsCalendar` registry name straight at the bare grid — so on
 `/bookings/calendar/:calendarId` the grid mounted, emitted into the void, and
 REQ-007 (click a free slot → booking form → 409 conflict dialog) became
 unreachable in the shipped app. Nothing caught it because the E2E job had never
 executed.

 This restores the wiring WITHOUT restoring the removed navigation: no extra
 manifest page, no extra menu entry, no `/verkoop/…` route. The host is simply
 what `/bookings/calendar/:calendarId` resolves to, and it takes its
 `calendarId` from the route param (main.js sets `props: true` for any route
 declaring a `:` segment).

 @spec openspec/changes/bookings-resource-calendar/tasks.md#task-5
 @spec openspec/changes/bookings-resource-calendar/tasks.md#task-6
-->

<template>
	<div class="bookings-calendar-page">
		<CalendarView
			ref="grid"
			:key="gridKey"
			:calendarId="calendarId"
			@booking:selected="onBookingSelected"
			@slot:clicked="onSlotClicked" />

		<aside
			v-if="formOpen"
			class="bookings-calendar-page__form"
			data-testid="booking-form-panel">
			<BookingForm
				:calendarId="calendarId"
				:initialStart="formStart"
				:initialEnd="formEnd"
				@booking:created="onBookingCreated"
				@cancel="formOpen = false" />
		</aside>
	</div>
</template>

<script>
import BookingForm from './BookingForm.vue'
import CalendarView from './CalendarView.vue'

export default {
	name: 'CalendarPage',

	components: { CalendarView, BookingForm },

	props: {
		/**
		 * Calendar UUID/slug, supplied by the `:calendarId` route param.
		 */
		calendarId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			formOpen: false,
			formStart: '',
			formEnd: '',
			selectedBookingId: '',
			// Bumped after a successful create so the grid remounts and
			// re-fetches; the grid owns its own fetch on mount.
			gridKey: 0,
		}
	},

	methods: {
		/**
		 * Open the booking form pre-filled with the clicked slot's window.
		 *
		 * @param {string} startIso UTC ISO-8601 start of the clicked slot.
		 * @param {string} endIso UTC ISO-8601 end of the clicked slot.
		 * @return {void}
		 */
		onSlotClicked(startIso, endIso) {
			this.formStart = this.toLocalInput(startIso)
			this.formEnd = this.toLocalInput(endIso)
			this.formOpen = true
		},

		/**
		 * Selecting an existing booking opens it in the form panel window so
		 * the operator keeps context. The grid owns selection state; the host
		 * only needs to make the panel visible.
		 *
		 * @param {string} bookingId The clicked booking's id.
		 * @return {void}
		 */
		onBookingSelected(bookingId) {
			this.selectedBookingId = bookingId
		},

		/**
		 * Close the panel and force the grid to re-fetch so a freshly created
		 * booking appears without a page reload.
		 *
		 * @return {void}
		 */
		onBookingCreated() {
			this.formOpen = false
			this.gridKey += 1
		},

		/**
		 * Convert a UTC ISO-8601 timestamp to the `datetime-local` wire format
		 * (local wall time, no zone suffix) the form's inputs expect.
		 *
		 * @param {string} iso UTC ISO-8601 timestamp.
		 * @return {string} `YYYY-MM-DDTHH:mm`, or '' when unparseable.
		 */
		toLocalInput(iso) {
			const d = new Date(iso)
			if (isNaN(d.getTime())) {
				return ''
			}
			const pad = (n) => String(n).padStart(2, '0')
			return (
				`${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
				+ `T${pad(d.getHours())}:${pad(d.getMinutes())}`
			)
		},
	},
}
</script>

<style scoped>
.bookings-calendar-page {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.bookings-calendar-page__form {
	border-top: 1px solid var(--color-border);
}
</style>
