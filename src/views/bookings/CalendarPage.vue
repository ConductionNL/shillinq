<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Bookings calendar host page (bookings-resource-calendar REQ-006 / REQ-007).

 CalendarView renders the month/week/day grid and EMITS `slot:clicked` and
 `booking:selected`; BookingForm creates the booking. Neither knows about the
 other — something has to host both and wire the events. That host is this
 page.

 It restores the wiring that `src/views/bookings/CalendarPage.vue` used to
 provide before commit 0c23c1e4 deleted it. That commit's stated reason was
 that the page "wraps the SAME CalendarView component"; it does not. The
 deleted page imported `../../components/CalendarView.vue` while `registry.js`
 imports `./views/bookings/CalendarView.vue` — two different files. Deleting
 the host therefore did not remove a duplicate, it removed the ONLY listener
 for `slot:clicked`, and REQ-007's "click an available slot to create a
 booking" has been dead ever since: the manifest mounted the grid bare and the
 event went nowhere.

 This version hosts the CANONICAL component pair under `src/views/bookings/`,
 and matches their real contracts rather than the deleted page's:
  * `slot:clicked` carries two POSITIONAL UTC ISO strings (start, end), not a
    `{startTime, endTime}` object.
  * CalendarView takes no `time-zone` prop and emits no `update:view`; the
    view switcher lives inside the grid itself.

 The `calendarId` route parameter is OPTIONAL. With a parameter the page opens
 that calendar directly (deep links from a Calendar detail page, and the e2e
 suite's deterministic fixture). Without one it lists the calendars the user
 may see and offers a picker, which is what makes a single param-less
 navigation entry possible — the page had none at all before, so it was
 reachable only by typing a URL containing a calendar id you had no way to
 discover from the UI.

 @spec openspec/changes/bookings-resource-calendar/tasks.md#task-5
 @spec openspec/changes/bookings-resource-calendar/tasks.md#task-6
-->

<template>
	<div class="bookings-calendar-page" data-testid="bk-calendar-page">
		<header class="bookings-calendar-page__header">
			<h1 class="bookings-calendar-page__title">
				{{ t('shillinq', 'Bookings calendar') }}
			</h1>
			<label v-if="showPicker" class="bookings-calendar-page__picker">
				{{ t('shillinq', 'Calendar') }}
				<select
					v-model="selectedCalendarId"
					class="bookings-calendar-page__select"
					data-testid="bk-calendar-picker">
					<option v-for="cal in calendars" :key="cal.id" :value="cal.id">
						{{ cal.id }}{{ cal.resource ? ' – ' + cal.resource : '' }}
					</option>
				</select>
			</label>
		</header>

		<!--
		 `bk-calendar` marks the calendar surface and lives on this wrapper
		 rather than on CalendarView's root, because that root's
		 `data-testid="calendar-view"` is already asserted on by
		 tests/e2e/bookings-calendar.spec.ts to prove the manifest page id
		 resolves to the grid component. An element carries one test id, so the
		 two names sit on the two elements that genuinely mean two things: the
		 host's calendar surface, and the grid component itself.
		-->
		<div v-if="selectedCalendarId" class="bookings-calendar-page__grid" data-testid="bk-calendar">
			<CalendarView
				ref="grid"
				:key="selectedCalendarId"
				:calendar-id="selectedCalendarId"
				:start-date="startDate"
				@booking:selected="onBookingSelected"
				@slot:clicked="onSlotClicked" />
		</div>

		<p v-else class="bookings-calendar-page__empty" data-testid="bk-calendar-empty">
			{{ emptyMessage }}
		</p>

		<aside v-if="formOpen" class="bookings-calendar-page__form" data-testid="bk-form-panel">
			<BookingForm
				:calendar-id="selectedCalendarId"
				:initial="formInitial"
				@booking:created="onBookingCreated"
				@cancel="closeForm" />
		</aside>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import CalendarView from './CalendarView.vue'
import BookingForm from './BookingForm.vue'

export default {
	name: 'CalendarPage',

	components: {
		CalendarView,
		BookingForm,
	},

	props: {
		/**
		 * Calendar id from the route (`/bookings/calendar/:calendarId?`).
		 * Optional: when absent the page resolves a calendar through the
		 * picker instead.
		 */
		calendarId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			calendars: [],
			selectedCalendarId: this.calendarId || '',
			startDate: this.todayIso(),
			formOpen: false,
			formInitial: {},
			loadFailed: false,
			loading: false,
		}
	},

	computed: {
		/**
		 * The picker is only meaningful when the page resolved the calendar
		 * itself; a deep link addresses one specific calendar.
		 *
		 * @return {boolean}
		 */
		showPicker() {
			return this.calendarId === '' && this.calendars.length > 0
		},

		/**
		 * Message shown when no grid can be rendered. Distinguishes "still
		 * loading", "the lookup failed" and "there genuinely are none" — an
		 * empty list and a failed request must not read identically.
		 *
		 * @return {string}
		 */
		emptyMessage() {
			if (this.loading) {
				return t('shillinq', 'Loading calendars…')
			}
			if (this.loadFailed) {
				return t('shillinq', 'Could not load the calendars for this administration.')
			}
			return t('shillinq', 'No calendars are available yet. Create one under Bookings › Calendars.')
		},
	},

	watch: {
		calendarId(value) {
			this.selectedCalendarId = value || ''
			if (value === '') {
				this.loadCalendars()
			}
		},
		selectedCalendarId() {
			this.closeForm()
		},
	},

	mounted() {
		// A deep link already names its calendar — do not spend a request on
		// the index, and do not make the page depend on the index being
		// readable in the caller's administration scope.
		if (this.selectedCalendarId === '') {
			this.loadCalendars()
		}
	},

	methods: {
		/**
		 * Today's date as YYYY-MM-DD (local), used as the grid's anchor.
		 *
		 * @return {string}
		 */
		todayIso() {
			const now = new Date()
			const pad = (n) => String(n).padStart(2, '0')
			return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
		},

		/**
		 * Load the calendars the current user may see and select the first.
		 *
		 * @return {Promise<void>}
		 */
		async loadCalendars() {
			this.loading = true
			this.loadFailed = false
			try {
				const response = await fetch(
					generateUrl('/apps/shillinq/api/v2/calendars'),
					{
						credentials: 'include',
						headers: { Accept: 'application/json', requesttoken: OC.requestToken },
					},
				)
				if (!response.ok) {
					this.loadFailed = true
					return
				}
				const body = await response.json()
				this.calendars = Array.isArray(body.calendars) ? body.calendars : []
				if (this.selectedCalendarId === '' && this.calendars.length > 0) {
					this.selectedCalendarId = this.calendars[0].id
				}
			} catch (error) {
				this.loadFailed = true
			} finally {
				this.loading = false
			}
		},

		/**
		 * An existing booking was clicked. Navigating to its detail page needs
		 * the booking's OpenRegister id, which the grid does not carry, so for
		 * now this is an acknowledged no-op hook kept so the event has a
		 * declared owner rather than a silent Vue warning.
		 *
		 * @param {string} bookingId The clicked booking's logical id.
		 * @return {void}
		 */
		onBookingSelected(bookingId) {
			if (typeof window !== 'undefined' && window.console) {
				window.console.info('shillinq: booking selected', bookingId)
			}
		},

		/**
		 * An empty slot was clicked — open the booking form pre-filled with
		 * that slot's window (REQ-007).
		 *
		 * CalendarView emits two POSITIONAL UTC ISO strings.
		 *
		 * @param {string} startIso Slot start, UTC ISO-8601.
		 * @param {string} endIso   Slot end, UTC ISO-8601.
		 * @return {void}
		 */
		onSlotClicked(startIso, endIso) {
			this.formInitial = {
				title: '',
				startTime: startIso,
				endTime: endIso,
				attendee: '',
				status: 'pending',
			}
			this.formOpen = true
		},

		/**
		 * A booking was created — close the form and re-read the grid so the
		 * new slot is visible immediately.
		 *
		 * @return {Promise<void>}
		 */
		async onBookingCreated() {
			this.closeForm()
			const grid = this.$refs.grid
			if (grid && typeof grid.refresh === 'function') {
				await grid.refresh()
			}
		},

		/**
		 * Close the booking form panel and drop its pre-fill.
		 *
		 * @return {void}
		 */
		closeForm() {
			this.formOpen = false
			this.formInitial = {}
		},
	},
}
</script>

<style scoped>
.bookings-calendar-page {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
}

.bookings-calendar-page__header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 16px;
}

.bookings-calendar-page__picker {
	display: flex;
	align-items: center;
	gap: 8px;
}

.bookings-calendar-page__select {
	min-width: 200px;
}

.bookings-calendar-page__empty {
	color: var(--color-text-maxcontrast);
}

.bookings-calendar-page__form {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
}
</style>
