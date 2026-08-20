<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Bookkeeping cluster landing page (nav-six-clusters, design.md §2 Cluster 1).

 Thin wrapper around the shared ClusterOverview card grid
 (src/components/cluster-overview/ClusterOverview.vue) — supplies this
 cluster's own card-section data. The Bookkeeping cluster absorbs Consolidation,
 Ifrs16Leases, DualGaap, Ifrs15Revenue, Projecten (folded, ProjectenOverzicht
 deleted per REQ-NAVIA-002) and Loonadministratie's payroll leaves (design.md
 §2/§7) on top of the original Bookkeeping group — the largest single cluster,
 so this page groups a representative set of its children by sub-domain
 rather than every one of its ~90 flat menu leaves (data-testid hooks are
 stable regardless of which cards a future edit adds).

 @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
-->
<template>
	<ClusterOverview
		clusterId="bookkeeping"
		:title="t('shillinq', 'Bookkeeping')"
		:hint="
			t(
				'shillinq',
				'Ledger, journals, dimensions, fiscal years, dual GAAP & IFRS, consolidation, projects and payroll.',
			)
		"
		:sections="sections" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ClusterOverview from '../cluster-overview/ClusterOverview.vue'

export default {
	name: 'BookkeepingOverview',
	components: { ClusterOverview },
	computed: {
		/**
		 * Card-section data for this cluster's landing page — a plain, static
		 * grouping of the Bookkeeping cluster's absorbed children.
		 *
		 * @return {Array} The section descriptors passed to ClusterOverview.
		 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
		 */
		sections() {
			return [
				{
					id: 'ledger',
					label: this.t('shillinq', 'Ledger & Journals'),
					cards: [
						{
							id: 'GeneralLedger',
							label: this.t('shillinq', 'General Ledger'),
							route: 'GeneralLedger',
						},
						{
							id: 'ChartOfAccounts',
							label: this.t('shillinq', 'Chart of Accounts'),
							route: 'ChartOfAccounts',
						},
						{
							id: 'Journals',
							label: this.t('shillinq', 'Manual Journals'),
							route: 'Journals',
						},
						{
							id: 'Aansluitingen',
							label: this.t('shillinq', 'Tie-outs'),
							route: 'Aansluitingen',
						},
						{
							id: 'AansluitingResultaten',
							label: this.t('shillinq', 'Tie-out results'),
							route: 'AansluitingResultaten',
						},
					],
				},
				{
					id: 'dimensions',
					label: this.t('shillinq', 'Dimensions & Projects'),
					cards: [
						{
							id: 'AnalyticalDimensions',
							label: this.t('shillinq', 'Analytical dimensions'),
							route: 'AnalyticalDimensions',
						},
						{
							id: 'CostCenters',
							label: this.t('shillinq', 'Cost centers'),
							route: 'AnalyticalDimensions',
							query: { dimensionType: 'cost-center' },
						},
						{
							id: 'Projects',
							label: this.t('shillinq', 'Projects'),
							route: 'Projects',
						},
						{
							id: 'Utilisatie',
							label: this.t('shillinq', 'Utilisation'),
							route: 'Utilisatie',
						},
					],
				},
				{
					id: 'framework',
					label: this.t('shillinq', 'Dual GAAP, IFRS & Fiscal Years'),
					cards: [
						{
							id: 'AccountingStandardsPolicy',
							label: this.t('shillinq', 'Standards policy'),
							route: 'AccountingStandardsPolicy',
						},
						{
							id: 'AccountingFrameworks',
							label: this.t('shillinq', 'Framework Configuration'),
							route: 'AccountingFrameworks',
						},
						{
							id: 'LeaseRegister',
							label: this.t('shillinq', 'Lease Register (IFRS 16)'),
							route: 'LeaseRegister',
						},
						{
							id: 'RevenueContracts',
							label: this.t('shillinq', 'Revenue Contracts (IFRS 15)'),
							route: 'RevenueContracts',
						},
						{
							id: 'YearEndCloseChecklist',
							label: this.t('shillinq', 'Year-end close checklist'),
							route: 'YearEndCloseChecklist',
						},
					],
				},
				{
					id: 'consolidation',
					label: this.t('shillinq', 'Consolidation'),
					cards: [
						{
							id: 'ConsolidationGroups',
							label: this.t('shillinq', 'Consolidation Groups'),
							route: 'ConsolidationGroups',
						},
						{
							id: 'ConsolidationPeriods',
							label: this.t('shillinq', 'Consolidation Periods'),
							route: 'ConsolidationPeriods',
						},
						{
							id: 'ConsolidatedReports',
							label: this.t('shillinq', 'Consolidated Reports'),
							route: 'ConsolidatedReports',
						},
					],
				},
				{
					id: 'payroll',
					label: this.t('shillinq', 'Payroll'),
					cards: [
						{
							id: 'Werkgevers',
							label: this.t('shillinq', 'Employers'),
							route: 'Werkgevers',
						},
						{
							id: 'Werknemers',
							label: this.t('shillinq', 'Employees'),
							route: 'Werknemers',
						},
						{
							id: 'Loonstroken',
							label: this.t('shillinq', 'Payslips'),
							route: 'Loonstroken',
						},
						{
							id: 'Loonjournaalposten',
							label: this.t('shillinq', 'Payroll journal entries'),
							route: 'Loonjournaalposten',
						},
					],
				},
			]
		},
	},

	methods: { t },
}
</script>
