<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  Klantbeeld Timeline — slice 06 of bookings-pipelinq-customer-bridge.

  Renders the transaction history surface for the Booking detail page.
  Consumes the slice-05 controller payload directly so the parent view
  only has to pass the raw response. Four mutually-exclusive view states
  drive the markup (`hidden` / `unavailable` / `empty` / `ok`) per
  `selectHistoryState()` in usePipelinqProfile.

  Pagination is "Load more": clicking the button emits `load-more` with the
  next `{ limit, offset }` page params; the parent view fetches and
  appends. The component is intentionally stateless — append happens at
  the parent so the detail view owns the merged transaction list across
  pages and a re-mount doesn't blow accumulated rows away.

  @spec openspec/changes/bookings-pipelinq-customer-bridge-06-profile-card-ui/tasks.md
-->
<template>
	<section
		v-if="state !== 'hidden'"
		class="klantbeeld-timeline"
		data-testid="klantbeeld-timeline"
		:data-state="state">
		<header class="klantbeeld-timeline__header">
			<h3 class="klantbeeld-timeline__title">
				{{ label('Transaction history') }}
			</h3>
			<span
				v-if="state === 'ok'"
				class="klantbeeld-timeline__count"
				data-testid="klantbeeld-timeline-count">
				{{ transactions.length }}
			</span>
		</header>

		<!-- Unavailable — klantbeeld 5xx'd but Contact succeeded. -->
		<p
			v-if="state === 'unavailable'"
			class="klantbeeld-timeline__fallback klantbeeld-timeline__fallback--unavailable"
			data-testid="klantbeeld-timeline-unavailable">
			{{ label('History unavailable') }}
		</p>

		<!-- Empty — call succeeded, zero rows. -->
		<p
			v-else-if="state === 'empty'"
			class="klantbeeld-timeline__fallback"
			data-testid="klantbeeld-timeline-empty">
			{{ label('No previous transactions') }}
		</p>

		<!-- OK — rows + optional Load more. -->
		<ol
			v-else
			class="klantbeeld-timeline__list"
			data-testid="klantbeeld-timeline-list">
			<li
				v-for="(row, index) in transactions"
				:key="rowKey(row, index)"
				class="klantbeeld-timeline__row"
				:data-testid="`klantbeeld-row-${index}`"
				:data-status="row.status || ''">
				<div
					class="klantbeeld-timeline__row-date"
					:data-testid="`klantbeeld-row-${index}-date`">
					{{ formatDate(row.date) }}
				</div>
				<div
					class="klantbeeld-timeline__row-description"
					:data-testid="`klantbeeld-row-${index}-description`">
					{{ row.description || label('(no description)') }}
				</div>
				<div
					class="klantbeeld-timeline__row-amount"
					:data-testid="`klantbeeld-row-${index}-amount`">
					{{ formatAmount(row) }}
				</div>
				<div
					v-if="row.status"
					class="klantbeeld-timeline__row-status"
					:data-testid="`klantbeeld-row-${index}-status`">
					<span
						class="klantbeeld-timeline__pill"
						:class="`klantbeeld-timeline__pill--${row.status}`">
						{{ label(row.status) }}
					</span>
				</div>
			</li>
		</ol>

		<button
			v-if="state === 'ok' && hasMore"
			type="button"
			class="klantbeeld-timeline__load-more"
			data-testid="klantbeeld-timeline-load-more"
			:disabled="loading"
			@click="onLoadMore">
			{{ loading ? label('Loading...') : label('Load more') }}
		</button>
	</section>
</template>

<script>
import {
	formatTransactionAmount,
	formatTransactionDate,
	nextPageParams,
	selectHistoryState,
} from '../../composables/usePipelinqProfile.js'

export default {
	name: 'KlantbeeldTimeline',
	props: {
		/**
		 * Full slice-05 BookingDetailController response. The timeline
		 * reads `klantbeeld` and the upstream `contact*` fields to decide
		 * whether to render at all.
		 */
		payload: {
			type: Object,
			required: true,
		},

		/**
		 * Whether more pages exist after the current one. The parent
		 * decides this — typically `true` until a fetch returns fewer
		 * rows than the requested `limit`.
		 */
		hasMore: {
			type: Boolean,
			default: false,
		},

		/**
		 * Disable the Load-more button while the parent is fetching.
		 */
		loading: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		state() {
			return selectHistoryState(this.payload)
		},

		transactions() {
			const rows = this.payload?.klantbeeld?.transactions
			return Array.isArray(rows) ? rows : []
		},
	},

	methods: {
		label(key) {
			if (typeof t === 'function') {
				return t('shillinq', key)
			}
			return key
		},

		formatDate(iso) {
			return formatTransactionDate(iso)
		},

		formatAmount(row) {
			return formatTransactionAmount(row)
		},

		rowKey(row, index) {
			return `${row?.date || ''}-${row?.description || ''}-${index}`
		},

		onLoadMore() {
			this.$emit('load-more', nextPageParams(this.payload?.klantbeeld))
		},
	},
}
</script>

<style scoped>
.klantbeeld-timeline {
	background: var(--color-main-background);
	border: 1px solid var(--color-border, #d5d5d5);
	border-radius: var(--border-radius-large, 8px);
	padding: 16px;
	margin-bottom: 16px;
}

.klantbeeld-timeline__header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 8px;
	margin-bottom: 12px;
}

.klantbeeld-timeline__title {
	margin: 0;
	font-size: 1.1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.klantbeeld-timeline__count {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast, #555);
	background: var(--color-background-dark, #f0f0f0);
	padding: 1px 8px;
	border-radius: 999px;
}

.klantbeeld-timeline__fallback {
	margin: 0;
	color: var(--color-text-maxcontrast, #555);
}

.klantbeeld-timeline__fallback--unavailable {
	color: var(--color-warning, #c93);
}

.klantbeeld-timeline__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.klantbeeld-timeline__row {
	display: grid;
	grid-template-columns: 100px 1fr max-content max-content;
	gap: 8px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border, #e5e5e5);
}

.klantbeeld-timeline__row:last-child {
	border-bottom: none;
}

.klantbeeld-timeline__row-date {
	color: var(--color-text-maxcontrast, #555);
	font-variant-numeric: tabular-nums;
}

.klantbeeld-timeline__row-description {
	color: var(--color-main-text);
}

.klantbeeld-timeline__row-amount {
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	font-weight: 500;
}

.klantbeeld-timeline__pill {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 999px;
	font-size: 0.8rem;
	background: var(--color-background-dark, #ddd);
	color: var(--color-main-text);
	text-transform: capitalize;
}

.klantbeeld-timeline__pill--paid {
	background: var(--color-success, #2c7);
	color: var(--color-primary-text, #fff);
}

.klantbeeld-timeline__pill--pending {
	background: var(--color-warning, #c93);
	color: var(--color-primary-text, #fff);
}

.klantbeeld-timeline__pill--failed {
	background: var(--color-error, #c33);
	color: var(--color-primary-text, #fff);
}

.klantbeeld-timeline__pill--refunded {
	background: var(--color-background-darker, #bbb);
	color: var(--color-main-text);
}

.klantbeeld-timeline__load-more {
	margin-top: 12px;
	padding: 6px 14px;
	background: var(--color-background-dark, #f0f0f0);
	border: 1px solid var(--color-border, #d5d5d5);
	border-radius: var(--border-radius, 4px);
	color: var(--color-main-text);
	cursor: pointer;
}

.klantbeeld-timeline__load-more:hover:not(:disabled),
.klantbeeld-timeline__load-more:focus:not(:disabled) {
	background: var(--color-background-hover, #e5e5e5);
}

.klantbeeld-timeline__load-more:disabled {
	cursor: not-allowed;
	opacity: 0.6;
}
</style>
