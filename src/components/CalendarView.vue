<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Calendar View — per-resource booking calendar (REQ-006 of bookings-resource-calendar).

  Renders a single Calendar (identified by calendarId) in month / week / day
  view. Bookings are fetched from /api/v2/calendars/{id}/bookings and rendered
  inside their corresponding day or hour cell; pending (conflicting) bookings
  are highlighted in red so the operator can resolve double-bookings at a glance.

  The component is intentionally framework-light: it builds the calendar
  grid using plain Date arithmetic in UTC and converts each booking into the
  calendar's configured time zone for display (REQ-008). It exposes two
  events — booking:selected when a booking is clicked, slot:clicked when an
  empty slot is clicked — so the host view can open the BookingForm modal.

  @spec openspec/changes/bookings-resource-calendar/tasks.md#task-5
-->

<template>
	<div class="bk-calendar" :data-view="view" data-testid="bk-calendar">
		<header class="bk-calendar__header">
			<div class="bk-calendar__title">
				{{ headerLabel }}
			</div>
			<nav class="bk-calendar__views" role="tablist">
				<button
					v-for="opt in viewOptions"
					:key="opt.value"
					type="button"
					role="tab"
					:aria-selected="view === opt.value"
					:class="{ 'is-active': view === opt.value }"
					:data-testid="`bk-calendar-view-${opt.value}`"
					@click="$emit('update:view', opt.value)">
					{{ opt.label }}
				</button>
			</nav>
		</header>

		<!-- Month view: 7-column grid, one cell per day -->
		<div v-if="view === 'month'" class="bk-calendar__grid bk-calendar__grid--month" data-testid="bk-calendar-month">
			<div v-for="(day, idx) in weekdayHeaders" :key="`hdr-${idx}`" class="bk-calendar__weekday">
				{{ day }}
			</div>
			<div
				v-for="cell in monthCells"
				:key="cell.iso"
				class="bk-calendar__day"
				:class="{ 'is-other-month': !cell.inMonth, 'is-today': cell.isToday }"
				:data-testid="`bk-day-${cell.iso}`"
				@click="onDayClick(cell)">
				<div class="bk-calendar__day-number">
					{{ cell.dayOfMonth }}
				</div>
				<ul class="bk-calendar__bookings">
					<li
						v-for="bk in cell.bookings"
						:key="bk.id"
						class="bk-calendar__booking"
						:class="{ 'is-conflict': bk.status === 'pending' }"
						:data-testid="`bk-booking-${bk.id}`"
						@click.stop="$emit('booking:selected', bk.id)">
						<span class="bk-calendar__booking-time">{{ formatLocalTime(bk.startTime) }}</span>
						<span class="bk-calendar__booking-title">{{ bk.title }}</span>
					</li>
				</ul>
			</div>
		</div>

		<!-- Week view: 7 columns x 24 hourly rows -->
		<div v-else-if="view === 'week'" class="bk-calendar__grid bk-calendar__grid--week" data-testid="bk-calendar-week">
			<div class="bk-calendar__hour-axis-cell" aria-hidden="true"></div>
			<div v-for="(day, idx) in weekCells" :key="`whdr-${idx}`" class="bk-calendar__week-header">
				<div class="bk-calendar__weekday">
					{{ weekdayHeaders[idx] }}
				</div>
				<div class="bk-calendar__day-number">
					{{ day.dayOfMonth }}
				</div>
			</div>
			<template v-for="h in hoursOfDay" :key="`hrow-${h}`">
				<div class="bk-calendar__hour-axis">
					{{ formatHourLabel(h) }}
				</div>
				<div
					v-for="(day, didx) in weekCells"
					:key="`wcell-${h}-${didx}`"
					class="bk-calendar__slot"
					:data-testid="`bk-slot-${day.iso}-${h}`"
					@click="onSlotClick(day, h)">
					<div
						v-for="bk in bookingsInHour(day, h)"
						:key="`${bk.id}-${h}`"
						class="bk-calendar__booking"
						:class="{ 'is-conflict': bk.status === 'pending' }"
						:data-testid="`bk-booking-${bk.id}`"
						@click.stop="$emit('booking:selected', bk.id)">
						<span class="bk-calendar__booking-time">{{ formatLocalTime(bk.startTime) }}</span>
						<span class="bk-calendar__booking-title">{{ bk.title }}</span>
					</div>
				</div>
			</template>
		</div>

		<!-- Day view: 24-hour vertical grid -->
		<div v-else class="bk-calendar__grid bk-calendar__grid--day" data-testid="bk-calendar-day">
			<div
				v-for="h in hoursOfDay"
				:key="`dhour-${h}`"
				class="bk-calendar__day-row">
				<div class="bk-calendar__hour-axis">
					{{ formatHourLabel(h) }}
				</div>
				<div
					class="bk-calendar__slot"
					:data-testid="`bk-slot-${dayIso}-${h}`"
					@click="onSlotClick({ iso: dayIso, date: dayDate }, h)">
					<div
						v-for="bk in bookingsInHour({ iso: dayIso, date: dayDate }, h)"
						:key="`${bk.id}-${h}`"
						class="bk-calendar__booking"
						:class="{ 'is-conflict': bk.status === 'pending' }"
						:data-testid="`bk-booking-${bk.id}`"
						@click.stop="$emit('booking:selected', bk.id)">
						<span class="bk-calendar__booking-time">{{ formatLocalTime(bk.startTime) }}</span>
						<span class="bk-calendar__booking-title">{{ bk.title }}</span>
					</div>
				</div>
			</div>
		</div>

		<p v-if="loadError" class="bk-calendar__error" role="alert">
			{{ loadError }}
		</p>
	</div>
</template>

<script>
/**
 * Calendar View component (REQ-006).
 *
 * Renders bookings for a single calendar in month, week or day view and
 * emits two events:
 *   - booking:selected(bookingId) — user clicked a rendered booking
 *   - slot:clicked({startTime, endTime}) — user clicked an empty slot
 *
 * The component fetches bookings from the API on mount and whenever the
 * calendarId / view / startDate prop changes; consumers can also call the
 * refresh() public method (e.g. after a successful booking create).
 */
export default {
	name: 'CalendarView',
	props: {
		calendarId: { type: String, required: true },
		view: { type: String, default: 'month' },
		startDate: { type: String, default: () => new Date().toISOString().slice(0, 10) },
		timeZone: { type: String, default: 'Europe/Amsterdam' },
		// Allow injection of bookings for tests / Storybook so the component
		// can render without a live API.
		bookings: { type: Array, default: null },
	},
	emits: ['booking:selected', 'slot:clicked', 'update:view'],
	data() {
		return {
			loadError: '',
			loadedBookings: [],
		}
	},
	computed: {
		viewOptions() {
			return [
				{ value: 'month', label: this.label('Month') },
				{ value: 'week', label: this.label('Week') },
				{ value: 'day', label: this.label('Day') },
			]
		},
		anchorDate() {
			// Parse startDate as a calendar date (no time) anchored to UTC.
			const [y, m, d] = (this.startDate || '').split('-').map(Number)
			if (!y || !m || !d) {
				return new Date()
			}
			return new Date(Date.UTC(y, m - 1, d))
		},
		headerLabel() {
			const d = this.anchorDate
			if (this.view === 'month') {
				return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' })
			}
			if (this.view === 'week') {
				return this.label('Week of {date}', { date: d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }) })
			}
			return d.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })
		},
		weekdayHeaders() {
			// ISO weekday order: Mon..Sun for the Dutch market.
			return [
				this.label('Mon'),
				this.label('Tue'),
				this.label('Wed'),
				this.label('Thu'),
				this.label('Fri'),
				this.label('Sat'),
				this.label('Sun'),
			]
		},
		hoursOfDay() {
			return Array.from({ length: 24 }, (_v, i) => i)
		},
		dayIso() {
			return this.toIso(this.anchorDate)
		},
		dayDate() {
			return this.anchorDate
		},
		monthCells() {
			const anchor = this.anchorDate
			const firstOfMonth = new Date(Date.UTC(anchor.getUTCFullYear(), anchor.getUTCMonth(), 1))
			// Monday-start week.
			const startOffset = (firstOfMonth.getUTCDay() + 6) % 7
			const gridStart = new Date(firstOfMonth)
			gridStart.setUTCDate(firstOfMonth.getUTCDate() - startOffset)
			const cells = []
			const todayIso = this.toIso(new Date())
			for (let i = 0; i < 42; i++) {
				const d = new Date(gridStart)
				d.setUTCDate(gridStart.getUTCDate() + i)
				const iso = this.toIso(d)
				cells.push({
					iso,
					date: d,
					dayOfMonth: d.getUTCDate(),
					inMonth: d.getUTCMonth() === anchor.getUTCMonth(),
					isToday: iso === todayIso,
					bookings: this.bookingsForDay(iso),
				})
			}
			return cells
		},
		weekCells() {
			const anchor = this.anchorDate
			const offset = (anchor.getUTCDay() + 6) % 7
			const monday = new Date(anchor)
			monday.setUTCDate(anchor.getUTCDate() - offset)
			const cells = []
			for (let i = 0; i < 7; i++) {
				const d = new Date(monday)
				d.setUTCDate(monday.getUTCDate() + i)
				cells.push({
					iso: this.toIso(d),
					date: d,
					dayOfMonth: d.getUTCDate(),
					bookings: this.bookingsForDay(this.toIso(d)),
				})
			}
			return cells
		},
		effectiveBookings() {
			return Array.isArray(this.bookings) ? this.bookings : this.loadedBookings
		},
	},
	watch: {
		calendarId: { immediate: true, handler: 'refresh' },
		startDate() { this.refresh() },
	},
	mounted() {
		this.refresh()
	},
	methods: {
		label(key, params = {}) {
			// Use Nextcloud's t() helper when available; otherwise fall back
			// to the raw key. Tests can stub t() globally.
			if (typeof this.$t === 'function') {
				return this.$t(key, params)
			}
			if (typeof t === 'function') {
				return t('shillinq', key, params)
			}
			return key
		},
		async refresh() {
			if (Array.isArray(this.bookings)) {
				return
			}
			if (!this.calendarId) {
				return
			}
			this.loadError = ''
			try {
				const url = this.buildBookingsUrl()
				const response = await fetch(url, { credentials: 'include', headers: { Accept: 'application/json' } })
				if (!response.ok) {
					throw new Error(`HTTP ${response.status}`)
				}
				const body = await response.json()
				this.loadedBookings = Array.isArray(body.bookings) ? body.bookings : []
			} catch (err) {
				this.loadError = this.label('Could not load bookings: {message}', { message: err.message })
				this.loadedBookings = []
			}
		},
		buildBookingsUrl() {
			const base = (window.OC && window.OC.generateUrl)
				? window.OC.generateUrl(`/apps/shillinq/api/v2/calendars/${encodeURIComponent(this.calendarId)}/bookings`)
				: `/apps/shillinq/api/v2/calendars/${encodeURIComponent(this.calendarId)}/bookings`
			const { rangeStart, rangeEnd } = this.computeRange()
			const params = new URLSearchParams({ start: rangeStart, end: rangeEnd })
			return `${base}?${params.toString()}`
		},
		computeRange() {
			const anchor = this.anchorDate
			if (this.view === 'month') {
				const first = new Date(Date.UTC(anchor.getUTCFullYear(), anchor.getUTCMonth(), 1))
				const last = new Date(Date.UTC(anchor.getUTCFullYear(), anchor.getUTCMonth() + 1, 1))
				return { rangeStart: this.toIso(first), rangeEnd: this.toIso(last) }
			}
			if (this.view === 'week') {
				const offset = (anchor.getUTCDay() + 6) % 7
				const monday = new Date(anchor)
				monday.setUTCDate(anchor.getUTCDate() - offset)
				const end = new Date(monday)
				end.setUTCDate(monday.getUTCDate() + 7)
				return { rangeStart: this.toIso(monday), rangeEnd: this.toIso(end) }
			}
			const next = new Date(anchor)
			next.setUTCDate(anchor.getUTCDate() + 1)
			return { rangeStart: this.toIso(anchor), rangeEnd: this.toIso(next) }
		},
		toIso(date) {
			const y = date.getUTCFullYear().toString().padStart(4, '0')
			const m = (date.getUTCMonth() + 1).toString().padStart(2, '0')
			const d = date.getUTCDate().toString().padStart(2, '0')
			return `${y}-${m}-${d}`
		},
		bookingsForDay(iso) {
			return this.effectiveBookings.filter(bk => {
				const start = bk && typeof bk.startTime === 'string' ? bk.startTime : ''
				return start.startsWith(iso)
			})
		},
		bookingsInHour(day, hour) {
			return this.bookingsForDay(day.iso).filter(bk => {
				const d = new Date(bk.startTime)
				if (Number.isNaN(d.valueOf())) {
					return false
				}
				return d.getUTCHours() === hour
			})
		},
		formatLocalTime(iso) {
			if (!iso) {
				return ''
			}
			try {
				const d = new Date(iso)
				return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', timeZone: this.timeZone })
			} catch {
				return iso
			}
		},
		formatHourLabel(h) {
			const d = new Date(Date.UTC(2026, 0, 1, h, 0, 0))
			try {
				return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', timeZone: this.timeZone })
			} catch {
				return `${h.toString().padStart(2, '0')}:00`
			}
		},
		onDayClick(cell) {
			const start = `${cell.iso}T09:00:00Z`
			const endDate = new Date(start)
			endDate.setUTCMinutes(endDate.getUTCMinutes() + 30)
			this.$emit('slot:clicked', { startTime: start, endTime: endDate.toISOString() })
		},
		onSlotClick(day, hour) {
			const start = `${day.iso}T${hour.toString().padStart(2, '0')}:00:00Z`
			const endDate = new Date(start)
			endDate.setUTCMinutes(endDate.getUTCMinutes() + 30)
			this.$emit('slot:clicked', { startTime: start, endTime: endDate.toISOString() })
		},
	},
}
</script>

<style scoped>
.bk-calendar { display: flex; flex-direction: column; gap: 8px; padding: 8px; }
.bk-calendar__header { display: flex; justify-content: space-between; align-items: center; }
.bk-calendar__title { font-weight: 600; font-size: 1.1em; }
.bk-calendar__views button {
	background: transparent;
	border: 1px solid var(--color-border, #ccc);
	padding: 4px 10px;
	cursor: pointer;
}
.bk-calendar__views button.is-active {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
}

.bk-calendar__grid--month {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: 1px;
	background: var(--color-border, #ccc);
}
.bk-calendar__weekday { padding: 4px 8px; font-weight: 600; background: var(--color-background-hover, #f5f5f5); }
.bk-calendar__day {
	min-height: 80px;
	background: var(--color-main-background, #fff);
	padding: 4px;
	cursor: pointer;
}
.bk-calendar__day.is-other-month { opacity: 0.4; }
.bk-calendar__day.is-today { box-shadow: inset 0 0 0 2px var(--color-primary-element, #0082c9); }
.bk-calendar__day-number { font-weight: 600; font-size: 0.9em; }
.bk-calendar__bookings { list-style: none; padding: 0; margin: 4px 0 0; }
.bk-calendar__booking {
	display: flex;
	gap: 4px;
	background: var(--color-primary-element-light, #d6ecf7);
	color: var(--color-text-light, #333);
	border-radius: 4px;
	padding: 2px 4px;
	font-size: 0.85em;
	margin-bottom: 2px;
	cursor: pointer;
}
.bk-calendar__booking.is-conflict {
	background: var(--color-error, #c62828);
	color: var(--color-main-background, #fff);
}
.bk-calendar__booking-time { font-weight: 600; }

.bk-calendar__grid--week {
	display: grid;
	grid-template-columns: 60px repeat(7, 1fr);
	gap: 1px;
	background: var(--color-border, #ccc);
}
.bk-calendar__hour-axis-cell { background: var(--color-background-hover, #f5f5f5); }
.bk-calendar__week-header { display: flex; flex-direction: column; align-items: center; padding: 4px; background: var(--color-background-hover, #f5f5f5); }
.bk-calendar__hour-axis { padding: 4px 8px; background: var(--color-background-hover, #f5f5f5); font-size: 0.85em; }
.bk-calendar__slot {
	min-height: 36px;
	background: var(--color-main-background, #fff);
	padding: 2px;
	cursor: pointer;
}

.bk-calendar__grid--day { display: flex; flex-direction: column; }
.bk-calendar__day-row {
	display: grid;
	grid-template-columns: 80px 1fr;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.bk-calendar__error { color: var(--color-error, #c62828); padding: 8px; }
</style>
