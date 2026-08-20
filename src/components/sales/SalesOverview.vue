<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Sales cluster landing page (nav-six-clusters, design.md §2 Cluster 2).

 Thin wrapper around the shared ClusterOverview card grid. Absorbs Orders,
 RecurringInvoicing and PaymentRequests (each a standalone top-level leaf
 before this change) plus ARAging (relocated out of Bookkeeping, design.md
 §4 row 12) alongside the original Sales group.

 @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
-->
<template>
	<ClusterOverview
		clusterId="sales"
		:title="t('shillinq', 'Sales')"
		:hint="
			t(
				'shillinq',
				'Customers, bookings, invoicing, retainers, accounts receivable and orders.',
			)
		"
		:sections="sections" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ClusterOverview from '../cluster-overview/ClusterOverview.vue'

export default {
	name: 'SalesOverview',
	components: { ClusterOverview },
	computed: {
		/**
		 * Card-section data for this cluster's landing page — a plain, static
		 * grouping of the Sales cluster's absorbed children.
		 *
		 * @return {Array} The section descriptors passed to ClusterOverview.
		 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
		 */
		sections() {
			return [
				{
					id: 'customers',
					label: this.t('shillinq', 'Customers & Bookings'),
					cards: [
						{
							id: 'Customers',
							label: this.t('shillinq', 'Customers'),
							route: 'Customers',
						},
						{
							id: 'Bookings',
							label: this.t('shillinq', 'Bookings'),
							route: 'Bookings',
						},
						{
							id: 'Deposits',
							label: this.t('shillinq', 'Deposits'),
							route: 'Deposits',
						},
					],
				},
				{
					id: 'invoicing',
					label: this.t('shillinq', 'Invoicing'),
					cards: [
						{
							id: 'BillableInvoices',
							label: this.t('shillinq', 'Billable Invoices'),
							route: 'BillableInvoices',
						},
						{
							id: 'RecurringInvoicing',
							label: this.t('shillinq', 'Recurring Invoices'),
							route: 'RecurringInvoiceProfiles',
						},
						{
							id: 'PaymentRequests',
							label: this.t('shillinq', 'Payment requests'),
							route: 'PaymentRequests',
						},
						{
							id: 'Orders',
							label: this.t('shillinq', 'Orders'),
							route: 'Orders',
						},
					],
				},
				{
					id: 'retainers',
					label: this.t('shillinq', 'Retainers'),
					cards: [
						{
							id: 'RetainerPools',
							label: this.t('shillinq', 'Retainer Pools'),
							route: 'RetainerPools',
						},
						{
							id: 'RetainerDrawdowns',
							label: this.t('shillinq', 'Retainer Drawdowns'),
							route: 'RetainerDrawdowns',
						},
						{
							id: 'RateCards',
							label: this.t('shillinq', 'Rate Cards'),
							route: 'RateCards',
						},
						{
							id: 'RateSchedules',
							label: this.t('shillinq', 'Rate Schedules'),
							route: 'RateSchedules',
						},
					],
				},
				{
					id: 'receivable',
					label: this.t('shillinq', 'Accounts Receivable'),
					cards: [
						{
							id: 'AccountsReceivable',
							label: this.t('shillinq', 'Accounts Receivable'),
							route: 'AccountsReceivable',
						},
						{
							id: 'ARAging',
							label: this.t('shillinq', 'AR Aging'),
							route: 'ARAging',
						},
					],
				},
			]
		},
	},

	methods: { t },
}
</script>
