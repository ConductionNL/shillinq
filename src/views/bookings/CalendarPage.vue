<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Calendar Page — host view wiring the CalendarView grid to the BookingForm
  modal. Mounted by the v2 manifest as the BookingsCalendar custom page so
  the operator can pick a calendar from a dropdown, browse the month/week/day
  grid, click a slot to open the form, and see freshly-created bookings
  appear immediately.

  @spec openspec/changes/bookings-resource-calendar/tasks.md#task-5
  @spec openspec/changes/bookings-resource-calendar/tasks.md#task-6
-->

<template>
	<div class="bookings-calendar-page">
		<header class="bookings-calendar-page__header">
			<h1>{{ label('Bookings calendar') }}</h1>
			<label class="bookings-calendar-page__picker">
				{{ label('Calendar') }}
				<select v-model="selectedCalendarId" data-testid="bk-calendar-picker">
					<option v-for="cal in calendars" :key="cal.id" :value="cal.id">
						{{ cal.id }} – {{ cal.resource }}
					</option>
				</select>
			</label>
		</header>

		<CalendarView
			v-if="selectedCalendarId"
			ref="grid"
			:calendar-id="selectedCalendarId"
			:view="currentView"
			:start-date="startDate"
			:time-zone="selectedTimeZone"
			@update:view="currentView = $event"
			@booking:selected="onBookingSelected"
			@slot:clicked="onSlotClicked" />

		<aside v-if="formOpen" class="bookings-calendar-page__form" data-testid="bk-form-panel">
			<BookingForm
				:calendar-id="selectedCalendarId"
				:initial="formInitial"
				:time-zone="selectedTimeZone"
				@booking:created="onBookingCreated"
				@cancel="formOpen = false" />
		</aside>
	</div>
</template>

<script>
import CalendarView from '../../components/CalendarView.vue'
import BookingForm from '../../components/BookingForm.vue'

export default {
	name: 'CalendarPage',
	components: { CalendarView, BookingForm },
	data() {
		return {
			calendars: [],
			selectedCalendarId: '',
			currentView: 'month',
			startDate: new Date().toISOString().slice(0, 10),
			formOpen: false,
			formInitial: {},
		}
	},
	computed: {
		selectedTimeZone() {
			const cal = this.calendars.find(c => c.id === this.selectedCalendarId)
			return (cal && cal.timeZone) ? cal.timeZone : 'Europe/Amsterdam'
		},
	},
	mounted() {
		this.loadCalendars()
	},
	methods: {
		label(key) {
			if (typeof t === 'function') {
				return t('shillinq', key)
			}
			return key
		},
		async loadCalendars() {
			try {
				const base = (window.OC && window.OC.generateUrl)
					? window.OC.generateUrl('/apps/shillinq/api/v2/calendars')
					: '/apps/shillinq/api/v2/calendars'
				const response = await fetch(base, { credentials: 'include', headers: { Accept: 'application/json' } })
				if (!response.ok) {
					return
				}
				const body = await response.json()
				this.calendars = Array.isArray(body.calendars) ? body.calendars : []
				if (!this.selectedCalendarId && this.calendars.length > 0) {
					this.selectedCalendarId = this.calendars[0].id
				}
			} catch {
				// Surface as empty list — the CalendarView will show its own load error if used.
			}
		},
		onBookingSelected(id) {
			// Hook for future edit flow; for now just log via console.
			if (typeof window !== 'undefined' && window.console) {
				window.console.info('shillinq: booking selected', id)
			}
		},
		onSlotClicked(slot) {
			this.formInitial = {
				title: '',
				startTime: slot.startTime,
				endTime: slot.endTime,
				attendee: '',
				status: 'pending',
			}
			this.formOpen = true
		},
		onBookingCreated() {
			this.formOpen = false
			if (this.$refs.grid && typeof this.$refs.grid.refresh === 'function') {
				this.$refs.grid.refresh()
			}
		},
	},
}
</script>

<style scoped>
.bookings-calendar-page { display: flex; flex-direction: column; gap: 16px; padding: 16px; }
.bookings-calendar-page__header { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; }
.bookings-calendar-page__picker { display: flex; align-items: center; gap: 8px; }
.bookings-calendar-page__form {
	border: 1px solid var(--color-border, #ccc);
	border-radius: 8px;
	background: var(--color-main-background, #fff);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}
</style>
