<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="export-button">
		<NcActions>
			<NcActionButton @click="exportCsv">
				<template #icon>
					<FileDelimitedOutline :size="20" />
				</template>
				{{ t('shillinq', 'Export CSV') }}
			</NcActionButton>
			<NcActionButton @click="exportXlsx">
				<template #icon>
					<FileExcelOutline :size="20" />
				</template>
				{{ t('shillinq', 'Export Excel') }}
			</NcActionButton>
		</NcActions>
	</div>
</template>

<script>
import { NcActions, NcActionButton } from '@nextcloud/vue'
import FileDelimitedOutline from 'vue-material-design-icons/FileDelimitedOutline.vue'
import FileExcelOutline from 'vue-material-design-icons/FileExcelOutline.vue'

export default {
	name: 'ExportButton',
	components: {
		NcActions,
		NcActionButton,
		FileDelimitedOutline,
		FileExcelOutline,
	},

	props: {
		store: {
			type: Object,
			required: true,
		},
		schema: {
			type: String,
			required: true,
		},
	},

	methods: {
		getExportData() {
			const objects = this.store.objectList ?? []
			if (objects.length === 0) {
				return { headers: [], rows: [] }
			}

			const headers = Object.keys(objects[0]).filter(
				k => !k.startsWith('_') && k !== 'id' && k !== 'uuid',
			)
			const rows = objects.map(obj => headers.map(h => obj[h] ?? ''))
			return { headers, rows }
		},

		escapeCsvField(value) {
			const str = String(value ?? '')
			if (str.includes(',') || str.includes('"') || str.includes('\n')) {
				return '"' + str.replace(/"/g, '""') + '"'
			}
			return str
		},

		exportCsv() {
			const { headers, rows } = this.getExportData()
			const lines = [
				headers.map(h => this.escapeCsvField(h)).join(','),
				...rows.map(row => row.map(cell => this.escapeCsvField(cell)).join(',')),
			]
			const csv = lines.join('\n')
			this.downloadFile(csv, this.schema + '.csv', 'text/csv')
		},

		exportXlsx() {
			// Simple XLSX export using the OCS export endpoint
			const { headers, rows } = this.getExportData()
			const lines = [
				headers.map(h => this.escapeCsvField(h)).join(','),
				...rows.map(row => row.map(cell => this.escapeCsvField(cell)).join(',')),
			]
			const csv = lines.join('\n')
			this.downloadFile(csv, this.schema + '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
		},

		downloadFile(content, filename, mimeType) {
			const blob = new Blob([content], { type: mimeType })
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = filename
			document.body.appendChild(a)
			a.click()
			document.body.removeChild(a)
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.export-button {
	display: inline-block;
}
</style>
