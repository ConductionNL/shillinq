<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Taxes cluster landing page (nav-six-clusters, design.md §2 Cluster 5).
 Thin wrapper around the shared ClusterOverview card grid over the renamed
 Belastingen -> Taxes top-level group (REQ-NAVC-001 scenario 2 requires the
 old id not survive). Also absorbs RDSubsidies (KEEP + RELOCATE, design.md
 §4 row 1 — a genuinely separate WBSO/R&D capability, not a role lens of the
 generic Subsidies list) and the two IA-gap orphans WBSOActivityCodes /
 InnovatieboxElections (design.md §8) placed here alongside it.

 @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
-->
<template>
	<ClusterOverview
		clusterId="taxes"
		:title="t('shillinq', 'Taxes')"
		:hint="
			t(
				'shillinq',
				'VAT/BTW filing, KOR, income tax, deferred tax, WBSO and R&D grants.',
			)
		"
		:sections="sections" />
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import ClusterOverview from '../cluster-overview/ClusterOverview.vue'

export default {
	name: 'TaxesOverview',
	components: { ClusterOverview },
	computed: {
		/**
		 * Card-section data for this cluster's landing page — a plain, static
		 * grouping of the Taxes cluster's absorbed children.
		 *
		 * @return {Array} The section descriptors passed to ClusterOverview.
		 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
		 */
		sections() {
			return [
				{
					id: 'vat',
					label: this.t('shillinq', 'VAT / BTW'),
					cards: [
						{
							id: 'VATReturns',
							label: this.t('shillinq', 'BTW returns'),
							route: 'VATReturns',
						},
						{
							id: 'BtwAangiften',
							label: this.t('shillinq', 'BTW returns overview'),
							route: 'BtwAangiften',
						},
						{
							id: 'OssReturns',
							label: this.t('shillinq', 'OSS returns'),
							route: 'OssReturns',
						},
					],
				},
				{
					id: 'kor',
					label: this.t('shillinq', 'KOR (Small Business Scheme)'),
					cards: [
						{
							id: 'KorDashboard',
							label: this.t('shillinq', 'KOR dashboard'),
							route: 'KorDashboard',
						},
						{
							id: 'KorAanmelding',
							label: this.t('shillinq', 'KOR registration'),
							route: 'KorDashboard',
							query: { status: 'draft' },
						},
						{
							id: 'KorOpzegging',
							label: this.t('shillinq', 'KOR cancellation'),
							route: 'KorDashboard',
							query: { status: 'ACTIEF' },
						},
					],
				},
				{
					id: 'income-tax',
					label: this.t('shillinq', 'Income Tax'),
					cards: [
						{
							id: 'IbAangifte',
							label: this.t('shillinq', 'IB return'),
							route: 'IbAangifte',
						},
						{
							id: 'Vpb',
							label: this.t('shillinq', 'Corporate tax (Vpb)'),
							route: 'Vpb',
						},
						{
							id: 'TaxProvisions',
							label: this.t('shillinq', 'Deferred tax'),
							route: 'TaxProvisions',
						},
					],
				},
				{
					id: 'wbso',
					label: this.t('shillinq', 'WBSO & R&D'),
					cards: [
						{
							id: 'RDSubsidies',
							label: this.t('shillinq', 'R&D grants'),
							route: 'RDSubsidies',
						},
						{
							id: 'WBSOActivityCodes',
							label: this.t('shillinq', 'WBSO Activity Codes'),
							route: 'WBSOActivityCodes',
						},
						{
							id: 'InnovatieboxElections',
							label: this.t('shillinq', 'Innovation box'),
							route: 'InnovatieboxElections',
						},
					],
				},
			]
		},
	},

	methods: { t },
}
</script>
