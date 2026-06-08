<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Documents list view (REQ-WBSO-003 / REQ-WBSO-007 / REQ-WBSO-009). Shows
 the administration's bookkeeping documents in a table with filters for
 type, status, and filing date. Exposes an "Upload Document" action.

 @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-22
-->
<template>
	<NcAppContent>
		<div class="wbso-documents">
			<header class="wbso-documents__header">
				<h2>{{ t('shillinq', 'Documents') }}</h2>
				<NcButton v-if="canCreate" type="primary" @click="onUpload">
					{{ t('shillinq', 'Upload Document') }}
				</NcButton>
			</header>

			<div class="wbso-documents__filters">
				<label>
					<span>{{ t('shillinq', 'Status') }}</span>
					<select v-model="filters.status" @change="load">
						<option value="">{{ t('shillinq', 'All') }}</option>
						<option value="draft">{{ t('shillinq', 'Draft') }}</option>
						<option value="filed">{{ t('shillinq', 'Filed') }}</option>
						<option value="archived">{{ t('shillinq', 'Archived') }}</option>
					</select>
				</label>
				<label>
					<span>{{ t('shillinq', 'Type') }}</span>
					<select v-model="filters.type" @change="load">
						<option value="">{{ t('shillinq', 'All') }}</option>
						<option value="invoice">{{ t('shillinq', 'Invoice') }}</option>
						<option value="receipt">{{ t('shillinq', 'Receipt') }}</option>
						<option value="contract">{{ t('shillinq', 'Contract') }}</option>
						<option value="tax-form">{{ t('shillinq', 'Tax Form') }}</option>
						<option value="bank-statement">{{ t('shillinq', 'Bank Statement') }}</option>
						<option value="memo">{{ t('shillinq', 'Memo') }}</option>
					</select>
				</label>
				<label>
					<span>{{ t('shillinq', 'Filed from') }}</span>
					<input v-model="filters.filedFrom" type="date" @change="load">
				</label>
			</div>

			<NcEmptyContent v-if="!loading && documents.length === 0"
				:name="t('shillinq', 'No documents')"
				:description="t('shillinq', 'Upload the first document to track an invoice, receipt, or contract.')" />

			<table v-else-if="!loading" class="wbso-documents__table">
				<thead>
					<tr>
						<th>{{ t('shillinq', 'Document Number') }}</th>
						<th>{{ t('shillinq', 'Document Type') }}</th>
						<th>{{ t('shillinq', 'Document Date') }}</th>
						<th>{{ t('shillinq', 'Status') }}</th>
						<th>{{ t('shillinq', 'File Reference') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in documents" :key="row.id || row.documentNumber">
						<td>{{ row.documentNumber }}</td>
						<td>{{ translateType(row.documentType) }}</td>
						<td>{{ row.documentDate }}</td>
						<td>
							<span :data-status="row.status">{{ translateStatus(row.status) }}</span>
						</td>
						<td>
							<a v-if="row.fileReference" :href="row.fileReference" target="_blank" rel="noopener">
								{{ row.fileReference }}
							</a>
							<span v-else>—</span>
						</td>
					</tr>
				</tbody>
			</table>

			<div v-else class="wbso-documents__loading">
				{{ t('shillinq', 'Loading…') }}
			</div>

			<p v-if="errorMessage" class="wbso-documents__error" role="alert">
				{{ errorMessage }}
			</p>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton, NcEmptyContent } from '@nextcloud/vue'
import { generateOcsUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'WbsoDocumentsView',

	components: {
		NcAppContent,
		NcButton,
		NcEmptyContent,
	},

	data() {
		return {
			documents: [],
			loading: true,
			errorMessage: '',
			canCreate: false,
			filters: {
				status: '',
				type: '',
				filedFrom: '',
			},
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		async load() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateOcsUrl('apps/shillinq/api/v1/documents')
				const params = {}
				if (this.filters.status) params.status = this.filters.status
				if (this.filters.type) params.type = this.filters.type
				if (this.filters.filedFrom) params.filedFrom = this.filters.filedFrom
				const { data } = await axios.get(url, { params })
				this.documents = data?.ocs?.data?.documents ?? data?.documents ?? []
				this.canCreate = data?.ocs?.data?.canCreate ?? data?.canCreate ?? false
			} catch (error) {
				this.errorMessage = t('shillinq', 'Failed to load documents.')
			} finally {
				this.loading = false
			}
		},

		onUpload() {
			this.$emit('upload-document')
		},

		translateType(type) {
			const map = {
				invoice: t('shillinq', 'Invoice'),
				receipt: t('shillinq', 'Receipt'),
				contract: t('shillinq', 'Contract'),
				'tax-form': t('shillinq', 'Tax Form'),
				'bank-statement': t('shillinq', 'Bank Statement'),
				memo: t('shillinq', 'Memo'),
			}
			return map[type] || type
		},

		translateStatus(status) {
			const map = {
				draft: t('shillinq', 'Draft'),
				filed: t('shillinq', 'Filed'),
				archived: t('shillinq', 'Archived'),
			}
			return map[status] || status
		},
	},
}
</script>

<style scoped>
.wbso-documents {
	padding: 1rem;
}
.wbso-documents__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
}
.wbso-documents__filters {
	display: flex;
	gap: 1rem;
	flex-wrap: wrap;
	margin-bottom: 1rem;
}
.wbso-documents__filters label {
	display: flex;
	flex-direction: column;
	font-size: 0.9rem;
}
.wbso-documents__table {
	width: 100%;
	border-collapse: collapse;
}
.wbso-documents__table th,
.wbso-documents__table td {
	padding: 0.5rem;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}
[data-status="archived"] {
	color: var(--color-text-maxcontrast);
}
[data-status="filed"] {
	font-weight: bold;
}
.wbso-documents__error {
	color: var(--color-error);
}
</style>
