<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 MissedDepreciationDialog: the months of an asset that ended without their
 depreciation posted, with the amounts, and a button that posts them, one
 journal entry per month (assets-method-change-and-reserve REQ-AMCR-001).
 The daily run posts only the month that ended last, so earlier months are
 posted here, after the amounts were shown. Opened from the fixed asset
 page's header action. Its own file for hydra gate-13.

 @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
-->

<template>
	<NcDialog
		v-if="open"
		:name="t('shillinq', 'Post missed depreciation')"
		size="normal"
		data-testid="missed-depreciation-dialog"
		@closing="$emit('close')">
		<p v-if="loading">
			{{ t('shillinq', 'Loading') }}
		</p>
		<p v-else-if="error" class="missed-depreciation__error">
			{{ error }}
		</p>
		<p v-else-if="rows.length === 0">
			{{ t('shillinq', 'Every month that ended is posted.') }}
		</p>
		<table
			v-else
			class="missed-depreciation"
			data-testid="missed-depreciation-rows">
			<thead>
				<tr>
					<th>{{ t('shillinq', 'Month') }}</th>
					<th>{{ t('shillinq', 'Amount') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.period">
					<td>{{ row.period }}</td>
					<td>{{ money(row.amount) }}</td>
				</tr>
			</tbody>
			<tfoot>
				<tr>
					<th>{{ t('shillinq', 'Total') }}</th>
					<th>{{ money(total) }}</th>
				</tr>
			</tfoot>
		</table>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('shillinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="loading || posting || rows.length === 0"
				data-testid="missed-depreciation-post"
				@click="post">
				{{ t('shillinq', 'Post {count} months', { count: rows.length }) }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'MissedDepreciationDialog',
	components: {
		NcButton,
		NcDialog,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},

		assetId: {
			type: String,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			rows: [],
			total: 0,
			loading: true,
			posting: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The asset's missed depreciation endpoint.
		 *
		 * @return {string} The URL.
		 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
		 */
		url() {
			return generateUrl(
				'/apps/shillinq/api/fixed-assets/{id}/missed-depreciation',
				{ id: this.assetId },
			)
		},
	},

	async mounted() {
		try {
			const { data } = await axios.get(this.url)
			this.rows = data.rows || []
			this.total = data.total || 0
		} catch (e) {
			this.error = this.reason(
				e,
				t('shillinq', 'The missed depreciation could not be read.'),
			)
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * An amount in euros.
		 *
		 * @param {number} amount The amount.
		 * @return {string} The text.
		 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
		 */
		money(amount) {
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: 'EUR',
			}).format(amount || 0)
		},

		/**
		 * The error text an endpoint answered with, or a fallback.
		 *
		 * @param {Error} error The request error.
		 * @param {string} fallback The text when the endpoint said nothing.
		 * @return {string} The text.
		 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
		 */
		reason(error, fallback) {
			return (
				(error
					&& error.response
					&& error.response.data
					&& error.response.data.error)
				|| fallback
			)
		},

		/**
		 * Post the listed months and close.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
		 */
		async post() {
			this.posting = true
			try {
				const { data } = await axios.post(this.url)
				showSuccess(
					t('shillinq', 'Posted {count} months of depreciation.', {
						count: (data.rows || []).length,
					}),
				)
				emit('cn:page:refresh', {})
				this.$emit('close')
			} catch (e) {
				this.error = this.reason(
					e,
					t('shillinq', 'The missed depreciation could not be posted.'),
				)
			} finally {
				this.posting = false
			}
		},
	},
}
</script>

<style scoped>
.missed-depreciation {
	width: 100%;
}

.missed-depreciation td:last-child,
.missed-depreciation th:last-child {
	text-align: end;
}

.missed-depreciation__error {
	color: var(--color-error-text);
}
</style>
