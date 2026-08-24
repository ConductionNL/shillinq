<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Vendor Performance Detail view (slice 10 of bookkeeping-purchase-order-3way).

 Renders the monthly scorecard for one VendorPerformance record: the four
 component rates (onTimeDelivery, quantityAccuracy, priceAccuracy,
 invoiceAccuracy) as basis-point percentage pills, the weighted overall
 score, the period-over-period trend pill (improving / stable / declining),
 the dispute count + average resolution days and the auto-review
 eligibility badge.

 Drill-through is provided via the "Related" block which links to the
 supplier's PurchaseOrder list, SupplierInvoice list and ThreeWayMatch
 index — the per-month rates above are computed from those exact records
 so the operator can cross-check the underlying activity.

 Data flow: GET /apps/openregister/api/objects/shillinq/VendorPerformance/{id}
 — the route is the standard OR object endpoint and the scorecard is
 read-only from the UI's perspective (the cron writes it).

 @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
-->
<template>
	<div class="vp-detail">
		<header class="vp-detail__header">
			<h2 data-testid="vp-detail-title">
				{{ t('shillinq', 'Vendor performance') }}
			</h2>
			<p class="vp-detail__hint">
				{{
					t(
						'shillinq',
						'Monthly scorecard computed by the vendor performance aggregation cron.',
					)
				}}
			</p>
		</header>

		<div
			v-if="loading"
			class="vp-detail__loading"
			data-testid="vp-detail-loading">
			{{ t('shillinq', 'Loading scorecard…') }}
		</div>

		<div
			v-else-if="error"
			class="vp-detail__error"
			data-testid="vp-detail-error">
			{{ error }}
		</div>

		<div v-else-if="scorecard" class="vp-detail__body">
			<section class="vp-detail__summary" data-testid="vp-detail-summary">
				<div class="vp-detail__field">
					<span class="vp-detail__label">{{
						t('shillinq', 'Supplier')
					}}</span>
					<span class="vp-detail__value" data-testid="vp-supplier">{{
						scorecard.supplierId || '—'
					}}</span>
				</div>
				<div class="vp-detail__field">
					<span class="vp-detail__label">{{
						t('shillinq', 'Period')
					}}</span>
					<span class="vp-detail__value" data-testid="vp-period">{{
						scorecard.period || '—'
					}}</span>
				</div>
				<div class="vp-detail__field">
					<span class="vp-detail__label">{{
						t('shillinq', 'Overall score')
					}}</span>
					<span
						class="vp-detail__score"
						:class="scoreClass(scorecard.overallScore)"
						data-testid="vp-overall-score">
						{{ formatBp(scorecard.overallScore) }}
					</span>
				</div>
				<div class="vp-detail__field">
					<span class="vp-detail__label">{{
						t('shillinq', 'Trend')
					}}</span>
					<span
						class="vp-detail__pill"
						:class="`vp-detail__pill--${scorecard.scoreTrend || 'stable'}`"
						data-testid="vp-trend">
						{{ trendLabel(scorecard.scoreTrend) }}
					</span>
				</div>
				<div class="vp-detail__field">
					<span class="vp-detail__label">{{
						t('shillinq', 'Auto-review eligible')
					}}</span>
					<span
						class="vp-detail__pill"
						:class="
							scorecard.automatedReviewEligible
								? 'vp-detail__pill--eligible'
								: 'vp-detail__pill--ineligible'
						"
						data-testid="vp-eligible-badge">
						{{
							scorecard.automatedReviewEligible
								? t('shillinq', 'Yes')
								: t('shillinq', 'No')
						}}
					</span>
				</div>
			</section>

			<section class="vp-detail__rates">
				<h3>{{ t('shillinq', 'Component rates') }}</h3>
				<table class="vp-detail__table" data-testid="vp-rates-table">
					<thead>
						<tr>
							<th scope="col">{{ t('shillinq', 'Rate') }}</th>
							<th scope="col">{{ t('shillinq', 'Weight') }}</th>
							<th scope="col">{{ t('shillinq', 'Value') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr data-testid="vp-row-onTime">
							<td>{{ t('shillinq', 'On-time delivery') }}</td>
							<td>40 %</td>
							<td>{{ formatBp(scorecard.onTimeDeliveryRate) }}</td>
						</tr>
						<tr data-testid="vp-row-quantity">
							<td>{{ t('shillinq', 'Quantity accuracy') }}</td>
							<td>30 %</td>
							<td>{{ formatBp(scorecard.quantityAccuracyRate) }}</td>
						</tr>
						<tr data-testid="vp-row-price">
							<td>{{ t('shillinq', 'Price accuracy') }}</td>
							<td>20 %</td>
							<td>{{ formatBp(scorecard.priceAccuracyRate) }}</td>
						</tr>
						<tr data-testid="vp-row-invoice">
							<td>{{ t('shillinq', 'Invoice accuracy') }}</td>
							<td>10 %</td>
							<td>{{ formatBp(scorecard.invoiceAccuracyRate) }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="vp-detail__disputes" data-testid="vp-detail-disputes">
				<h3>{{ t('shillinq', 'Disputes') }}</h3>
				<dl class="vp-detail__dl">
					<dt>{{ t('shillinq', 'Raised this period') }}</dt>
					<dd data-testid="vp-dispute-count">
						{{ scorecard.disputeCount || 0 }}
					</dd>
					<dt>{{ t('shillinq', 'Avg. resolution days') }}</dt>
					<dd data-testid="vp-avg-resolution">
						{{ scorecard.averageResolutionDays || 0 }}
					</dd>
				</dl>
			</section>

			<section class="vp-detail__related">
				<h3>{{ t('shillinq', 'Related') }}</h3>
				<ul>
					<li>
						<router-link
							v-if="scorecard.supplierId"
							:to="{
								name: 'PurchaseOrderIndex',
								query: { supplierId: scorecard.supplierId },
							}"
							data-testid="vp-link-pos">
							{{ t('shillinq', 'Purchase orders for this supplier') }}
						</router-link>
					</li>
					<li>
						<router-link
							v-if="scorecard.supplierId"
							:to="{
								name: 'SupplierInvoiceIndex',
								query: { supplierId: scorecard.supplierId },
							}"
							data-testid="vp-link-invoices">
							{{ t('shillinq', 'Supplier invoices') }}
						</router-link>
					</li>
					<li>
						<router-link
							:to="{ name: 'ThreeWayMatchIndex' }"
							data-testid="vp-link-matches">
							{{ t('shillinq', 'Three-way matches') }}
						</router-link>
					</li>
				</ul>
			</section>
		</div>

		<p v-else class="vp-detail__empty" data-testid="vp-detail-empty">
			{{ t('shillinq', 'No scorecard found.') }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

export default {
	name: 'VendorPerformanceDetail',
	props: {
		/**
		 * Scorecard id (resolved by the manifest router from the
		 * /:id route segment).
		 */
		id: {
			type: String,
			default: '',
		},

		/**
		 * Administration scope (server-resolved at the call site).
		 */
		administrationId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			scorecard: null,
			loading: true,
			error: '',
		}
	},

	async created() {
		await this.loadScorecard()
	},

	methods: {
		async loadScorecard() {
			this.loading = true
			this.error = ''
			const id = this.id || this.$route?.params?.id
			if (!id) {
				this.error = this.t('shillinq', 'Scorecard id is required')
				this.loading = false
				return
			}
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/${REGISTER_SLUG}/VendorPerformance/${id}`,
					),
				)
				this.scorecard = response.data || null
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load scorecard')
			} finally {
				this.loading = false
			}
		},

		formatBp(bp) {
			if (bp === null || bp === undefined) {
				return '—'
			}
			const value = Number(bp)
			if (!Number.isFinite(value)) {
				return '—'
			}
			return `${(value / 100).toFixed(2)} %`
		},

		scoreClass(bp) {
			const value = Number(bp || 0)
			if (value >= 9600) {
				return 'vp-detail__score--high'
			}
			if (value >= 8500) {
				return 'vp-detail__score--mid'
			}
			return 'vp-detail__score--low'
		},

		trendLabel(trend) {
			const labels = {
				improving: this.t('shillinq', 'Improving'),
				stable: this.t('shillinq', 'Stable'),
				declining: this.t('shillinq', 'Declining'),
			}
			return labels[trend] || this.t('shillinq', 'Stable')
		},
	},
}
</script>

<style scoped>
.vp-detail {
	padding: 1rem;
}

.vp-detail__header h2 {
	margin: 0 0 0.25rem 0;
}

.vp-detail__hint {
	color: var(--color-text-maxcontrast);
	margin: 0 0 1rem 0;
}

.vp-detail__loading,
.vp-detail__error,
.vp-detail__empty {
	padding: 1rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.vp-detail__error {
	color: var(--color-error);
}

.vp-detail__summary {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 0.75rem 1.25rem;
	background: var(--color-background-hover);
	padding: 0.75rem 1rem;
	border-radius: var(--border-radius-large);
	margin-bottom: 1rem;
}

.vp-detail__field {
	display: flex;
	flex-direction: column;
}

.vp-detail__label {
	font-size: 0.875rem;
	color: var(--color-text-lighter);
}

.vp-detail__value {
	font-weight: 600;
}

.vp-detail__score {
	display: inline-block;
	padding: 0.125rem 0.5rem;
	border-radius: var(--border-radius);
	font-weight: 700;
}

.vp-detail__score--high {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.vp-detail__score--mid {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.vp-detail__score--low {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.vp-detail__pill {
	display: inline-block;
	padding: 0.125rem 0.5rem;
	border-radius: var(--border-radius-pill);
	font-size: 0.875rem;
	background: var(--color-background-hover);
}

.vp-detail__pill--improving,
.vp-detail__pill--eligible {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.vp-detail__pill--stable {
	background: var(--color-background-darker);
}

.vp-detail__pill--declining {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.vp-detail__pill--ineligible {
	background: var(--color-background-darker);
}

.vp-detail__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 1rem;
}

.vp-detail__table th,
.vp-detail__table td {
	padding: 0.5rem 0.75rem;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.vp-detail__dl {
	display: grid;
	grid-template-columns: max-content max-content;
	gap: 0.25rem 1.5rem;
}

.vp-detail__dl dt {
	font-weight: 600;
}

.vp-detail__related ul {
	list-style: disc;
	margin-left: 1.5rem;
}
</style>
