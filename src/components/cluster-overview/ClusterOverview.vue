<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Shared ADR-097 Decision-4 cluster landing page (nav-six-clusters).

 A category-grouped, STATIC card grid over the cluster's own children — the
 same "cards-collapse" pattern already in production as
 ReportingComplianceOverview (src/components/reporting/
 ReportingComplianceOverview.vue), simplified: that page fronts a dynamic
 server-side report catalogue (GET /api/reporting/types); a cluster landing
 page fronts a fixed set of menu children, so its card data is a plain prop
 declared once by each cluster's own thin wrapper component
 (src/components/<cluster-slug>/<Cluster>Overview.vue) rather than fetched.

 Each of the 6 top-level clusters (design.md §2) gets exactly one of these
 landing pages as its `menu[].route` target — Bookkeeping, Sales, Purchasing,
 Banking & Cashflow, Taxes get a thin wrapper around THIS component; Reporting
 & Compliance reuses the pre-existing ReportingComplianceOverview as-is
 (design.md §3 — its dynamic catalogue already covers a superset of what a
 static wrapper here would show).

 `data-testid="<cluster>-overview"` / `"<cluster>-overview-title"` root hooks
 match the ReportingComplianceOverview convention so
 tests/e2e/NavSixClusters.spec.js has stable selectors (design.md §3/§11).

 @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
-->
<template>
	<div
		:class="`cluster-overview cluster-overview--${clusterId}`"
		:data-testid="`${clusterId}-overview`">
		<header class="cluster-overview__header">
			<h2 :data-testid="`${clusterId}-overview-title`">
				{{ title }}
			</h2>
			<p v-if="hint" class="cluster-overview__hint">
				{{ hint }}
			</p>
		</header>

		<div class="cluster-overview__groups">
			<section
				v-for="section in sections"
				:key="section.id"
				class="cluster-overview__group"
				:data-testid="`${clusterId}-section-${section.id}`">
				<h3 class="cluster-overview__group-title">
					{{ section.label }}
				</h3>
				<div class="cluster-overview__cards">
					<router-link
						v-for="card in section.cards"
						:key="card.id"
						class="cluster-overview__card"
						:data-testid="`${clusterId}-card-${card.id}`"
						:to="cardTo(card)">
						<span class="cluster-overview__card-title">{{
							card.label
						}}</span>
						<span
							v-if="card.description"
							class="cluster-overview__card-desc"
							>{{ card.description }}</span
						>
					</router-link>
				</div>
			</section>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'ClusterOverview',

	props: {
		/** Kebab/PascalCase slug used to namespace every data-testid on this instance. */
		clusterId: {
			type: String,
			required: true,
		},

		/** Rendered H2 heading (already translated by the caller — see each wrapper's use of t()). */
		title: {
			type: String,
			required: true,
		},

		/** Optional one-line subheading under the title. */
		hint: {
			type: String,
			default: '',
		},

		/**
		 * Card sections: `[{ id, label, cards: [{ id, label, description?, route, query? }] }]`.
		 * `route` names a manifest page id (vue-router route name); `query` is an
		 * optional plain object merged into the router-link target — the same
		 * shape as a `menu[].query` preset (design.md §4's MERGE-row deep links).
		 */
		sections: {
			type: Array,
			required: true,
		},
	},

	methods: {
		t,
		/**
		 * Build the router-link `:to` target for a card, carrying its optional
		 * query preset through — mirrors CnAppNav's own `itemTo()` handling of
		 * `menu[].query` (@conduction/nextcloud-vue/src/components/CnAppNav/
		 * CnAppNav.vue).
		 *
		 * @param {{route: string, query?: object}} card The card descriptor.
		 * @return {{name: string, query?: object}} The router-link target.
		 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
		 */
		cardTo(card) {
			return card.query
				? { name: card.route, query: card.query }
				: { name: card.route }
		},
	},
}
</script>

<style scoped>
.cluster-overview {
	padding: 1rem;
}

.cluster-overview__header {
	margin-bottom: 1.5rem;
}

.cluster-overview__header h2 {
	margin: 0 0 0.25rem 0;
}

.cluster-overview__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
	max-width: 48rem;
}

.cluster-overview__group {
	margin-bottom: 2rem;
}

.cluster-overview__group-title {
	margin: 0 0 0.75rem 0;
	padding-bottom: 0.25rem;
	border-bottom: 1px solid var(--color-border);
}

.cluster-overview__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
	gap: 1rem;
}

.cluster-overview__card {
	display: flex;
	flex-direction: column;
	gap: 0.375rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	text-decoration: none;
}

.cluster-overview__card:hover {
	border-color: var(--color-primary-element);
	background: var(--color-background-hover);
}

.cluster-overview__card-title {
	font-weight: 600;
}

.cluster-overview__card-desc {
	color: var(--color-text-maxcontrast);
	font-size: var(--default-font-size, 0.875rem);
}
</style>
