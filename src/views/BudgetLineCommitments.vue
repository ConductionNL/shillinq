<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Budget Line Commitments drilldown (verplichtingen-commitment-accounting
 Task 4 / REQ-VPL-011).

 Renders the declarative `committedVsRealisedPerBudgetLine` aggregation
 declared on CommitmentLine (geautoriseerd / verplicht / gerealiseerd /
 vrij per budget coderingscombinatie) and lets a controller drill from a
 budget line into its underlying Commitment records. Reads through
 OpenRegister's existing aggregation + list API — no bespoke shillinq
 controller/endpoint (REQ-VPL-011: "no parallel PHP reporting service").

 @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
-->
<template>
	<NcAppContent>
		<div class="budget-line-commitments">
			<header class="budget-line-commitments__header">
				<h2 class="budget-line-commitments__title">
					{{ t('shillinq', 'Committed vs. realised per budget line') }}
				</h2>
				<p class="budget-line-commitments__description">
					{{
						t(
							'shillinq',
							'Per-budget-line breakdown of authorized, committed, realised and available budget, drilling down to the underlying commitments.',
						)
					}}
				</p>
			</header>

			<section class="budget-line-commitments__body">
				<NcLoadingIcon
					v-if="loading"
					:size="32"
					:name="t('shillinq', 'Loading budget lines')" />
				<NcEmptyContent
					v-else-if="!rows.length"
					:name="t('shillinq', 'No budget lines')"
					:description="
						t(
							'shillinq',
							'No commitment line records exist yet. Approve a purchase order or sign a contract to materialise a commitment.',
						)
					" />
				<table v-else class="budget-line-commitments__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('shillinq', 'Programme') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'Cost center') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'Fiscal year') }}
							</th>
							<th scope="col">
								{{ t('shillinq', 'GL account') }}
							</th>
							<th
								scope="col"
								class="budget-line-commitments__amount-col">
								{{ t('shillinq', 'Authorized') }}
							</th>
							<th
								scope="col"
								class="budget-line-commitments__amount-col">
								{{ t('shillinq', 'Committed') }}
							</th>
							<th
								scope="col"
								class="budget-line-commitments__amount-col">
								{{ t('shillinq', 'Realised') }}
							</th>
							<th
								scope="col"
								class="budget-line-commitments__amount-col">
								{{ t('shillinq', 'Available') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<!-- Vue 3 requires the v-for key on the <template> itself; the
						     Vue 2 spelling put one on each child and is a compile error. -->
						<template v-for="row in rows" :key="row.key">
							<tr
								class="budget-line-commitments__row"
								data-testid="budget-line-row"
								tabindex="0"
								role="button"
								:aria-expanded="expandedKey === row.key"
								@click="toggleDrilldown(row)"
								@keyup.enter="toggleDrilldown(row)">
								<th scope="row">
									{{ row.programme || '—' }}
								</th>
								<td>{{ row.cost_centre || '—' }}</td>
								<td>{{ row.financial_year ?? '—' }}</td>
								<td>{{ row.general_ledger_account || '—' }}</td>
								<td class="budget-line-commitments__amount-cell">
									{{ formatAmount(row.geautoriseerd) }}
								</td>
								<td class="budget-line-commitments__amount-cell">
									{{ formatAmount(row.mandatory) }}
								</td>
								<td class="budget-line-commitments__amount-cell">
									{{ formatAmount(row.gerealiseerd) }}
								</td>
								<td
									class="budget-line-commitments__amount-cell"
									:class="{
										'budget-line-commitments__amount-cell--negative':
											row.vrij < 0,
									}">
									{{ formatAmount(row.vrij) }}
								</td>
							</tr>
							<tr
								v-if="expandedKey === row.key"
								class="budget-line-commitments__drilldown-row">
								<td colspan="8">
									<NcLoadingIcon
										v-if="drilldownLoading"
										:size="20" />
									<p
										v-else-if="!drilldownItems.length"
										class="budget-line-commitments__drilldown-empty">
										{{
											t(
												'shillinq',
												'No underlying commitments found for this line.',
											)
										}}
									</p>
									<ul
										v-else
										class="budget-line-commitments__drilldown-list"
										data-testid="budget-line-drilldown">
										<li
											v-for="item in drilldownItems"
											:key="item.id || item.commitment">
											<span
												class="budget-line-commitments__drilldown-commitment"
												>{{ item.commitment }}</span
											>
											<span
												class="budget-line-commitments__drilldown-amount"
												>{{
													formatAmount(
														item.amount_excl_vat,
													)
												}}</span
											>
										</li>
									</ul>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
				<p
					v-if="errorMessage"
					class="budget-line-commitments__error"
					role="alert">
					{{ errorMessage }}
				</p>
			</section>
		</div>
	</NcAppContent>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcAppContent, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import {
	drilldownFilters,
	formatAmount,
	normaliseBudgetLineRows,
} from './budgetLineCommitmentsHelpers.js'

const REGISTER_SLUG = 'shillinq'

export default {
	name: 'BudgetLineCommitments',

	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			errorMessage: '',
			rows: [],
			expandedKey: null,
			drilldownLoading: false,
			drilldownItems: [],
		}
	},

	mounted() {
		this.loadRows()
	},

	methods: {
		formatAmount,
		/** @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md */
		async loadRows() {
			this.loading = true
			this.errorMessage = ''
			this.rows = []

			try {
				const url = generateUrl(
					`/apps/openregister/api/objects/aggregations/${REGISTER_SLUG}/CommitmentLine/committedVsRealisedPerBudgetLine`,
				)
				const { data } = await axios.get(url)
				this.rows = normaliseBudgetLineRows(data)
			} catch (error) {
				const status = error?.response?.status
				if (status === 404 || status === 501) {
					this.errorMessage = this.t(
						'shillinq',
						'Aggregation endpoint unavailable on this OpenRegister build.',
					)
				} else if (status === 401 || status === 403) {
					this.errorMessage = this.t(
						'shillinq',
						'Permission required to read budget-line data.',
					)
				} else {
					this.errorMessage = this.t(
						'shillinq',
						'Failed to load budget lines.',
					)
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Expand/collapse the drilldown for a budget-line row.
		 *
		 * @param {object} row Normalised budget-line row.
		 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
		 */
		async toggleDrilldown(row) {
			if (this.expandedKey === row.key) {
				this.expandedKey = null
				this.drilldownItems = []
				return
			}

			this.expandedKey = row.key
			this.drilldownLoading = true
			this.drilldownItems = []

			try {
				const filters = drilldownFilters(row)
				const params = {}
				Object.keys(filters).forEach((key) => {
					params[`filters[${key}]`] = filters[key]
				})
				const url = generateUrl(
					`/apps/openregister/api/objects/${REGISTER_SLUG}/CommitmentLine`,
				)
				const { data } = await axios.get(url, { params })
				const items = Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
				this.drilldownItems = items
			} catch (error) {
				this.drilldownItems = []
			} finally {
				this.drilldownLoading = false
			}
		},
	},
}
</script>

<style scoped>
.budget-line-commitments {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.budget-line-commitments__title {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
}

.budget-line-commitments__description {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 3);
	color: var(--color-text-maxcontrast, #555);
}

.budget-line-commitments__table {
	width: 100%;
	border-collapse: collapse;
}

.budget-line-commitments__table th,
.budget-line-commitments__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border, #ddd);
	text-align: left;
}

.budget-line-commitments__row {
	cursor: pointer;
}

.budget-line-commitments__row:hover,
.budget-line-commitments__row:focus {
	background-color: var(--color-background-hover, #f5f5f5);
}

.budget-line-commitments__amount-col,
.budget-line-commitments__amount-cell {
	text-align: right;
	font-variant-numeric: tabular-nums;
}

.budget-line-commitments__amount-cell--negative {
	color: var(--color-error, #d40000);
}

.budget-line-commitments__drilldown-row td {
	background-color: var(--color-background-dark, #f0f0f0);
}

.budget-line-commitments__drilldown-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.budget-line-commitments__drilldown-list li {
	display: flex;
	justify-content: space-between;
	gap: 12px;
}

.budget-line-commitments__drilldown-empty {
	margin: 0;
	color: var(--color-text-maxcontrast, #777);
}

.budget-line-commitments__error {
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
	color: var(--color-error, #d40000);
}
</style>
