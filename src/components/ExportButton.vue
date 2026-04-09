<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-8.4
-->
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
import { useObjectStore } from '@conduction/nextcloud-vue'
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
		objectType: {
			type: String,
			required: true,
		},
	},
	methods: {
		getObjects() {
			const store = useObjectStore()
			return store.collections[this.objectType] || []
		},
		exportCsv() {
			const objects = this.getObjects()
			if (objects.length === 0) return

			const keys = Object.keys(objects[0]).filter(k => !k.startsWith('_'))
			const header = keys.join(',')
			const rows = objects.map(obj =>
				keys.map(k => {
					const val = String(obj[k] ?? '')
					return val.includes(',') || val.includes('"') || val.includes('\n')
						? '"' + val.replace(/"/g, '""') + '"'
						: val
				}).join(','),
			)

			const csv = [header, ...rows].join('\n')
			this.download(csv, `${this.objectType}-export.csv`, 'text/csv')
		},
		exportXlsx() {
			// Simple XML-based XLSX using SpreadsheetML (single sheet)
			const objects = this.getObjects()
			if (objects.length === 0) return

			const keys = Object.keys(objects[0]).filter(k => !k.startsWith('_'))

			let sheetData = '<Row>'
			keys.forEach(k => {
				sheetData += `<Cell><Data ss:Type="String">${this.escapeXml(k)}</Data></Cell>`
			})
			sheetData += '</Row>'

			objects.forEach(obj => {
				sheetData += '<Row>'
				keys.forEach(k => {
					const val = String(obj[k] ?? '')
					sheetData += `<Cell><Data ss:Type="String">${this.escapeXml(val)}</Data></Cell>`
				})
				sheetData += '</Row>'
			})

			const xml = `<?xml version="1.0"?>
<?mso-application progid="Excel.Sheet"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">
<Worksheet ss:Name="Export">
<Table>${sheetData}</Table>
</Worksheet>
</Workbook>`

			this.download(xml, `${this.objectType}-export.xls`, 'application/vnd.ms-excel')
		},
		escapeXml(str) {
			return str
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
		},
		download(content, filename, mimeType) {
			const blob = new Blob([content], { type: mimeType })
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = filename
			document.body.appendChild(link)
			link.click()
			document.body.removeChild(link)
			URL.revokeObjectURL(url)
		},
	},
}
</script>
