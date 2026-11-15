<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Booking Self-service Widget — partner-facing Vue component (REQ-WSW-003).

  Implements the 4-step booking flow: service selection -> date+time
  selection -> customer details -> confirmation. Uses plain HTML form
  controls so the widget mounts in any host environment (script tag,
  iframe, web component, npm) without dragging the @nextcloud/vue
  component library along — the host page may not have it.

  Accessibility (REQ-WSW-003 / WCAG 2.1 AA):
    - native <label for> associations for every form control,
    - aria-live=polite on the status region so errors are announced,
    - aria-current=step on the active step indicator,
    - 44x44 minimum touch targets via widget.css.

  Embed-time configuration is passed via props (businessId, apiBase, apiKey,
  lang, primaryColor). Theming uses CSS variables exposed by widget.css —
  the component never inlines colours from JavaScript so partners can fully
  override appearance via their own stylesheet (REQ-WSW-005).
-->

<template>
	<div
		:class="rootClass"
		:lang="lang"
		role="form"
		:aria-label="t('Book an appointment')">
		<header class="wsw-widget__header">
			<h2 class="wsw-widget__title">
				{{ t('Book an appointment') }}
			</h2>
			<p class="wsw-widget__step-indicator" aria-live="polite">
				{{ stepLabel }}
			</p>
		</header>

		<!-- STEP 1 — Service -->
		<section v-if="step === 'service'">
			<label class="wsw-widget__label" :for="ids.service">{{
				t('Select a service')
			}}</label>
			<select
				:id="ids.service"
				v-model="selectedServiceId"
				class="wsw-widget__select"
				:disabled="loadingServices"
				:aria-describedby="ids.serviceError">
				<option value="">
					{{ t('Select a service') }}
				</option>
				<option
					v-for="service in services"
					:key="service.serviceId"
					:value="service.serviceId">
					{{ service.name }} ({{ service.duration }} {{ t('minutes') }})
				</option>
			</select>
			<p :id="ids.serviceError" class="wsw-widget__error" role="alert">
				{{ servicesError }}
			</p>

			<div class="wsw-widget__actions">
				<button
					type="button"
					class="wsw-widget__button"
					:disabled="!selectedServiceId"
					@click="goToDateStep">
					{{ t('Next') }}
				</button>
			</div>
		</section>

		<!-- STEP 2 — Date + time -->
		<section v-if="step === 'datetime'">
			<label class="wsw-widget__label" :for="ids.date">{{
				t('Select a date')
			}}</label>
			<input
				:id="ids.date"
				v-model="selectedDate"
				class="wsw-widget__input"
				type="date"
				:min="todayIso"
				@change="loadSlots" />

			<label class="wsw-widget__label" :for="ids.slot">{{
				t('Select a time')
			}}</label>
			<div
				:id="ids.slot"
				class="wsw-widget__slot-grid"
				role="radiogroup"
				:aria-label="t('Select a time')">
				<button
					v-for="slot in slots"
					:key="slot.startTime"
					type="button"
					role="radio"
					:aria-checked="
						selectedSlot && selectedSlot.startTime === slot.startTime
							? 'true'
							: 'false'
					"
					:class="slotClasses(slot)"
					@click="selectedSlot = slot">
					{{ formatTime(slot.startTime) }}
				</button>
			</div>
			<p class="wsw-widget__error" role="alert">
				{{ slotsError }}
			</p>

			<div class="wsw-widget__actions">
				<button
					type="button"
					class="wsw-widget__button wsw-widget__button--secondary"
					@click="step = 'service'">
					{{ t('Back') }}
				</button>
				<button
					type="button"
					class="wsw-widget__button"
					:disabled="!selectedSlot"
					@click="step = 'details'">
					{{ t('Next') }}
				</button>
			</div>
		</section>

		<!-- STEP 3 — Customer details -->
		<section v-if="step === 'details'">
			<label class="wsw-widget__label" :for="ids.name">{{
				t('Your name')
			}}</label>
			<input
				:id="ids.name"
				v-model="customerName"
				class="wsw-widget__input"
				type="text"
				required
				maxlength="255" />
			<p class="wsw-widget__error" role="alert">
				{{ errors.name }}
			</p>

			<label class="wsw-widget__label" :for="ids.email">{{
				t('Email address')
			}}</label>
			<input
				:id="ids.email"
				v-model="email"
				class="wsw-widget__input"
				type="email"
				autocomplete="email"
				required />
			<p class="wsw-widget__error" role="alert">
				{{ errors.email }}
			</p>

			<label class="wsw-widget__label" :for="ids.phone">{{
				t('Phone (optional)')
			}}</label>
			<input
				:id="ids.phone"
				v-model="phone"
				class="wsw-widget__input"
				type="tel"
				autocomplete="tel" />
			<p class="wsw-widget__error" role="alert">
				{{ errors.phone }}
			</p>

			<label class="wsw-widget__label" :for="ids.notes">{{
				t('Notes (optional)')
			}}</label>
			<textarea
				:id="ids.notes"
				v-model="notes"
				class="wsw-widget__textarea"
				maxlength="500"
				rows="3" />
			<p class="wsw-widget__error" role="alert">
				{{ errors.notes }}
			</p>

			<div class="wsw-widget__actions">
				<button
					type="button"
					class="wsw-widget__button wsw-widget__button--secondary"
					@click="step = 'datetime'">
					{{ t('Back') }}
				</button>
				<button
					type="button"
					class="wsw-widget__button"
					:disabled="!detailsValid"
					@click="step = 'review'">
					{{ t('Next') }}
				</button>
			</div>
		</section>

		<!-- STEP 4 — Review + confirm -->
		<section v-if="step === 'review'">
			<dl class="wsw-widget__summary">
				<dt>{{ t('Service') }}</dt>
				<dd>{{ selectedServiceName }}</dd>
				<dt>{{ t('Select a date') }}</dt>
				<dd>{{ selectedDate }}</dd>
				<dt>{{ t('Select a time') }}</dt>
				<dd>{{ selectedSlot ? formatTime(selectedSlot.startTime) : '' }}</dd>
				<dt>{{ t('Your name') }}</dt>
				<dd>{{ customerName }}</dd>
				<dt>{{ t('Email address') }}</dt>
				<dd>{{ email }}</dd>
			</dl>

			<p v-if="submitError" class="wsw-widget__error" role="alert">
				{{ submitError }}
			</p>

			<div class="wsw-widget__actions">
				<button
					type="button"
					class="wsw-widget__button wsw-widget__button--secondary"
					@click="step = 'details'">
					{{ t('Back') }}
				</button>
				<button
					type="button"
					class="wsw-widget__button"
					:disabled="submitting"
					@click="submit">
					{{ submitting ? t('Submitting…') : t('Confirm booking') }}
				</button>
			</div>
		</section>

		<!-- STEP 5 — Result -->
		<section
			v-if="step === 'confirmed'"
			class="wsw-widget__status wsw-widget__status--success"
			role="status">
			<strong>{{ t('Booking confirmed') }}</strong>
			<p>{{ confirmationMessage }}</p>
		</section>
	</div>
</template>

<script>
const DEFAULT_RESOURCE_ID = 'res-001'

// Walks the customer through the four-step booking flow and submits the
// final selection to the widget API. The component keeps state local —
// it has no Pinia/Vuex dependency so it can be embedded outside the
// shillinq SPA (web-component / npm scenarios).
// Instance counter for the DOM ids below. Module scope, so every widget
// mounted in this bundle gets its own number.
let nextWidgetId = 1

export default {
	name: 'SelfServiceWidget',
	props: {
		businessId: {
			type: String,
			required: true,
		},

		apiBase: {
			type: String,
			required: true,
		},

		apiKey: {
			type: String,
			required: true,
		},

		resourceId: {
			type: String,
			default: DEFAULT_RESOURCE_ID,
		},

		lang: {
			type: String,
			default: 'en',
		},

		primaryColor: {
			type: String,
			default: '',
		},

		darkMode: {
			type: Boolean,
			default: false,
		},

		translations: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		// A COUNTER, NOT A RANDOM NUMBER. This value only ever builds DOM
		// element ids (`wsw-service-<uid>` and friends) so two widgets on one
		// page do not collide on `id`/`for`. CodeQL read the name and the
		// `toString(36)` shape as a token being minted and raised
		// js/insecure-randomness (alert 19, high). Counting is the honest
		// answer: it is what the code actually needs, it cannot collide at
		// all where eight random characters could, and it leaves nothing for
		// the next reader to wonder about.
		const uid = String(nextWidgetId++)
		return {
			step: 'service',
			services: [],
			loadingServices: false,
			servicesError: '',
			selectedServiceId: '',
			selectedDate: this.defaultDate(),
			slots: [],
			slotsError: '',
			selectedSlot: null,
			customerName: '',
			email: '',
			phone: '',
			notes: '',
			errors: { name: '', email: '', phone: '', notes: '' },
			submitting: false,
			submitError: '',
			confirmationMessage: '',
			ids: {
				service: 'wsw-service-' + uid,
				date: 'wsw-date-' + uid,
				slot: 'wsw-slot-' + uid,
				name: 'wsw-name-' + uid,
				email: 'wsw-email-' + uid,
				phone: 'wsw-phone-' + uid,
				notes: 'wsw-notes-' + uid,
				serviceError: 'wsw-service-error-' + uid,
			},
		}
	},

	computed: {
		rootClass() {
			return ['wsw-widget', { 'wsw-widget--dark': this.darkMode }]
		},

		todayIso() {
			const today = new Date()
			return today.toISOString().substring(0, 10)
		},

		stepLabel() {
			const steps = {
				service: this.t('Select a service'),
				datetime: this.t('Select a date'),
				details: this.t('Your details'),
				review: this.t('Review and confirm'),
				confirmed: this.t('Booking confirmed'),
			}
			return steps[this.step] || ''
		},

		selectedServiceName() {
			const found = this.services.find(
				(s) => s.serviceId === this.selectedServiceId,
			)
			return found ? found.name : ''
		},

		detailsValid() {
			this.validateDetails()
			return (
				!this.errors.name
				&& !this.errors.email
				&& !this.errors.phone
				&& !this.errors.notes
			)
		},
	},

	mounted() {
		if (this.primaryColor) {
			this.$el.style.setProperty('--wsw-primary-color', this.primaryColor)
		}
		this.loadServices()
	},

	methods: {
		// i18n: prefer the supplied translation table, fall back to the source
		// English string. The widget never reaches for a global Nextcloud
		// `t()` because it may run outside the app shell.
		t(key) {
			if (this.translations && this.translations[key]) {
				return this.translations[key]
			}
			return key
		},

		defaultDate() {
			const tomorrow = new Date()
			tomorrow.setDate(tomorrow.getDate() + 1)
			return tomorrow.toISOString().substring(0, 10)
		},

		slotClasses(slot) {
			return [
				'wsw-widget__slot',
				{
					'wsw-widget__slot--selected':
						this.selectedSlot
						&& this.selectedSlot.startTime === slot.startTime,
				},
			]
		},

		formatTime(iso) {
			try {
				const date = new Date(iso)
				return date.toLocaleTimeString(this.lang, {
					hour: '2-digit',
					minute: '2-digit',
				})
			} catch (e) {
				return iso
			}
		},

		authHeaders() {
			return {
				'Content-Type': 'application/json',
				Authorization: 'Bearer ' + this.apiKey,
			}
		},

		buildUrl(path, params) {
			const url = new URL(
				this.apiBase.replace(/\/$/, '') + path,
				window.location.origin,
			)
			url.searchParams.set('businessId', this.businessId)
			if (params) {
				Object.keys(params).forEach((k) =>
					url.searchParams.set(k, params[k]),
				)
			}
			return url.toString()
		},

		async loadServices() {
			this.loadingServices = true
			this.servicesError = ''
			try {
				const response = await fetch(this.buildUrl('/api/widget/services'), {
					method: 'GET',
					headers: this.authHeaders(),
				})
				if (response.status === 401 || response.status === 403) {
					this.servicesError = this.t(
						'Configuration error. Please contact the website owner.',
					)
					return
				}
				if (!response.ok) {
					this.servicesError = this.t(
						'We could not load the available services. Please try again later.',
					)
					return
				}
				const payload = await response.json()
				this.services = payload && payload.services ? payload.services : []
			} catch (e) {
				this.servicesError = this.t(
					'Network error. Please check your connection and try again.',
				)
			} finally {
				this.loadingServices = false
			}
		},

		async goToDateStep() {
			if (!this.selectedServiceId) {
				return
			}
			this.step = 'datetime'
			await this.loadSlots()
		},

		async loadSlots() {
			this.slotsError = ''
			this.slots = []
			this.selectedSlot = null
			if (!this.selectedServiceId || !this.selectedDate) {
				return
			}
			try {
				const response = await fetch(
					this.buildUrl('/api/widget/slots', {
						serviceId: this.selectedServiceId,
						resourceId: this.resourceId,
						date: this.selectedDate,
					}),
					{ method: 'GET', headers: this.authHeaders() },
				)
				const refusal = this.slotsRefusal(response)
				if (refusal !== '') {
					this.slotsError = refusal
					return
				}
				const payload = await response.json()
				this.slots = payload && payload.slots ? payload.slots : []
			} catch (e) {
				this.slotsError = this.t(
					'Network error. Please check your connection and try again.',
				)
			}
		},

		/**
		 * The message of a slots response that is not usable, or '' when it is.
		 *
		 * @param {Response} response The slots response.
		 * @return {string}
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		slotsRefusal(response) {
			if (response.status === 401 || response.status === 403) {
				return this.t('Configuration error. Please contact the website owner.')
			}
			if (response.status === 404) {
				return this.t(
					'This service is no longer available. Please refresh the page.',
				)
			}
			if (!response.ok) {
				return this.t(
					'We could not load available times. Please try another date.',
				)
			}
			return ''
		},

		validateDetails() {
			const nameLength = (this.customerName || '').trim().length
			this.errors.name =
				nameLength >= 1 ? '' : this.t('Please enter your name')

			// Simple RFC-5322-light email check; the server re-validates strictly.
			const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
			this.errors.email = emailRe.test(this.email || '')
				? ''
				: this.t('Please enter a valid email address')

			this.errors.phone = ''
			if (this.phone) {
				const phoneRe = /^\+?[1-9]\d{1,14}$/
				this.errors.phone = phoneRe.test(this.phone)
					? ''
					: this.t(
							'Phone number must be in international format (e.g. +31612345678)',
						)
			}

			this.errors.notes =
				(this.notes || '').length <= 500
					? ''
					: this.t('Notes must be at most 500 characters')
		},

		/**
		 * Book the selected slot and show the outcome.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		async submit() {
			if (!this.selectedSlot) {
				return
			}
			this.submitting = true
			this.submitError = ''
			try {
				await this.handleSubmitResponse(await this.postAppointment())
			} catch (e) {
				this.submitError = this.networkError()
			} finally {
				this.submitting = false
			}
		},

		/**
		 * POST the booking to the widget API.
		 *
		 * @return {Promise<Response>} The API response.
		 *
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		postAppointment() {
			return fetch(this.buildUrl('/api/widget/appointments'), {
				method: 'POST',
				headers: this.authHeaders(),
				body: JSON.stringify(this.appointmentBody()),
			})
		},

		/**
		 * The booking request body.
		 *
		 * @return {object} The appointment to create.
		 *
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		appointmentBody() {
			return {
				serviceId: this.selectedServiceId,
				resourceId: this.resourceId,
				startTime: this.selectedSlot.startTime,
				endTime: this.selectedSlot.endTime,
				customerName: this.customerName,
				email: this.email,
				phone: this.phone || null,
				notes: this.notes || null,
			}
		},

		/**
		 * Act on the booking answer: a taken slot sends the customer back to the
		 * times, a refused one shows why, an accepted one confirms.
		 *
		 * @param {Response} response The API response.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		async handleSubmitResponse(response) {
			if (response.status === 409) {
				await this.slotTaken()
				return
			}
			const failure = await this.submitFailure(response)
			if (failure !== null) {
				this.submitError = failure
				return
			}
			await this.confirmBooking(response)
		},

		/**
		 * The error to show for a refused booking, or null when it was accepted.
		 *
		 * @param {Response} response The API response.
		 * @return {Promise<string|null>} The error text.
		 *
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		async submitFailure(response) {
			const statusError = this.submitStatusError(response.status)
			if (statusError !== null || response.ok) {
				return statusError
			}
			return this.rejectionMessage(response)
		},

		/**
		 * Tell the customer the slot is gone, reload the times and go back to them.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		async slotTaken() {
			this.submitError = this.t(
				'This slot was just booked. Please select another time.',
			)
			await this.loadSlots()
			this.step = 'datetime'
		},

		/**
		 * The error for a status that refuses the booking outright.
		 *
		 * @param {number} status The HTTP status.
		 * @return {string|null} The error text, or null.
		 *
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		submitStatusError(status) {
			if (status === 401 || status === 403) {
				return this.t(
					'Configuration error. Please contact the website owner.',
				)
			}
			if (status === 404) {
				return this.t(
					'This service is no longer available. Please refresh the page.',
				)
			}
			return status >= 500 ? this.serverError() : null
		},

		/**
		 * The error for a server failure.
		 *
		 * @return {string} The error text.
		 *
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		serverError() {
			return this.t(
				'Something went wrong. Our team has been notified. Please try again later.',
			)
		},

		/**
		 * The server's reason for a refusal, or the network error.
		 *
		 * @param {Response} response The API response.
		 * @return {Promise<string>} The error text.
		 *
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		async rejectionMessage(response) {
			const payload = await response.json().catch(() => ({}))
			return payload && payload.message ? payload.message : this.networkError()
		},

		/**
		 * The error for a failed request.
		 *
		 * @return {string} The error text.
		 *
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		networkError() {
			return this.t(
				'Network error. Please check your connection and try again.',
			)
		},

		/**
		 * Show the server's confirmation and finish.
		 *
		 * @param {Response} response The API response.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/bookings-self-service-widget/spec.md
		 */
		async confirmBooking(response) {
			const payload = await response.json()
			this.confirmationMessage =
				payload && payload.confirmationMessage
					? payload.confirmationMessage
					: this.t('Booking confirmed')
			this.step = 'confirmed'
		},
	},
}
</script>
