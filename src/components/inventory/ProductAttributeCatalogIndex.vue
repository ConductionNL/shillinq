<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Inventory > Product Attributes — the read-only attribute-definition catalog
 (#860, REQ-IPC-003 / REQ-IPC-004).

 Same ownership story as ProductCatalogIndex.vue: the `ProductAttribute`
 register was deleted from shillinq by `shillinq-product-vendor-to-pipelinq`
 REQ-SPVP-004, and attribute definitions now belong to the product master.

 This page answers the question an operator actually has in front of a
 federated master — "which product attributes does this installation carry,
 and who owns each one?" — from
 `GET /apps/shillinq/api/inventory/product-attributes`. When the master is
 reachable the rows are the attribute names its products really declare;
 when it is not, they are the field set the ADR-019 `getProduct` resolver
 publishes plus the fields shillinq holds locally, each row stating its
 owner. It is therefore never an empty table pretending to be a feature.

 Read-only: no add button, no write route. REQ-SPVP-004 forbids a shillinq
 surface that accepts a product definition, and an attribute definition is
 part of one.

 Registered in src/registry.js as a kind:"page" custom component.

 @visual exclude same reason as ProductCatalogIndex.vue — a stock CnDataTable
 whose rows and whose provenance banner both depend on whether a pipelinq
 register exists on the instance, so a committed pixel baseline would encode
 the environment rather than the design. Behaviour is asserted by
 tests/e2e/spec-coverage/inventory.spec.ts and both branches by
 tests/Unit/Service/ProductCatalogServiceTest.php.

 @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-004
-->
<template>
	<div class="product-attributes" data-testid="product-attributes">
		<header class="product-attributes__header">
			<h2 data-testid="product-attributes-title">
				{{ t('shillinq', 'Product Attributes') }}
			</h2>
			<p class="product-attributes__hint">
				{{
					t(
						'shillinq',
						'Attribute definitions the product catalog exposes, and which application owns each one.',
					)
				}}
			</p>
		</header>

		<div
			class="product-attributes__provenance"
			:class="`product-attributes__provenance--${source}`"
			data-testid="product-attributes-provenance">
			<strong>{{ provenanceTitle }}</strong>
			<span>{{ provenanceDetail }}</span>
		</div>

		<div
			v-if="error"
			class="product-attributes__error"
			data-testid="product-attributes-error">
			{{ error }}
		</div>

		<CnDataTable
			class="product-attributes__table"
			data-testid="product-attributes-table"
			:columns="columns"
			:rows="attributes"
			:loading="loading"
			:emptyText="t('shillinq', 'No attribute definitions are available.')">
			<!-- `#column-<key>` — see the note in ProductCatalogIndex.vue: the
			     `cell-*` spelling is not a CnDataTable slot and renders nowhere. -->
			<template #column-isRequired="{ row }">
				{{ row.isRequired ? t('shillinq', 'Yes') : t('shillinq', 'No') }}
			</template>
			<template #column-validationRule="{ row }">
				{{ row.validationRule || '—' }}
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ProductAttributeCatalogIndex',
	components: {
		CnDataTable,
	},

	data() {
		return {
			/** @type {Array<object>} Attribute definitions as served. */
			attributes: [],
			/** @type {string} `pipelinq` | `contract` — which side answered. */
			source: 'contract',
			/** @type {boolean} True only when the master supplied the names. */
			authoritative: false,
			/** @type {boolean} True while the request is in flight. */
			loading: true,
			/** @type {string} A human-readable failure, empty when none. */
			error: '',
		}
	},

	computed: {
		/**
		 * Table column definitions (REQ-IPC-004 field set, plus the owner).
		 *
		 * @return {Array<object>} The columns.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-004
		 */
		columns() {
			return [
				{ key: 'name', label: this.t('shillinq', 'Name'), sortable: true },
				{
					key: 'dataType',
					label: this.t('shillinq', 'Data Type'),
					sortable: true,
				},
				{
					key: 'applicableToCategories',
					label: this.t('shillinq', 'Applies To'),
					sortable: true,
				},
				{
					key: 'isRequired',
					label: this.t('shillinq', 'Required'),
					sortable: true,
				},
				{
					key: 'displayOrder',
					label: this.t('shillinq', 'Order'),
					sortable: true,
				},
				{
					key: 'ownedBy',
					label: this.t('shillinq', 'Owned By'),
					sortable: true,
				},
				{
					key: 'validationRule',
					label: this.t('shillinq', 'Notes'),
					sortable: false,
				},
			]
		},

		/**
		 * Headline of the provenance banner.
		 *
		 * @return {string} The heading.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-004
		 */
		provenanceTitle() {
			return this.authoritative
				? this.t(
						'shillinq',
						'Attribute definitions: from the product master',
					)
				: this.t(
						'shillinq',
						'Attribute definitions: from the integration contract',
					)
		},

		/**
		 * Body of the provenance banner.
		 *
		 * @return {string} The explanation.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-004
		 */
		provenanceDetail() {
			if (this.authoritative) {
				return this.t(
					'shillinq',
					'Rows below are the attribute names the product master’s own products declare.',
				)
			}

			return this.t(
				'shillinq',
				'The product master is unavailable, so the rows below are the attribute surface the integration contract publishes. The “Owned By” column says which application authors each value.',
			)
		},
	},

	/**
	 * Load the attribute definitions once the page mounts.
	 *
	 * @return {void}
	 */
	mounted() {
		this.loadAttributes()
	},

	methods: {
		/**
		 * Fetch the attribute envelope and unpack it.
		 *
		 * @return {Promise<void>} Resolves once state is set.
		 * @spec openspec/specs/inventory-product-catalog/spec.md#req-ipc-004
		 */
		async loadAttributes() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/inventory/product-attributes'),
				)
				const payload = response.data || {}
				this.attributes = Array.isArray(payload.attributes)
					? payload.attributes
					: []
				this.source = payload.source || 'contract'
				this.authoritative = payload.authoritative === true
			} catch (e) {
				this.attributes = []
				// See ProductCatalogIndex.vue: 403 is an answer, not a failure.
				if (e?.response?.status === 403) {
					this.error = this.t(
						'shillinq',
						'You have no administration yet, so there is no inventory to show. Ask an administrator for access.',
					)
					return
				}

				this.error = this.t(
					'shillinq',
					'Could not load the product attribute definitions.',
				)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.product-attributes {
	padding: 12px;
}

.product-attributes__hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 12px;
}

.product-attributes__provenance {
	border-inline-start: 4px solid var(--color-border);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 8px 12px;
	margin-bottom: 12px;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.product-attributes__provenance--pipelinq {
	border-inline-start-color: var(--color-success);
}

.product-attributes__provenance--contract {
	border-inline-start-color: var(--color-warning);
}

.product-attributes__error {
	color: var(--color-error);
	margin-bottom: 12px;
}
</style>
