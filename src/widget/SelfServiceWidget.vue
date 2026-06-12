<template>
	<div :class="['wsw', 'wsw-widget']"
		:data-wsw-dark="dark ? '1' : '0'"
		role="form"
		:aria-label="tr('Book an appointment')">
		<h2 class="wsw__title">
			{{ tr('Book an appointment') }}
		</h2>

		<!-- Configuration / fatal error -->
		<div v-if="fatalError" class="wsw__notice wsw__notice--error" role="alert">
			{{ fatalError }}
			<button v-if="retryable"
				class="wsw__button wsw__button--secondary"
				type="button"
				@click="reload">
				{{ tr('Try again') }}
			</button>
		</div>

		<!-- Success -->
		<div v-else-if="step === 'done'" class="wsw__notice wsw__notice--success" role="status">
			{{ tr('Booking confirmed') }}
		</div>

		<template v-else>
			<!-- Service + date selection -->
			<div v-if="step === 'select'">
				<div class="wsw__field">
					<label class="wsw__label" :for="ids.service">{{ tr('Service') }}</label>
					<select :id="ids.service"
						v-model="selectedService"
						class="wsw__select"
						@change="onServiceChange">
						<option value="">
							{{ tr('Choose a service') }}
						</option>
						<option v-for="s in services" :key="s.serviceSlug" :value="s.serviceSlug">
							{{ s.name }} ({{ s.durationMinutes }} {{ tr('minutes') }}<span v-if="s.price != null"> · {{ formatPrice(s) }}</span>)
						</option>
					</select>
				</div>

				<div class="wsw__field">
					<label class="wsw__label" :for="ids.date">{{ tr('Date') }}</label>
					<input :id="ids.date"
						v-model="selectedDate"
						class="wsw__input"
						type="date"
						:min="today"
						@change="onDateChange">
				</div>

				<div v-if="selectedService && selectedDate" class="wsw__field">
					<span class="wsw__label">{{ tr('Available times') }}</span>
					<div v-if="loadingSlots" class="wsw__tz">
						…
					</div>
					<div v-else-if="slots.length === 0" class="wsw__tz">
						{{ tr('No times available for this day') }}
					</div>
					<div v-else
						class="wsw__slots"
						role="listbox"
						:aria-label="tr('Available times')">
						<button
							v-for="slot in slots"
							:key="slot.startTime"
							type="button"
							role="option"
							:aria-selected="selectedSlot && selectedSlot.startTime === slot.startTime"
							:class="['wsw__slot', { 'wsw__slot--selected': selectedSlot && selectedSlot.startTime === slot.startTime }]"
							@click="selectSlot(slot)">
							{{ formatTime(slot.startTime) }}
						</button>
					</div>
					<p class="wsw__tz">
						{{ tr('Time') }}: {{ tzLabel }}
					</p>
				</div>

				<button class="wsw__button"
					type="button"
					:disabled="!selectedSlot"
					@click="step = 'details'">
					{{ tr('Your details') }}
				</button>
			</div>

			<!-- Customer details -->
			<div v-else-if="step === 'details'">
				<div class="wsw__field">
					<label class="wsw__label" :for="ids.name">{{ tr('Name') }}</label>
					<input :id="ids.name"
						v-model.trim="customer.name"
						class="wsw__input"
						type="text"
						maxlength="255"
						required>
					<span v-if="touched.name && !nameValid" class="wsw__error">{{ tr('Please enter your name') }}</span>
				</div>
				<div class="wsw__field">
					<label class="wsw__label" :for="ids.email">{{ tr('Email') }}</label>
					<input :id="ids.email"
						v-model.trim="customer.email"
						class="wsw__input"
						type="email"
						required
						@blur="touched.email = true">
					<span v-if="touched.email && !emailValid" class="wsw__error">{{ tr('Please enter a valid email address') }}</span>
				</div>
				<div class="wsw__field">
					<label class="wsw__label" :for="ids.phone">{{ tr('Phone (optional)') }}</label>
					<input :id="ids.phone"
						v-model.trim="customer.phone"
						class="wsw__input"
						type="tel"
						@blur="touched.phone = true">
					<span v-if="touched.phone && !phoneValid" class="wsw__error">{{ tr('Please enter a valid phone number') }}</span>
				</div>
				<div class="wsw__field">
					<label class="wsw__label" :for="ids.notes">{{ tr('Notes (optional)') }}</label>
					<textarea :id="ids.notes"
						v-model="customer.notes"
						class="wsw__textarea"
						maxlength="500"
						rows="3" />
				</div>

				<button class="wsw__button"
					type="button"
					:disabled="!formValid"
					@click="step = 'review'">
					{{ tr('Review your booking') }}
				</button>
				<button class="wsw__button wsw__button--secondary" type="button" @click="step = 'select'">
					{{ tr('Back') }}
				</button>
			</div>

			<!-- Review + confirm -->
			<div v-else-if="step === 'review'">
				<p><strong>{{ tr('Service') }}:</strong> {{ serviceName }}</p>
				<p><strong>{{ tr('Date') }}:</strong> {{ selectedDate }}</p>
				<p><strong>{{ tr('Time') }}:</strong> {{ selectedSlot ? formatTime(selectedSlot.startTime) : '' }} ({{ tzLabel }})</p>
				<p><strong>{{ tr('Name') }}:</strong> {{ customer.name }}</p>

				<div v-if="submitError" class="wsw__notice wsw__notice--error" role="alert">
					{{ submitError }}
				</div>

				<button class="wsw__button"
					type="button"
					:disabled="submitting"
					@click="submit">
					{{ tr('Confirm booking') }}
				</button>
				<button class="wsw__button wsw__button--secondary" type="button" @click="step = 'details'">
					{{ tr('Back') }}
				</button>
			</div>
		</template>
	</div>
</template>

<script>
import { WIDGET_STRINGS } from './strings.js'

/**
 * Embeddable self-service booking widget (REQ-WSW-003).
 *
 * Self-contained: works standalone (iframe/script/npm/web-component) without
 * Nextcloud's global `t()`. Strings are resolved from the bundled WIDGET_STRINGS
 * table keyed by the embed-time `lang`. Times are shown in the browser timezone
 * and converted to UTC before POST (REQ-WSW-007).
 */
export default {
	name: 'SelfServiceWidget',
	props: {
		/** Public business identifier (== administrationId). */
		businessId: { type: String, required: true },
		/** Absolute base URL of the widget API host. */
		apiBase: { type: String, default: '' },
		/** Per-business API key (Bearer token). */
		apiKey: { type: String, required: true },
		/** UI language, e.g. 'en' or 'nl'. */
		lang: { type: String, default: 'en' },
		/** Dark mode toggle. */
		dark: { type: Boolean, default: false },
	},
	data() {
		const uid = Math.random().toString(36).slice(2, 8)
		return {
			step: 'select',
			services: [],
			selectedService: '',
			selectedDate: '',
			slots: [],
			selectedSlot: null,
			loadingSlots: false,
			customer: { name: '', email: '', phone: '', notes: '' },
			touched: { name: false, email: false, phone: false },
			submitting: false,
			submitError: '',
			fatalError: '',
			retryable: false,
			ids: {
				service: `wsw-svc-${uid}`,
				date: `wsw-date-${uid}`,
				name: `wsw-name-${uid}`,
				email: `wsw-email-${uid}`,
				phone: `wsw-phone-${uid}`,
				notes: `wsw-notes-${uid}`,
			},
		}
	},
	computed: {
		today() {
			return new Date().toISOString().slice(0, 10)
		},
		tzLabel() {
			try {
				return Intl.DateTimeFormat().resolvedOptions().timeZone
			} catch (e) {
				return 'UTC'
			}
		},
		serviceName() {
			const s = this.services.find(x => x.serviceSlug === this.selectedService)
			return s ? s.name : ''
		},
		nameValid() {
			return this.customer.name.length >= 1 && this.customer.name.length <= 255
		},
		emailValid() {
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.customer.email)
		},
		phoneValid() {
			return this.customer.phone === '' || /^\+?[1-9]\d{1,14}$/.test(this.customer.phone)
		},
		formValid() {
			return this.nameValid && this.emailValid && this.phoneValid
		},
	},
	created() {
		this.fetchServices()
	},
	methods: {
		/**
		 * Resolve a UI string for the active language, falling back to English
		 * then to the key itself.
		 *
		 * @param {string} key The English source string.
		 * @return {string} The localized string.
		 */
		tr(key) {
			const table = WIDGET_STRINGS[this.lang] || WIDGET_STRINGS.en || {}
			return table[key] || (WIDGET_STRINGS.en && WIDGET_STRINGS.en[key]) || key
		},
		url(path) {
			const base = (this.apiBase || '').replace(/\/$/, '')
			return `${base}/index.php/apps/shillinq/api/widget/${encodeURIComponent(this.businessId)}/${path}`
		},
		/**
		 * @spec exclude request-header plumbing for the widget API client, no business behaviour
		 */
		headers() {
			return {
				'Content-Type': 'application/json',
				Authorization: `Bearer ${this.apiKey}`,
			}
		},
		formatPrice(s) {
			try {
				return new Intl.NumberFormat(this.lang, { style: 'currency', currency: s.currency || 'EUR' }).format(s.price)
			} catch (e) {
				return `${s.price}`
			}
		},
		formatTime(iso) {
			try {
				return new Intl.DateTimeFormat(this.lang, { hour: '2-digit', minute: '2-digit' }).format(new Date(iso))
			} catch (e) {
				return iso
			}
		},
		async fetchServices() {
			try {
				const res = await fetch(this.url('services'), { headers: this.headers() })
				if (res.status === 401 || res.status === 403) {
					this.fail(this.tr('Configuration error. Please contact the website owner.'), false)
					return
				}
				if (!res.ok) {
					this.fail(this.tr('Something went wrong. Please try again later.'), true)
					return
				}
				const data = await res.json()
				this.services = (data.services || [])
			} catch (e) {
				this.fail(this.tr('Network error. Please check your connection and try again.'), true)
			}
		},
		onServiceChange() {
			this.selectedSlot = null
			this.slots = []
			if (this.selectedService && this.selectedDate) {
				this.fetchSlots()
			}
		},
		onDateChange() {
			this.selectedSlot = null
			this.slots = []
			if (this.selectedService && this.selectedDate) {
				this.fetchSlots()
			}
		},
		async fetchSlots() {
			this.loadingSlots = true
			try {
				const q = `slots?serviceSlug=${encodeURIComponent(this.selectedService)}&date=${encodeURIComponent(this.selectedDate)}`
				const res = await fetch(this.url(q), { headers: this.headers() })
				if (res.status === 404) {
					this.fail(this.tr('This service is no longer available. Please refresh the page.'), true)
					return
				}
				if (!res.ok) {
					this.slots = []
					return
				}
				const data = await res.json()
				this.slots = (data.slots || [])
			} catch (e) {
				this.fail(this.tr('Network error. Please check your connection and try again.'), true)
			} finally {
				this.loadingSlots = false
			}
		},
		selectSlot(slot) {
			this.selectedSlot = slot
		},
		async submit() {
			if (!this.selectedSlot || !this.formValid) {
				return
			}
			this.submitting = true
			this.submitError = ''
			try {
				const body = {
					serviceSlug: this.selectedService,
					startTime: this.selectedSlot.startTime,
					customerName: this.customer.name,
					customerEmail: this.customer.email,
					customerPhone: this.customer.phone,
					notes: this.customer.notes,
				}
				const res = await fetch(this.url('appointments'), {
					method: 'POST',
					headers: this.headers(),
					body: JSON.stringify(body),
				})
				if (res.status === 201) {
					this.step = 'done'
					return
				}
				if (res.status === 409) {
					this.submitError = this.tr('This slot was just booked. Please select another time.')
					this.selectedSlot = null
					this.step = 'select'
					await this.fetchSlots()
					return
				}
				if (res.status === 404) {
					this.submitError = this.tr('This service is no longer available. Please refresh the page.')
					return
				}
				this.submitError = this.tr('Something went wrong. Please try again later.')
			} catch (e) {
				this.submitError = this.tr('Network error. Please check your connection and try again.')
			} finally {
				this.submitting = false
			}
		},
		fail(message, retryable) {
			this.fatalError = message
			this.retryable = retryable
		},
		reload() {
			this.fatalError = ''
			this.retryable = false
			this.step = 'select'
			this.fetchServices()
		},
	},
}
</script>
