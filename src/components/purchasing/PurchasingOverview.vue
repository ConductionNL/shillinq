<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Purchasing cluster landing page (nav-six-clusters, design.md §2 Cluster 3).

 Thin wrapper around the shared ClusterOverview card grid. Absorbs
 AccountsPayableT2, PO Matching (renamed from the id-colliding PurchaseOrders
 top-level group, design.md §5), the Commitments group and
 Contracts (relabelled "Procurement Contracts" to disambiguate from
 RevenueContracts, design.md §4 row 25) on top of the original Purchasing &
 Inventory group.

 @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
-->
<template>
	<ClusterOverview
		clusterId="purchasing"
		:title="t('shillinq', 'Purchasing')"
		:hint="
			t(
				'shillinq',
				'Purchase orders, goods receipts, supplier invoices, inventory, commitments and procurement contracts.',
			)
		"
		:sections="sections" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ClusterOverview from '../cluster-overview/ClusterOverview.vue'

export default {
	name: 'PurchasingOverview',
	components: { ClusterOverview },
	computed: {
		/**
		 * Card-section data for this cluster's landing page — a plain, static
		 * grouping of the Purchasing cluster's absorbed children.
		 *
		 * @return {Array} The section descriptors passed to ClusterOverview.
		 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
		 */
		sections() {
			return [
				{
					id: 'orders',
					label: this.t('shillinq', 'Purchase Orders & Matching'),
					cards: [
						{
							id: 'PurchaseOrders',
							label: this.t('shillinq', 'Purchase Orders'),
							route: 'PurchaseOrders',
						},
						{
							id: 'GoodsReceipts',
							label: this.t('shillinq', 'Goods Receipts'),
							route: 'GoodsReceipts',
						},
						{
							id: 'ThreeWayMatches',
							label: this.t('shillinq', '3-way Matches'),
							route: 'ThreeWayMatches',
						},
						{
							id: 'ThreeWayMatchExceptions',
							label: this.t('shillinq', 'Match Exceptions'),
							route: 'ThreeWayMatches',
						},
						{
							id: 'VendorPerformanceIndex',
							label: this.t('shillinq', 'Vendor performance'),
							route: 'VendorPerformanceIndex',
						},
					],
				},
				{
					id: 'payable',
					label: this.t('shillinq', 'Accounts Payable'),
					cards: [
						{
							id: 'SupplierInvoices',
							label: this.t('shillinq', 'Supplier Invoices'),
							route: 'SupplierInvoices',
						},
						{
							id: 'APAgingT2',
							label: this.t('shillinq', 'AP Aging'),
							route: 'APAgingT2',
						},
						{
							id: 'PaymentRuns',
							label: this.t('shillinq', 'Payment Runs'),
							route: 'PaymentRuns',
						},
						{
							id: 'Receipts',
							label: this.t('shillinq', 'Receipts'),
							route: 'Receipts',
						},
						{
							id: 'ExpenseClaims',
							label: this.t('shillinq', 'Expense Claims'),
							route: 'ExpenseClaims',
						},
					],
				},
				{
					id: 'inventory',
					label: this.t('shillinq', 'Inventory'),
					cards: [
						{
							id: 'StockLevels',
							label: this.t('shillinq', 'Stock Levels'),
							route: 'StockLevels',
						},
						{
							id: 'StockMovements',
							label: this.t('shillinq', 'Stock Movements'),
							route: 'StockMovements',
						},
						{
							id: 'InventoryValuation',
							label: this.t('shillinq', 'Inventory Valuation'),
							route: 'InventoryValuation',
						},
					],
				},
				{
					id: 'commitments',
					label: this.t('shillinq', 'Commitments & Contracts'),
					cards: [
						{
							id: 'CommitmentsRegister',
							label: this.t('shillinq', 'Commitments register'),
							route: 'CommitmentsRegister',
						},
						{
							id: 'MijnContracten',
							label: this.t(
								'shillinq',
								'TenderNed-sourced commitments',
							),

							route: 'CommitmentsRegister',
							query: { source: 'tenderned' },
						},
						{
							id: 'Contracts',
							label: this.t('shillinq', 'Procurement Contracts'),
							route: 'Contracts',
						},
						{
							id: 'ContractObligations',
							label: this.t('shillinq', 'Contract Obligations'),
							route: 'ContractObligations',
						},
					],
				},
			]
		},
	},

	methods: { t },
}
</script>
