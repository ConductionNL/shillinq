<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Inventory > Products — the read-only product catalog (#860).

 WHY THIS IS A CUSTOM PAGE AND NOT `type: "index"`
 -------------------------------------------------
 A declarative `type: "index"` page binds to exactly one OpenRegister
 register + schema. The product master this page shows does NOT live in
 shillinq: `shillinq-product-vendor-to-pipelinq` REQ-SPVP-004 deleted the
 local `Product` register and moved ownership to pipelinq, and every
 inventory schema now carries a `productId` FK resolved through the ADR-019
 integration registry.

 Binding an index page straight to `register: "pipelinq", schema: "product"`
 would render, 404 under the hood on any install without pipelinq, show an
 empty table and satisfy every "the page mounted" assertion while being
 incapable of listing a row. This page instead calls
 `GET /apps/shillinq/api/inventory/products`, which resolves the master
 first and falls back to the local denormalised cache the integration
 contract already declares — and, crucially, RENDERS WHICH OF THE TWO it
 got. A cache that cannot be told apart from the master is worse than no
 cache.

 Read-only by construction: no add button, no row form, no write route
 exists. REQ-SPVP-004's second scenario is that no shillinq surface may
 accept a product definition; the link out to the master is the affordance
 for authoring one.

 Registered in src/registry.js as a kind:"page" custom component so the
 manifest router can dispatch `component: "ProductCatalogIndex"`.

 @visual exclude the page is a stock CnDataTable whose every pixel below the
 header is data — and which of two provenance states it renders depends on
 whether a pipelinq register exists on the instance, which differs between a
 developer rig, CI and production. A committed baseline would therefore encode
 the environment rather than the design, and would go red on a fixture change
 that broke nothing. The behaviour that matters (route resolves, index surface
 renders, no shillinq 5xx, no uncaught page error) is asserted by
 tests/e2e/spec-coverage/inventory.spec.ts, and both provenance branches are
 asserted in tests/Unit/Service/ProductCatalogServiceTest.php.

 @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
-->
<template>
	<div class="product-catalog" data-testid="product-catalog">
		<header class="product-catalog__header">
			<h2 data-testid="product-catalog-title">
				{{ t('shillinq', 'Products') }}
			</h2>
			<p class="product-catalog__hint">
				{{
					t(
						'shillinq',
						'Products this administration holds inventory or barcodes for. Product definitions are owned by the product master; shillinq owns unit cost, quantities and valuation.',
					)
				}}
			</p>
		</header>

		<div
			class="product-catalog__provenance"
			:class="`product-catalog__provenance--${source}`"
			data-testid="product-catalog-provenance">
			<strong>{{ provenanceTitle }}</strong>
			<span>{{ provenanceDetail }}</span>
		</div>

		<div
			v-if="error"
			class="product-catalog__error"
			data-testid="product-catalog-error">
			{{ error }}
		</div>

		<CnDataTable
			class="product-catalog__table"
			data-testid="product-catalog-table"
			:columns="columns"
			:rows="products"
			:loading="loading"
			:emptyText="emptyLabel">
			<!--
				⚠️ THREE NAMES ON THIS COMPONENT ARE EASY TO GET WRONG AND FAIL
				SILENTLY. CnDataTable 2.3.0 declares the props `loading` and
				`emptyText`, and the per-column slot `#column-{key}` (scoped with
				`{ row, value }`). `isLoading`, `emptyLabel` and `#cell-{key}` are
				NOT part of its API: Vue falls the two attributes through onto the
				root DOM element and drops the unmatched slot content, so all three
				render nowhere while the table looks healthy. Measured on the rig:
				with `#cell-unitPrice` the price column printed the raw `1899`
				instead of `1899.00 EUR`, and with `:emptyLabel` the empty state
				printed CnDataTable's untranslated default `No items found`.
				⚠️ Six other files in this repo use the `#cell-*` spelling on
				CnDataTable (three-way-match, vendor-performance, reporting,
				bookkeeping DocumentsView/TransactionsView, invoice AdminInvoiceList)
				— ~30 overrides that are inert today. Reported, not fixed here:
				each one changes what those pages render and needs its own check.
			-->
			<template #column-productId="{ row }">
				<code class="product-catalog__id">{{ row.productId }}</code>
			</template>
			<template #column-name="{ row }">
				{{ row.name || unknownLabel }}
			</template>
			<template #column-category="{ row }">
				{{ row.category || unknownLabel }}
			</template>
			<template #column-unitPrice="{ row }">
				{{ money(row.unitPrice, row.currency) }}
			</template>
			<template #column-unitCost="{ row }">
				{{ money(row.unitCost, 'EUR') }}
			</template>
			<template #column-quantityOnHand="{ row }">
				{{ quantity(row.quantityOnHand) }}
			</template>
			<template #column-status="{ row }">
				{{ row.status || unknownLabel }}
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ProductCatalogIndex',
	components: {
		CnDataTable,
	},

	data() {
		return {
			/** @type {Array<object>} Catalog rows as served by the endpoint. */
			products: [],
			/** @type {string} `pipelinq` | `local-cache` — which side answered. */
			source: 'local-cache',
			/** @type {boolean} True only when the rows came from the owning app. */
			authoritative: false,
			/** @type {boolean} True while the request is in flight. */
			loading: true,
			/** @type {string} A human-readable failure, empty when none. */
			error: '',
		}
	},

	computed: {
		/**
		 * Table column definitions.
		 *
		 * @return {Array<object>} The columns.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
		 */
		columns() {
			return [
				{
					key: 'productId',
					label: this.t('shillinq', 'Product ID'),
					sortable: true,
				},
				{ key: 'sku', label: this.t('shillinq', 'SKU'), sortable: true },
				{ key: 'name', label: this.t('shillinq', 'Name'), sortable: true },
				{
					key: 'category',
					label: this.t('shillinq', 'Category'),
					sortable: true,
				},
				{
					key: 'unitPrice',
					label: this.t('shillinq', 'Unit Price'),
					sortable: true,
				},
				{
					key: 'unitCost',
					label: this.t('shillinq', 'Unit Cost'),
					sortable: true,
				},
				{
					key: 'quantityOnHand',
					label: this.t('shillinq', 'On Hand'),
					sortable: true,
				},
				{
					key: 'status',
					label: this.t('shillinq', 'Status'),
					sortable: true,
				},
			]
		},

		/**
		 * Headline of the provenance banner.
		 *
		 * @return {string} The heading.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
		 */
		provenanceTitle() {
			return this.authoritative
				? this.t('shillinq', 'Product master: connected')
				: this.t('shillinq', 'Product master: not connected')
		},

		/**
		 * Body of the provenance banner. States exactly what the rows are.
		 *
		 * @return {string} The explanation.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
		 */
		provenanceDetail() {
			if (this.authoritative) {
				return this.t(
					'shillinq',
					'Rows below are the authoritative product definitions resolved from the product master.',
				)
			}

			return this.t(
				'shillinq',
				'The product master is unavailable, so the rows below are shillinq’s local cache: the products its own stock and barcode records reference. Names, categories and prices are owned elsewhere and are shown blank rather than guessed.',
			)
		},

		/**
		 * Placeholder for a field shillinq does not own and cannot resolve.
		 *
		 * @return {string} The placeholder.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
		 */
		unknownLabel() {
			// Not translated: an em dash is the same glyph in every locale, and
			// registering it as a translation key would put a bare punctuation
			// mark in front of 36 translator teams.
			return '—'
		},

		/**
		 * Empty-state text. Differs by source: "no products" and "no product
		 * master and no local references" are different facts.
		 *
		 * @return {string} The empty label.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
		 */
		emptyLabel() {
			return this.authoritative
				? this.t('shillinq', 'The product master holds no products yet.')
				: this.t(
						'shillinq',
						'No products are referenced by this administration’s stock or barcode records yet.',
					)
		},
	},

	/**
	 * Load the catalog once the page mounts.
	 *
	 * @return {void}
	 */
	mounted() {
		this.loadCatalog()
	},

	methods: {
		/**
		 * Fetch the catalog envelope and unpack it.
		 *
		 * @return {Promise<void>} Resolves once state is set.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-008
		 */
		async loadCatalog() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/inventory/products'),
				)
				const payload = response.data || {}
				this.products = Array.isArray(payload.products)
					? payload.products
					: []
				this.source = payload.source || 'local-cache'
				this.authoritative = payload.authoritative === true
			} catch (e) {
				this.products = []
				// 403 is not a failure, it is an answer: the caller holds no
				// AdministrationMembership. Reporting it as "could not load"
				// would send an operator hunting a broken endpoint instead of
				// asking for access.
				if (e?.response?.status === 403) {
					this.error = this.t(
						'shillinq',
						'You have no administration yet, so there is no inventory to show. Ask an administrator for access.',
					)
					return
				}

				this.error = this.t(
					'shillinq',
					'Could not load the product catalog.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format a monetary amount, or the unknown placeholder.
		 *
		 * @param {number|null} value    The amount.
		 * @param {string|null} currency The ISO 4217 code.
		 * @return {string} The formatted amount.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-002
		 */
		money(value, currency) {
			if (
				value === null
				|| value === undefined
				|| Number.isNaN(Number(value))
			) {
				return this.unknownLabel
			}

			return `${Number(value).toFixed(2)} ${currency || 'EUR'}`
		},

		/**
		 * Format a quantity, or the unknown placeholder.
		 *
		 * @param {number|null} value The quantity.
		 * @return {string} The formatted quantity.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-002
		 */
		quantity(value) {
			if (
				value === null
				|| value === undefined
				|| Number.isNaN(Number(value))
			) {
				return this.unknownLabel
			}

			return Number(value).toFixed(2)
		},
	},
}
</script>

<style scoped>
.product-catalog {
	padding: 12px;
}

.product-catalog__hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 12px;
}

.product-catalog__provenance {
	border-inline-start: 4px solid var(--color-border);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	margin-bottom: 12px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.product-catalog__provenance--pipelinq {
	border-inline-start-color: var(--color-success);
}

.product-catalog__provenance--local-cache {
	border-inline-start-color: var(--color-warning);
}

.product-catalog__error {
	color: var(--color-error);
	margin-bottom: 12px;
}

.product-catalog__id {
	font-family: var(--font-face-monospace, monospace);
}
</style>
