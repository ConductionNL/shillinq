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
import { translateSections } from '../cluster-overview/clusterSections.js'

// Each label is a function, so its literal t() call runs when the page renders.
const SECTIONS = [
	{
		id: 'banking',
		label: () => t('shillinq', 'Banking & Treasury'),
		cards: [
			{
				id: 'Treasury',
				label: () => t('shillinq', 'Treasury'),
				route: 'TreasuryDashboard',
			},
			{
				id: 'BankAccounts',
				label: () => t('shillinq', 'Bank Accounts'),
				route: 'BankAccounts',
			},
			{
				id: 'CurrencyBalances',
				label: () => t('shillinq', 'Currency Balances'),
				route: 'CurrencyBalances',
			},
			{
				id: 'FXRates',
				label: () => t('shillinq', 'FX Rates'),
				route: 'FXRates',
			},
		],
	},
	{
		id: 'reconciliation',
		label: () => t('shillinq', 'Reconciliation'),
		cards: [
			{
				id: 'Reconciliations',
				label: () => t('shillinq', 'Reconciliations'),
				route: 'Reconciliations',
			},
			{
				id: 'VarianceReport',
				label: () => t('shillinq', 'Variance Report'),
				route: 'VarianceReport',
			},
			{
				id: 'UnmatchedItems',
				label: () => t('shillinq', 'Unmatched Items'),
				route: 'UnmatchedItems',
			},
		],
	},
	{
		id: 'cashflow',
		label: () => t('shillinq', 'Cashflow'),
		cards: [
			{
				id: 'CashflowDashboard',
				label: () => t('shillinq', 'Cashflow Dashboard'),
				route: 'CashflowDashboard',
			},
			{
				id: 'CashflowScenarios',
				label: () => t('shillinq', 'Scenarios'),
				route: 'CashflowScenarios',
			},
		],
		// A "Budgets" card is reserved here for a later change — not shipped by this one.
	},
]

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
			return translateSections(SECTIONS)
		},
	},

	methods: { t },
}
</script>
