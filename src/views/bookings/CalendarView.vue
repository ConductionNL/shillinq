<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Per-resource calendar component (bookings-resource-calendar REQ-006). Renders a
 resource's bookings in month, week, or day view, highlights conflicting
 (status: pending) bookings in red, and emits slot:clicked / booking:selected.
 Times are displayed in the calendar's configured time zone; storage is UTC.
-->
<template>
	<div class="calendar-view" data-testid="calendar-view">
		<header class="calendar-view__toolbar">
			<h2 class="calendar-view__title">
				{{ headerLabel }}
			</h2>
			<div class="calendar-view__view-switch">
				<button
					v-for="v in views"
					:key="v"
					type="button"
					class="calendar-view__view-button"
					:class="{
						'calendar-view__view-button--active': currentView === v,
					}"
					:data-testid="`calendar-view-${v}`"
					@click="currentView = v">
					{{ viewLabel(v) }}
				</button>
			</div>
		</header>

		<!-- MONTH VIEW -->
		<div
			v-if="currentView === 'month'"
			class="calendar-view__month"
			data-testid="calendar-month-grid">
			<div
				v-for="day in monthDays"
				:key="day.iso"
				class="calendar-view__cell"
				:data-date="day.iso">
				<div class="calendar-view__cell-date">
					{{ day.day }}
				</div>
				<button
					v-for="booking in bookingsForDay(day.iso)"
					:key="bookingId(booking)"
					type="button"
					class="calendar-view__booking"
					:class="{
						'calendar-view__booking--conflict': isConflict(booking),
					}"
					:data-testid="`booking-${bookingId(booking)}`"
					@click="$emit('booking:selected', bookingId(booking))">
					{{ booking.title }}
				</button>
			</div>
		</div>

		<!-- WEEK VIEW -->
		<div
			v-else-if="currentView === 'week'"
			class="calendar-view__week"
			data-testid="calendar-week-grid">
			<div
				v-for="day in weekDays"
				:key="day.iso"
				class="calendar-view__week-column">
				<div class="calendar-view__cell-date">
					{{ day.label }}
				</div>
				<div
					v-for="hour in hours"
					:key="`${day.iso}-${hour}`"
					class="calendar-view__slot">
					<button
						type="button"
						class="calendar-view__slot-button"
						:data-testid="`calendar-slot-${day.iso}-${hour}`"
						@click="emitSlot(day.iso, hour)">
						<span class="calendar-view__slot-hour">{{
							formatHour(hour)
						}}</span>
					</button>
					<button
						v-for="booking in bookingsForHour(day.iso, hour)"
						:key="bookingId(booking)"
						type="button"
						class="calendar-view__booking"
						:class="{
							'calendar-view__booking--conflict': isConflict(booking),
						}"
						:data-testid="`booking-${bookingId(booking)}`"
						@click="$emit('booking:selected', bookingId(booking))">
						{{ booking.title }}
					</button>
				</div>
			</div>
		</div>

		<!-- DAY VIEW -->
		<div v-else class="calendar-view__day" data-testid="calendar-day-grid">
			<div v-for="hour in hours" :key="hour" class="calendar-view__slot">
				<button
					type="button"
					class="calendar-view__slot-button"
					:data-testid="`calendar-slot-${dayIso}-${hour}`"
					@click="emitSlot(dayIso, hour)">
					<span class="calendar-view__slot-hour">{{
						formatHour(hour)
					}}</span>
				</button>
				<button
					v-for="booking in bookingsForHour(dayIso, hour)"
					:key="bookingId(booking)"
					type="button"
					class="calendar-view__booking"
					:class="{
						'calendar-view__booking--conflict': isConflict(booking),
					}"
					:data-testid="`booking-${bookingId(booking)}`"
					@click="$emit('booking:selected', bookingId(booking))">
					{{ booking.title }}
				</button>
			</div>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'CalendarView',

	props: {
		/**
		 * Calendar UUID/slug to display.
		 */
		calendarId: {
			type: String,
			required: true,
		},

		/**
		 * Initial view mode.
		 */
		view: {
			type: String,
			default: 'month',
			validator: (v) => ['month', 'week', 'day'].includes(v),
		},

		/**
		 * Initial date (ISO-8601) to display; defaults to today.
		 */
		startDate: {
			type: String,
			default: '',
		},

		/**
		 * Optional pre-loaded bookings (used by tests / parent-driven data).
		 * When empty the component fetches from the API on mount.
		 */
		initialBookings: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['booking:selected', 'slot:clicked'],

	data() {
		return {
			currentView: this.view,
			views: ['month', 'week', 'day'],
			hours: Array.from({ length: 24 }, (_, i) => i),
			bookings: [...this.initialBookings],
			anchor: this.startDate ? new Date(this.startDate) : new Date(),
		}
	},

	computed: {
		/**
		 * The ISO date (YYYY-MM-DD) of the day view's anchor.
		 *
		 * @return {string}
		 */
		dayIso() {
			return this.toIsoDate(this.anchor)
		},

		/**
		 * Header label for the current view (month name / week range / date).
		 *
		 * @return {string}
		 */
		headerLabel() {
			const opts = { year: 'numeric', month: 'long' }
			if (this.currentView === 'day') {
				return this.anchor.toLocaleDateString(undefined, {
					...opts,
					day: 'numeric',
				})
			}
			return this.anchor.toLocaleDateString(undefined, opts)
		},

		/**
		 * All day cells of the anchor's month.
		 *
		 * @return {Array<object>}
		 */
		monthDays() {
			const year = this.anchor.getFullYear()
			const month = this.anchor.getMonth()
			const last = new Date(year, month + 1, 0).getDate()
			const days = []
			for (let d = 1; d <= last; d++) {
				const date = new Date(year, month, d)
				days.push({ iso: this.toIsoDate(date), day: d })
			}
			return days
		},

		/**
		 * The 7 days of the anchor's week (Monday-first).
		 *
		 * @return {Array<object>}
		 */
		weekDays() {
			const monday = new Date(this.anchor)
			const offset = (monday.getDay() + 6) % 7
			monday.setDate(monday.getDate() - offset)
			const days = []
			for (let i = 0; i < 7; i++) {
				const date = new Date(monday)
				date.setDate(monday.getDate() + i)
				days.push({
					iso: this.toIsoDate(date),
					label: date.toLocaleDateString(undefined, {
						weekday: 'short',
						day: 'numeric',
					}),
				})
			}
			return days
		},
	},

	watch: {
		view(v) {
			this.currentView = v
		},

		initialBookings(v) {
			this.bookings = [...v]
		},
	},

	async mounted() {
		if (this.bookings.length === 0) {
			await this.fetchBookings()
		}
	},

	methods: {
		/**
		 * Fetch this calendar's bookings from the API.
		 *
		 * @return {Promise<void>}
		 */
		async fetchBookings() {
			try {
				const url = generateUrl(
					'/apps/shillinq/api/v2/calendars/{calendarId}/bookings',
					{ calendarId: this.calendarId },
				)
				const response = await fetch(url, {
					headers: { requesttoken: OC.requestToken },
				})
				if (response.ok) {
					const data = await response.json()
					// ENVELOPE KEY. CalendarController::bookings() answers
					// `{"bookings": [...]}` — this read `data.results`, which is
					// never present, so the grid rendered ZERO bookings on every
					// load while the request itself returned a healthy 200. The
					// calendar looked empty for a real user, not just for a test;
					// ci-seed.sh reads the same endpoint and gets its 10 rows
					// (`body.get('bookings')`), which is what proves the data was
					// always there.
					this.bookings = Array.isArray(data)
						? data
						: data.bookings || data.results || []
				}
			} catch (error) {
				this.bookings = []
			}
		},

		/**
		 * Bookings whose start date matches the given ISO date.
		 *
		 * @param {string} iso ISO date (YYYY-MM-DD).
		 * @return {Array<object>}
		 */
		bookingsForDay(iso) {
			return this.bookings.filter(
				(b) => this.toIsoDate(new Date(b.startTime)) === iso,
			)
		},

		/**
		 * Bookings that start within the given ISO date and hour.
		 *
		 * @param {string} iso ISO date.
		 * @param {number} hour Hour (0-23) in local time.
		 * @return {Array<object>}
		 */
		bookingsForHour(iso, hour) {
			return this.bookings.filter((b) => {
				const d = new Date(b.startTime)
				return this.toIsoDate(d) === iso && d.getHours() === hour
			})
		},

		/**
		 * Whether a booking is conflicting (pending status flags an overlap).
		 *
		 * @param {object} booking The booking.
		 * @return {boolean}
		 */
		isConflict(booking) {
			return booking.status === 'pending'
		},

		/**
		 * Resolve a booking's stable id (flattened by the API serializer).
		 *
		 * @param {object} booking The booking.
		 * @return {string}
		 */
		bookingId(booking) {
			// `booking.bookingId` FIRST. This read `booking.id` — the
			// OpenRegister row UUID — so every chip rendered
			// `data-testid="booking-<uuid>"` and emitted a UUID on
			// `booking:selected`. `bookingId` is the domain identifier the rest
			// of this feature already speaks: ci-seed.sh writes `bk-001`…`bk-010`
			// into it, ConflictDetectionService returns it in the `conflicts[]`
			// entries, and BookingConflictDialog keys its rows off `c.bookingId`.
			// CalendarView was the single outlier, which made its chips
			// unaddressable by anything that knew a booking by name.
			return (
				booking.bookingId
				|| booking.id
				|| (booking['@self']
					&& (booking['@self'].id || booking['@self'].uuid))
				|| ''
			)
		},

		/**
		 * Emit slot:clicked with the UTC start/end of a one-hour slot.
		 *
		 * @param {string} iso ISO date.
		 * @param {number} hour Hour (0-23).
		 * @return {void}
		 */
		emitSlot(iso, hour) {
			const start = new Date(`${iso}T${String(hour).padStart(2, '0')}:00:00`)
			const end = new Date(start.getTime() + 3600 * 1000)
			this.$emit('slot:clicked', start.toISOString(), end.toISOString())
		},

		/**
		 * Format a Date as YYYY-MM-DD (local).
		 *
		 * @param {Date} date The date.
		 * @return {string}
		 */
		toIsoDate(date) {
			const y = date.getFullYear()
			const m = String(date.getMonth() + 1).padStart(2, '0')
			const d = String(date.getDate()).padStart(2, '0')
			return `${y}-${m}-${d}`
		},

		/**
		 * Format an hour as HH:00.
		 *
		 * @param {number} hour The hour.
		 * @return {string}
		 */
		formatHour(hour) {
			return `${String(hour).padStart(2, '0')}:00`
		},

		/**
		 * Localized label for a view mode.
		 *
		 * @param {string} v The view mode.
		 * @return {string}
		 */
		viewLabel(v) {
			const labels = {
				month: t('shillinq', 'Month'),
				week: t('shillinq', 'Week'),
				day: t('shillinq', 'Day'),
			}
			return labels[v] || v
		},
	},
}
</script>

<style scoped>
.calendar-view {
	padding: 16px;
}

.calendar-view__toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.calendar-view__view-switch {
	display: flex;
	gap: 4px;
}

.calendar-view__view-button {
	padding: 4px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.calendar-view__view-button--active {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.calendar-view__month {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: 4px;
}

.calendar-view__week {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: 4px;
}

.calendar-view__cell,
.calendar-view__week-column {
	min-height: 80px;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.calendar-view__cell-date {
	font-weight: bold;
	font-size: 0.85em;
}

/*
 * The slot is a plain container, not a button: it holds the empty-slot
 * affordance AND the bookings inside that hour. Bookings are their own
 * <button>s, and a <button> may not be nested inside another one — the
 * markup that did was both invalid and unreachable by keyboard.
 */
.calendar-view__slot {
	display: flex;
	flex-direction: column;
	width: 100%;
	min-height: 32px;
	padding: 2px 4px;
	border-bottom: 1px solid var(--color-border);
}

.calendar-view__slot-button {
	width: 100%;
	padding: 0;
	border: none;
	background: transparent;
	text-align: left;
}

.calendar-view__slot-hour {
	font-size: 0.75em;
	color: var(--color-text-maxcontrast);
}

.calendar-view__booking {
	display: block;
	width: 100%;
	margin-top: 2px;
	padding: 2px 6px;
	border: none;
	border-radius: var(--border-radius);
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
	font-size: 0.8em;
	text-align: left;
	cursor: pointer;
}

.calendar-view__booking--conflict {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}
</style>
