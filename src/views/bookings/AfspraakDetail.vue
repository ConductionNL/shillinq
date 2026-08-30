<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Afspraak (Booking) Detail — slice 06 of bookings-pipelinq-customer-bridge.

  Custom page that replaces the manifest-driven `type: "detail"` for
  Appointments. Fetches the slice-05 BookingDetailController endpoint
  (`/api/v1/bookings/{id}`) which returns the Appointment + linked
  pipelinq Contact + klantbeeld history in one hop, then renders:

   - The standard Appointment fields (legacy-equivalent to what the
     manifest-driven CnDetailPage produced for AfspraakDetail).
   - The slice-06 profile card (PipelinqProfileCard.vue), with all four
     fallback states handled internally.
   - The slice-06 transaction history timeline
     (KlantbeeldTimeline.vue), with empty / unavailable / hidden states
     and "Load more" pagination wired through the slice-04 offset path.

  Registered as a `kind: "page"` custom component in src/registry.js so
  the v2 manifest router still owns the URL → component mapping
  (manifest.d/10-bookings-create-appointment.json switches the
  AfspraakDetail page to `type: "custom"`, `component: "AfspraakDetail"`).

  @spec openspec/changes/bookings-pipelinq-customer-bridge-06-profile-card-ui/tasks.md
-->
<template>
	<div class="afspraak-detail">
		<header class="afspraak-detail__header">
			<router-link
				v-if="hasIndexRoute"
				:to="{ name: 'Afspraken' }"
				class="afspraak-detail__back"
				data-testid="afspraak-detail-back">
				&larr; {{ label('Back to bookings') }}
			</router-link>
			<h1 class="afspraak-detail__title">
				{{ label('Appointment') }}
				<span v-if="bookingReference" class="afspraak-detail__reference">
					{{ bookingReference }}
				</span>
			</h1>
		</header>

		<div
			v-if="loading"
			class="afspraak-detail__loading"
			data-testid="afspraak-detail-loading">
			{{ label('Loading booking...') }}
		</div>

		<div
			v-else-if="loadError"
			class="afspraak-detail__error"
			data-testid="afspraak-detail-error">
			<p>{{ loadError }}</p>
			<button
				type="button"
				class="afspraak-detail__retry"
				data-testid="afspraak-detail-retry"
				@click="reload">
				{{ label('Retry') }}
			</button>
		</div>

		<div v-else-if="payload" class="afspraak-detail__body">
			<section
				class="afspraak-detail__summary"
				data-testid="afspraak-detail-summary">
				<dl class="afspraak-detail__summary-fields">
					<!-- Vue 3 requires the v-for key on the <template> itself. -->
					<template v-for="field in summaryFields" :key="field.key">
						<dt>
							{{ label(field.label) }}
						</dt>
						<dd :data-testid="`afspraak-detail-${field.key}`">
							{{ field.value || emptyMarker }}
						</dd>
					</template>
				</dl>
			</section>

			<PipelinqProfileCard
				:payload="payload"
				:booking="payload.booking || {}"
				:pipelinqBaseUrl="pipelinqBaseUrl" />

			<KlantbeeldTimeline
				:payload="paginatedPayload"
				:hasMore="hasMore"
				:loading="pagingLoading"
				@loadMore="onLoadMore" />

			<p
				v-if="pagingError"
				class="afspraak-detail__error"
				data-testid="afspraak-detail-paging-error">
				{{ pagingError }}
			</p>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import KlantbeeldTimeline from '../../components/pipelinq/KlantbeeldTimeline.vue'
import PipelinqProfileCard from '../../components/pipelinq/PipelinqProfileCard.vue'

const SUMMARY_FIELD_DEFS = [
	{ key: 'appointmentId', label: 'Reference' },
	{ key: 'startTime', label: 'Start (UTC)' },
	{ key: 'endTime', label: 'End (UTC)' },
	{ key: 'serviceId', label: 'Service' },
	{ key: 'resourceId', label: 'Resource' },
	{ key: 'customerId', label: 'Customer' },
	{ key: 'status', label: 'Status' },
	{ key: 'notes', label: 'Notes' },
	{ key: 'administrationId', label: 'Administration' },
	{ key: 'pipelinqContactId', label: 'Pipelinq contact' },
]

export default {
	name: 'AfspraakDetail',
	components: {
		PipelinqProfileCard,
		KlantbeeldTimeline,
	},

	props: {
		/**
		 * Appointment business id, supplied by the manifest router
		 * (props: true on `/verkoop/afspraken/:id`).
		 */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			payload: null,
			accumulatedTransactions: [],
			loading: true,
			loadError: '',
			pagingLoading: false,
			pagingError: '',
			pipelinqBaseUrl: '',
			emptyMarker: '—',
		}
	},

	computed: {
		bookingReference() {
			return this.payload?.booking?.appointmentId || this.id || ''
		},

		hasIndexRoute() {
			// The Afspraken index route is registered by the same slice-01
			// manifest fragment, so this is true in practice; the guard
			// keeps the back link safe if the route is ever removed.
			return !!this.$router?.options?.routes?.some?.(
				(r) => r.name === 'Afspraken',
			)
		},

		summaryFields() {
			const booking = this.payload?.booking || {}
			return SUMMARY_FIELD_DEFS.map((def) => ({
				key: def.key,
				label: def.label,
				value: booking[def.key] != null ? String(booking[def.key]) : '',
			}))
		},

		/**
		 * Slice-05 payload with `klantbeeld.transactions` replaced by the
		 * accumulated list across pages. Keeps the envelope shape stable
		 * so KlantbeeldTimeline's state selector keeps working.
		 *
		 * @return {object|null}
		 */
		paginatedPayload() {
			if (!this.payload) {
				return null
			}
			const k = this.payload.klantbeeld
			if (!k) {
				return this.payload
			}
			return {
				...this.payload,
				klantbeeld: {
					...k,
					transactions: this.accumulatedTransactions,
					empty:
						this.accumulatedTransactions.length === 0
						&& k.unavailable !== true,
				},
			}
		},

		/**
		 * Whether the last fetched page returned a full set of rows
		 * (i.e. there may be more). We use the >= limit heuristic so a
		 * partial page suppresses the Load-more button.
		 *
		 * @return {boolean}
		 */
		hasMore() {
			const k = this.payload?.klantbeeld
			if (!k || k.unavailable === true) {
				return false
			}
			const limit = Number(k.limit) || 0
			const lastBatchSize =
				this._lastBatchSize
				?? (Array.isArray(k.transactions) ? k.transactions.length : 0)
			return limit > 0 && lastBatchSize >= limit
		},
	},

	watch: {
		id: {
			immediate: false,
			handler() {
				this.reload()
			},
		},
	},

	async created() {
		await this.loadConfig()
		await this.reload()
	},

	methods: {
		label(key) {
			if (typeof t === 'function') {
				return t('shillinq', key)
			}
			return key
		},

		async loadConfig() {
			// Best-effort fetch of the pipelinq base url from the settings
			// API so the profile card can render an "Open in pipelinq"
			// link. Failure leaves the link hidden (slice 06 still works).
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/settings/pipelinq'),
				)
				const url =
					response?.data?.baseUrl || response?.data?.pipelinqBaseUrl || ''
				this.pipelinqBaseUrl = typeof url === 'string' ? url : ''
			} catch (e) {
				this.pipelinqBaseUrl = ''
			}
		},

		async reload() {
			this.loading = true
			this.loadError = ''
			this.pagingError = ''
			this.accumulatedTransactions = []
			this._lastBatchSize = null
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/shillinq/api/v1/bookings/${encodeURIComponent(this.id)}`,
					),
				)
				this.payload = response?.data || null
				const initial = this.payload?.klantbeeld?.transactions
				if (Array.isArray(initial)) {
					this.accumulatedTransactions = initial.slice()
					this._lastBatchSize = initial.length
				} else {
					this.accumulatedTransactions = []
					this._lastBatchSize = 0
				}
			} catch (e) {
				const status = e?.response?.status
				if (status === 404) {
					this.loadError = this.label('Booking not found')
				} else if (status === 503) {
					this.loadError = this.label(
						'OpenRegister is temporarily unavailable',
					)
				} else if (status === 401) {
					this.loadError = this.label(
						'Please sign in to view this booking',
					)
				} else {
					this.loadError =
						e?.response?.data?.error
						|| this.label('Failed to load booking')
				}
				this.payload = null
			} finally {
				this.loading = false
			}
		},

		async onLoadMore({ limit, offset }) {
			if (this.pagingLoading) {
				return
			}
			this.pagingLoading = true
			this.pagingError = ''
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/shillinq/api/v1/bookings/${encodeURIComponent(this.id)}`,
					),
					{ params: { limit, offset } },
				)
				const next = response?.data?.klantbeeld
				if (next && Array.isArray(next.transactions)) {
					this.accumulatedTransactions =
						this.accumulatedTransactions.concat(next.transactions)
					this._lastBatchSize = next.transactions.length
					// Mirror the new offset / limit back to the envelope so a
					// subsequent Load-more computes the right next page.
					this.payload = {
						...this.payload,
						klantbeeld: {
							...(this.payload?.klantbeeld || {}),
							limit: next.limit ?? limit,
							offset: next.offset ?? offset,
							unavailable: next.unavailable === true,
						},
					}
				}
			} catch (e) {
				this.pagingError =
					e?.response?.data?.error
					|| this.label('Failed to load more transactions')
			} finally {
				this.pagingLoading = false
			}
		},
	},
}
</script>

<style scoped>
.afspraak-detail {
	max-width: 960px;
	margin: 0 auto;
	padding: 16px;
}

.afspraak-detail__header {
	margin-bottom: 16px;
}

.afspraak-detail__back {
	display: inline-block;
	margin-bottom: 8px;
	color: var(--color-primary-element, #0082c9);
	text-decoration: none;
}

.afspraak-detail__back:hover,
.afspraak-detail__back:focus {
	text-decoration: underline;
}

.afspraak-detail__title {
	margin: 0;
	font-size: 1.4rem;
	color: var(--color-main-text);
}

.afspraak-detail__reference {
	font-weight: 400;
	color: var(--color-text-maxcontrast, #555);
	margin-left: 8px;
}

.afspraak-detail__loading,
.afspraak-detail__error {
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border, #d5d5d5);
	border-radius: var(--border-radius-large, 8px);
	color: var(--color-main-text);
}

.afspraak-detail__error {
	color: var(--color-error, #b00);
}

.afspraak-detail__retry {
	margin-top: 8px;
	padding: 6px 14px;
	background: var(--color-background-dark, #f0f0f0);
	border: 1px solid var(--color-border, #d5d5d5);
	border-radius: var(--border-radius, 4px);
	color: var(--color-main-text);
	cursor: pointer;
}

.afspraak-detail__summary {
	background: var(--color-main-background);
	border: 1px solid var(--color-border, #d5d5d5);
	border-radius: var(--border-radius-large, 8px);
	padding: 16px;
	margin-bottom: 16px;
}

.afspraak-detail__summary-fields {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
}

.afspraak-detail__summary-fields dt {
	font-weight: 500;
	color: var(--color-text-maxcontrast, #555);
}

.afspraak-detail__summary-fields dd {
	margin: 0;
	color: var(--color-main-text);
	overflow-wrap: break-word;
}
</style>
