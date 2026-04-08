<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/catalog-purchase-management/tasks.md#task-8.3 -->
<template>
	<div class="catalog-import-panel">
		<h3>{{ t('shillinq', 'Import Catalog Items') }}</h3>

		<p class="catalog-import-panel__description">
			{{ t('shillinq', 'Upload a CSV file to import items into this catalog.') }}
		</p>

		<div class="catalog-import-panel__actions">
			<NcButton type="secondary" @click="downloadTemplate">
				<template #icon>
					<DownloadIcon :size="20" />
				</template>
				{{ t('shillinq', 'Download template') }}
			</NcButton>
		</div>

		<div class="catalog-import-panel__upload">
			<label class="catalog-import-panel__file-label">
				<FileUploadOutlineIcon :size="20" />
				{{ selectedFile ? selectedFile.name : t('shillinq', 'Choose CSV file...') }}
				<input
					ref="fileInput"
					type="file"
					accept=".csv"
					class="catalog-import-panel__file-input"
					@change="onFileSelected">
			</label>

			<NcButton
				type="primary"
				:disabled="!selectedFile || importing"
				@click="importFile">
				<template v-if="importing" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				<template v-else #icon>
					<UploadIcon :size="20" />
				</template>
				{{ t('shillinq', 'Import') }}
			</NcButton>
		</div>

		<!-- Success message -->
		<div v-if="importedCount !== null" class="catalog-import-panel__success">
			<CheckCircleIcon :size="20" />
			{{ t('shillinq', '{count} items imported successfully.', { count: importedCount }) }}
		</div>

		<!-- Error table -->
		<div v-if="importErrors.length > 0" class="catalog-import-panel__errors">
			<h4>{{ t('shillinq', 'Import Errors') }}</h4>
			<table class="catalog-import-panel__error-table">
				<thead>
					<tr>
						<th>{{ t('shillinq', 'Row') }}</th>
						<th>{{ t('shillinq', 'SKU') }}</th>
						<th>{{ t('shillinq', 'Error') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(error, index) in importErrors" :key="index">
						<td>{{ error.row }}</td>
						<td>{{ error.sku || '-' }}</td>
						<td>{{ error.message }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import FileUploadOutlineIcon from 'vue-material-design-icons/FileUploadOutline.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'

const CSV_TEMPLATE_HEADERS = [
	'sku',
	'productName',
	'description',
	'unitPrice',
	'currency',
	'unitOfMeasure',
	'category',
	'leadTimeDays',
	'minimumOrderQuantity',
]

export default {
	name: 'CatalogImportPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		CheckCircleIcon,
		DownloadIcon,
		FileUploadOutlineIcon,
		UploadIcon,
	},

	props: {
		catalogId: {
			type: String,
			required: true,
		},
	},

	emits: ['imported'],

	data() {
		return {
			selectedFile: null,
			importing: false,
			importedCount: null,
			importErrors: [],
		}
	},

	methods: {
		t(app, text, params) {
			return t(app, text, params)
		},

		downloadTemplate() {
			const csvContent = CSV_TEMPLATE_HEADERS.join(',') + '\n'
				+ 'SKU-001,Example Product,Description,10.00,EUR,piece,General,5,1\n'
			const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = 'catalog-import-template.csv'
			link.click()
			URL.revokeObjectURL(url)
		},

		onFileSelected(event) {
			this.selectedFile = event.target.files[0] || null
			this.importedCount = null
			this.importErrors = []
		},

		async importFile() {
			if (!this.selectedFile) return

			this.importing = true
			this.importedCount = null
			this.importErrors = []

			try {
				const formData = new FormData()
				formData.append('file', this.selectedFile)

				const url = generateUrl(`/apps/shillinq/api/v1/catalogs/${this.catalogId}/import`)
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						requesttoken: OC.requestToken,
					},
					body: formData,
				})

				const data = await response.json()

				if (response.ok) {
					this.importedCount = data.importedCount || 0
					this.importErrors = data.errors || []
					this.$emit('imported')
				} else {
					this.importErrors = data.errors || [
						{ row: '-', sku: '-', message: data.message || 'Import failed' },
					]
				}
			} catch (error) {
				console.error('Import failed:', error)
				this.importErrors = [
					{ row: '-', sku: '-', message: error.message || 'Network error' },
				]
			} finally {
				this.importing = false
				this.selectedFile = null
				if (this.$refs.fileInput) {
					this.$refs.fileInput.value = ''
				}
			}
		},
	},
}
</script>

<style scoped>
.catalog-import-panel {
	padding: 8px 0;
}

.catalog-import-panel h3 {
	margin: 0 0 8px;
	font-size: 18px;
	font-weight: 600;
}

.catalog-import-panel__description {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
}

.catalog-import-panel__actions {
	margin-bottom: 16px;
}

.catalog-import-panel__upload {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.catalog-import-panel__file-label {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 8px 12px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.catalog-import-panel__file-label:hover {
	border-color: var(--color-primary);
	color: var(--color-primary);
}

.catalog-import-panel__file-input {
	display: none;
}

.catalog-import-panel__success {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	background-color: var(--color-success-hover);
	border-radius: var(--border-radius-large);
	color: var(--color-success-text);
	margin-bottom: 12px;
}

.catalog-import-panel__errors {
	margin-top: 12px;
}

.catalog-import-panel__errors h4 {
	margin: 0 0 8px;
	color: var(--color-error);
}

.catalog-import-panel__error-table {
	width: 100%;
	border-collapse: collapse;
}

.catalog-import-panel__error-table th,
.catalog-import-panel__error-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.catalog-import-panel__error-table tr:nth-child(even) {
	background-color: var(--color-background-dark);
}
</style>
