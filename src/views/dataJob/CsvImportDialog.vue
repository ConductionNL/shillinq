<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

@spec openspec/changes/core/tasks.md#task-7.3
-->
<template>
	<NcDialog
		:name="t('shillinq', 'Import CSV')"
		size="large"
		@closing="$emit('close')">
		<div class="csv-import">
			<div class="csv-import__steps">
				<span v-for="(label, index) in stepLabels"
					:key="index"
					:class="{ 'csv-import__step--active': step === index, 'csv-import__step--done': step > index }"
					class="csv-import__step">
					{{ index + 1 }}. {{ label }}
				</span>
			</div>

			<!-- Step 0: Upload -->
			<div v-if="step === 0" class="csv-import__upload">
				<p>{{ t('shillinq', 'Select a CSV file to import organizations.') }}</p>
				<input
					ref="fileInput"
					type="file"
					accept=".csv,text/csv"
					@change="onFileSelected">
				<p v-if="fileError" class="csv-import__error">{{ fileError }}</p>
			</div>

			<!-- Step 1: Map Columns -->
			<div v-if="step === 1" class="csv-import__mapping">
				<p>{{ t('shillinq', 'Map CSV columns to schema fields.') }}</p>
				<div v-for="header in csvHeaders"
					:key="header"
					class="csv-import__map-row">
					<label>{{ header }}</label>
					<select v-model="mapping[header]">
						<option value="">{{ t('shillinq', '— skip —') }}</option>
						<option v-for="field in schemaFields"
							:key="field"
							:value="field">
							{{ field }}
						</option>
					</select>
				</div>
			</div>

			<!-- Step 2: Preview -->
			<div v-if="step === 2" class="csv-import__preview">
				<p>{{ t('shillinq', 'Preview of the first 5 rows:') }}</p>
				<table class="csv-import__table">
					<thead>
						<tr>
							<th v-for="field in mappedFields" :key="field">{{ field }}</th>
							<th>{{ t('shillinq', 'Valid') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, i) in previewRows"
							:key="i"
							:class="{ 'csv-import__row--error': !row._valid }">
							<td v-for="field in mappedFields" :key="field">
								{{ row[field] || '' }}
							</td>
							<td>
								<span v-if="row._valid" style="color: var(--color-success);">✓</span>
								<span v-else style="color: var(--color-error);">{{ row._error }}</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Step 3: Confirm -->
			<div v-if="step === 3" class="csv-import__confirm">
				<p>
					{{ t('shillinq', 'Ready to import {count} rows.', { count: csvRows.length }) }}
				</p>
				<template v-if="importing">
					<p class="csv-import__importing">
						{{ t('shillinq', 'Importing... {progress} of {total}', { progress: importProgress, total: csvRows.length }) }}
					</p>
					<progress class="csv-import__progress"
						:value="importProgress"
						:max="csvRows.length" />
				</template>
			</div>

			<div class="csv-import__footer">
				<NcButton v-if="step > 0" @click="step--">
					{{ t('shillinq', 'Back') }}
				</NcButton>
				<NcButton v-if="step < 3"
					type="primary"
					:disabled="!canProceed"
					@click="step++">
					{{ t('shillinq', 'Next') }}
				</NcButton>
				<NcButton v-if="step === 3"
					type="primary"
					:disabled="importing"
					@click="startImport">
					{{ t('shillinq', 'Start Import') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { useDataJobStore } from '../../store/modules/dataJob.js'
import { useOrganizationStore } from '../../store/modules/organization.js'

export default {
	name: 'CsvImportDialog',
	components: {
		NcButton,
		NcDialog,
	},
	data() {
		return {
			step: 0,
			file: null,
			fileError: '',
			csvHeaders: [],
			csvRows: [],
			mapping: {},
			importing: false,
			importProgress: 0,
			schemaFields: ['name', 'registrationNumber', 'email', 'phone', 'website', 'address', 'city', 'country'],
			stepLabels: [
				t('shillinq', 'Upload'),
				t('shillinq', 'Map Columns'),
				t('shillinq', 'Preview'),
				t('shillinq', 'Confirm'),
			],
		}
	},
	computed: {
		mappedFields() {
			return Object.values(this.mapping).filter(v => v !== '')
		},
		previewRows() {
			return this.csvRows.slice(0, 5).map(row => {
				const mapped = {}
				for (const [csvCol, schemaField] of Object.entries(this.mapping)) {
					if (schemaField) {
						mapped[schemaField] = row[csvCol] || ''
					}
				}
				const valid = !!mapped.name
				mapped._valid = valid
				mapped._error = valid ? '' : t('shillinq', 'Missing required field: name')
				return mapped
			})
		},
		canProceed() {
			if (this.step === 0) return this.file !== null && this.csvHeaders.length > 0
			if (this.step === 1) return this.mappedFields.length > 0
			if (this.step === 2) return true
			return false
		},
	},
	methods: {
		onFileSelected(event) {
			const file = event.target.files[0]
			this.fileError = ''
			if (!file) return

			if (file.type && !file.type.includes('csv') && !file.name.endsWith('.csv')) {
				this.fileError = t('shillinq', 'Please select a CSV file.')
				return
			}

			this.file = file
			const reader = new FileReader()
			reader.onload = (e) => this.parseCsv(e.target.result)
			reader.readAsText(file)
		},
		parseCsv(text) {
			const lines = text.trim().split('\n')
			if (lines.length < 2) {
				this.fileError = t('shillinq', 'CSV file must have a header row and at least one data row.')
				return
			}

			this.csvHeaders = this.parseCsvLine(lines[0])
			this.csvRows = lines.slice(1).map(line => {
				const values = this.parseCsvLine(line)
				const row = {}
				this.csvHeaders.forEach((h, i) => {
					row[h] = values[i] || ''
				})
				return row
			})

			// Auto-map matching headers
			this.mapping = {}
			this.csvHeaders.forEach(header => {
				const lower = header.toLowerCase().replace(/[^a-z]/g, '')
				const match = this.schemaFields.find(
					f => f.toLowerCase() === lower,
				)
				this.mapping[header] = match || ''
			})
		},
		parseCsvLine(line) {
			const result = []
			let current = ''
			let inQuotes = false
			for (let i = 0; i < line.length; i++) {
				const ch = line[i]
				if (inQuotes) {
					if (ch === '"' && line[i + 1] === '"') {
						current += '"'
						i++
					} else if (ch === '"') {
						inQuotes = false
					} else {
						current += ch
					}
				} else if (ch === '"') {
					inQuotes = true
				} else if (ch === ',') {
					result.push(current.trim())
					current = ''
				} else {
					current += ch
				}
			}
			result.push(current.trim())
			return result
		},
		async startImport() {
			this.importing = true
			this.importProgress = 0
			const dataJobStore = useDataJobStore()
			const organizationStore = useOrganizationStore()

			const job = {
				fileName: this.file.name,
				entityType: 'organization',
				status: 'processing',
				totalRecords: this.csvRows.length,
				processedRecords: 0,
				failedRecords: 0,
			}
			// Capture the server-assigned id so the completion update PATCHes the same record.
			const createdJob = await dataJobStore.saveObject('dataJob', job)

			// Import rows in batches of 10 to keep the UI responsive.
			const BATCH_SIZE = 10
			let processedCount = 0
			let failedCount = 0

			for (let i = 0; i < this.csvRows.length; i += BATCH_SIZE) {
				const batch = this.csvRows.slice(i, i + BATCH_SIZE)
				await Promise.all(batch.map(async (row) => {
					const mapped = {}
					for (const [csvCol, schemaField] of Object.entries(this.mapping)) {
						if (schemaField) {
							mapped[schemaField] = row[csvCol] || ''
						}
					}
					if (mapped.name) {
						try {
							await organizationStore.saveObject('organization', mapped)
							processedCount++
						} catch {
							failedCount++
						}
					} else {
						failedCount++
					}
				}))
				this.importProgress = Math.min(i + BATCH_SIZE, this.csvRows.length)
			}

			// Update the original DataJob record with final counts.
			await dataJobStore.saveObject('dataJob', {
				...createdJob,
				processedRecords: processedCount,
				failedRecords: failedCount,
				status: failedCount > 0 && processedCount === 0 ? 'failed' : 'completed',
			})

			this.importing = false
			this.$emit('imported')
		},
	},
}
</script>

<style scoped>
.csv-import {
	padding: 16px;
}

.csv-import__steps {
	display: flex;
	gap: 16px;
	margin-bottom: 20px;
	padding-bottom: 12px;
	border-bottom: 1px solid var(--color-border);
}

.csv-import__step {
	color: var(--color-text-maxcontrast);
}

.csv-import__step--active {
	color: var(--color-main-text);
	font-weight: 600;
}

.csv-import__step--done {
	color: var(--color-success);
}

.csv-import__error {
	color: var(--color-error);
	margin-top: 8px;
}

.csv-import__map-row {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 8px;
}

.csv-import__map-row label {
	min-width: 150px;
	font-weight: 500;
}

.csv-import__table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 12px;
}

.csv-import__table th,
.csv-import__table td {
	border: 1px solid var(--color-border);
	padding: 6px 10px;
	text-align: left;
}

.csv-import__table th {
	background: var(--color-background-dark);
	font-weight: 600;
}

.csv-import__row--error {
	background: color-mix(in srgb, var(--color-error) 10%, transparent);
}

.csv-import__footer {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 20px;
}

.csv-import__importing {
	color: var(--color-text-maxcontrast);
}
</style>
