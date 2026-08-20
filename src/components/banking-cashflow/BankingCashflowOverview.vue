<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Banking & Cashflow cluster landing page (nav-six-clusters, design.md §2
 Cluster 4). A genuinely new top-level id (BankingCashflow) — neither the
 former "Banking & Treasury" nor "Cashflow" alone named the merged domain, so
 both groups fold into this one (design.md §5, REQ-NAVC-001 scenario 2 —
 neither old id survives). Also absorbs Reconciliations + VarianceReport
 pulled out of the giant Bookkeeping group (design.md §4 row 18 — VarianceReport
 KEPT as report-shaped per orchestrator ruling, not merged).

 A "Budgets" card slot is reserved for a later change (design.md §2) — no
 page or menu node ships for it from this change; this comment is the only
 trace of the reservation.

 @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
-->
<template>
	<ClusterOverview
		clusterId="banking-cashflow"
		:title="t('shillinq', 'Banking & Cashflow')"
		:hint="
			t(
				'shillinq',
				'Bank accounts, reconciliation, treasury and cashflow forecasting.',
			)
		"
		:sections="sections" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ClusterOverview from '../cluster-overview/ClusterOverview.vue'

export default {
	name: 'BankingCashflowOverview',
	components: { ClusterOverview },
	computed: {
		/**
		 * Card-section data for this cluster's landing page — a plain, static
		 * grouping of the Banking & Cashflow cluster's absorbed children.
		 *
		 * @return {Array} The section descriptors passed to ClusterOverview.
		 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
		 */
		sections() {
			return [
				{
					id: 'banking',
					label: this.t('shillinq', 'Banking & Treasury'),
					cards: [
						{
							id: 'Treasury',
							label: this.t('shillinq', 'Treasury'),
							route: 'Treasury',
						},
						{
							id: 'BankAccounts',
							label: this.t('shillinq', 'Bank Accounts'),
							route: 'BankAccounts',
						},
						{
							id: 'CurrencyBalances',
							label: this.t('shillinq', 'Currency Balances'),
							route: 'CurrencyBalances',
						},
						{
							id: 'FXRates',
							label: this.t('shillinq', 'FX Rates'),
							route: 'FXRates',
						},
					],
				},
				{
					id: 'reconciliation',
					label: this.t('shillinq', 'Reconciliation'),
					cards: [
						{
							id: 'Reconciliations',
							label: this.t('shillinq', 'Reconciliations'),
							route: 'Reconciliations',
						},
						{
							id: 'VarianceReport',
							label: this.t('shillinq', 'Variance Report'),
							route: 'VarianceReport',
						},
						{
							id: 'UnmatchedItems',
							label: this.t('shillinq', 'Unmatched Items'),
							route: 'UnmatchedItems',
						},
					],
				},
				{
					id: 'cashflow',
					label: this.t('shillinq', 'Cashflow'),
					cards: [
						{
							id: 'CashflowDashboard',
							label: this.t('shillinq', 'Cashflow Dashboard'),
							route: 'CashflowDashboard',
						},
						{
							id: 'CashflowScenarios',
							label: this.t('shillinq', 'Scenarios'),
							route: 'CashflowScenarios',
						},
					],
					// A "Budgets" card is reserved here for a later change — not shipped by this one.
				},
			]
		},
	},

	methods: { t },
}
</script>
