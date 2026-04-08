<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<NcDialog :name="t('shillinq', 'Import CSV')"
		size="large"
		@close="$emit('close')">
		<div class="csv-import">
			<!-- Step indicators -->
			<div class="csv-import__steps">
				<span v-for="(label, index) in stepLabels"
					:key="index"
					:class="{ 'csv-import__step--active': currentStep === index }">
					{{ (index + 1) + '. ' + label }}
				</span>
			</div>

			<!-- Step 1: Upload -->
			<div v-if="currentStep === 0" class="csv-import__section">
				<h3>{{ t('shillinq', 'Upload CSV file') }}</h3>
				<input type="file"
					accept=".csv,text/csv"
					@change="onFileSelected">
				<p v-if="fileError" class="csv-import__error">
					{{ fileError }}
				</p>

				<div class="csv-import__entity-select">
					<label>{{ t('shillinq', 'Target entity type') }}</label>
					<select v-model="entityType">
						<option value="organization">
							{{ t('shillinq', 'Organization') }}
						</option>
					</select>
				</div>
			</div>

			<!-- Step 2: Map Columns -->
			<div v-if="currentStep === 1" class="csv-import__section">
				<h3>{{ t('shillinq', 'Map columns') }}</h3>
				<table class="csv-import__mapping-table">
					<thead>
						<tr>
							<th>{{ t('shillinq', 'CSV Column') }}</th>
							<th>{{ t('shillinq', 'Schema Property') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="header in csvHeaders" :key="header">
							<td>{{ header }}</td>
							<td>
								<select v-model="columnMapping[header]">
									<option value="">
										{{ t('shillinq', '— Skip —') }}
									</option>
									<option v-for="prop in schemaProperties"
										:key="prop"
										:value="prop">
										{{ prop }}
									</option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Step 3: Preview -->
			<div v-if="currentStep === 2" class="csv-import__section">
				<h3>{{ t('shillinq', 'Preview') }}</h3>
				<table class="csv-import__preview-table">
					<thead>
						<tr>
							<th v-for="prop in mappedProperties" :key="prop">
								{{ prop }}
							</th>
							<th>{{ t('shillinq', 'Status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(row, idx) in previewRows"
							:key="idx"
							:class="{ 'csv-import__row--error': row._hasError }">
							<td v-for="prop in mappedProperties" :key="prop">
								{{ row[prop] }}
							</td>
							<td>
								<span v-if="row._hasError" class="csv-import__error">
									{{ row._errorMessage }}
								</span>
								<span v-else class="csv-import__valid">
									{{ t('shillinq', 'Valid') }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Step 4: Confirm -->
			<div v-if="currentStep === 3" class="csv-import__section">
				<h3>{{ t('shillinq', 'Confirm import') }}</h3>
				<p>{{ t('shillinq', 'Total rows:') }} {{ csvRows.length }}</p>
				<p>{{ t('shillinq', 'Target entity:') }} {{ entityType }}</p>
				<p v-if="importing" class="csv-import__importing">
					{{ t('shillinq', 'Importing...') }}
				</p>
			</div>

			<!-- Navigation buttons -->
			<div class="csv-import__nav">
				<NcButton v-if="currentStep > 0"
					@click="currentStep--">
					{{ t('shillinq', 'Back') }}
				</NcButton>
				<NcButton v-if="currentStep < 3"
					type="primary"
					:disabled="!canProceed"
					@click="currentStep++">
					{{ t('shillinq', 'Next') }}
				</NcButton>
				<NcButton v-if="currentStep === 3"
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

export default {
	name: 'CsvImportDialog',
	components: {
		NcButton,
		NcDialog,
	},

	data() {
		return {
			currentStep: 0,
			file: null,
			fileError: '',
			entityType: 'organization',
			csvHeaders: [],
			csvRows: [],
			columnMapping: {},
			importing: false,
			schemaProperties: [
				'name', 'registrationNumber', 'email', 'phone',
				'website', 'address', 'city', 'country',
			],
			stepLabels: [
				t('shillinq', 'Upload'),
				t('shillinq', 'Map Columns'),
				t('shillinq', 'Preview'),
				t('shillinq', 'Confirm'),
			],
		}
	},

	computed: {
		canProceed() {
			if (this.currentStep === 0) {
				return this.file !== null && this.csvHeaders.length > 0
			}
			if (this.currentStep === 1) {
				return Object.values(this.columnMapping).some(v => v !== '')
			}
			return true
		},
		mappedProperties() {
			return Object.values(this.columnMapping).filter(v => v !== '')
		},
		previewRows() {
			return this.csvRows.slice(0, 5).map(row => {
				const mapped = {}
				let hasError = false
				let errorMessage = ''

				for (const [csvCol, schemaProp] of Object.entries(this.columnMapping)) {
					if (schemaProp !== '') {
						const headerIndex = this.csvHeaders.indexOf(csvCol)
						mapped[schemaProp] = row[headerIndex] ?? ''
					}
				}

				if (mapped.name === undefined || mapped.name === '') {
					hasError = true
					errorMessage = t('shillinq', 'Missing required field: name')
				}

				return { ...mapped, _hasError: hasError, _errorMessage: errorMessage }
			})
		},
	},

	methods: {
		onFileSelected(event) {
			const file = event.target.files[0]
			this.fileError = ''

			if (file === undefined) {
				return
			}

			if (file.type !== '' && file.type !== 'text/csv' && !file.name.endsWith('.csv')) {
				this.fileError = t('shillinq', 'Please select a valid CSV file.')
				return
			}

			this.file = file
			this.parseCsv(file)
		},

		parseCsv(file) {
			const reader = new FileReader()
			reader.onload = (e) => {
				const text = e.target.result
				const lines = text.split('\n').filter(l => l.trim() !== '')

				if (lines.length < 2) {
					this.fileError = t('shillinq', 'CSV must have a header row and at least one data row.')
					return
				}

				this.csvHeaders = this.parseCsvLine(lines[0])
				this.csvRows = lines.slice(1).map(line => this.parseCsvLine(line))

				// Auto-detect column mapping
				this.columnMapping = {}
				for (const header of this.csvHeaders) {
					const normalized = header.trim().toLowerCase()
					const match = this.schemaProperties.find(
						p => p.toLowerCase() === normalized,
					)
					this.columnMapping[header] = match || ''
				}
			}
			reader.readAsText(file)
		},

		parseCsvLine(line) {
			const result = []
			let current = ''
			let inQuotes = false

			for (let i = 0; i < line.length; i++) {
				const char = line[i]
				if (char === '"') {
					inQuotes = !inQuotes
				} else if (char === ',' && !inQuotes) {
					result.push(current.trim())
					current = ''
				} else {
					current += char
				}
			}
			result.push(current.trim())
			return result
		},

		async startImport() {
			this.importing = true
			const dataJobStore = useDataJobStore()

			await dataJobStore.saveObject({
				fileName: this.file.name,
				entityType: this.entityType,
				status: 'processing',
				totalRecords: this.csvRows.length,
				processedRecords: 0,
				failedRecords: 0,
				errorLog: '',
			})

			this.importing = false
			this.$emit('close')
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

.csv-import__step--active {
	font-weight: 700;
	color: var(--color-primary);
}

.csv-import__section {
	min-height: 200px;
}

.csv-import__entity-select {
	margin-top: 16px;
}

.csv-import__entity-select label {
	display: block;
	margin-bottom: 4px;
	font-weight: 500;
}

.csv-import__mapping-table,
.csv-import__preview-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 8px;
}

.csv-import__mapping-table th,
.csv-import__mapping-table td,
.csv-import__preview-table th,
.csv-import__preview-table td {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	text-align: left;
}

.csv-import__row--error {
	background-color: var(--color-error-hover);
}

.csv-import__error {
	color: var(--color-error);
}

.csv-import__valid {
	color: var(--color-success);
}

.csv-import__nav {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 20px;
}

.csv-import__importing {
	color: var(--color-primary);
	font-weight: 500;
}
</style>
