<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Generated Reports index (reporting-compliance-consolidation).

 The archive view for the Reporting & Compliance section: every report
 generated through ReportingComplianceOverview is persisted as a
 GeneratedReport record (lib/Settings/register.d/reporting-generated-report.json)
 with its file stored in Nextcloud Files and tagged, so this page lists
 them with a download link back to the stored file.

 Data source: GET /api/reporting/generated returns the GeneratedReport
 rows (reportType, category, period, administrationId, format, fileName,
 generatedAt, status, id). Each row's download link points at
 /api/reporting/download/{id}, which streams the stored file.

 The table is rendered with CnDataTable so it inherits the fleet table
 chrome (sortable headers, empty/loading states) while the three filters
 (category, period, administration) narrow the row set in-memory — the row
 count is bounded by how many reports an administration actually produces,
 so an in-memory filter is well-sized.

 Registered in src/registry.js as a kind:"page" custom component: the
 download-link cell + the three-facet filter row do not fit the built-in
 `index` page type (ADR-024 / ADR-036). The manifest fragment
 src/manifest.d/reporting-compliance.json declares the route.

 @spec exclude The reporting capability has no canonical spec. This tag pointed at
       openspec/changes/reporting-compliance-consolidation (a change directory that
       exists neither under changes nor under changes/archive), and no canonical
       reporting capability exists under openspec/specs either. Tracked in #525.
       Deliberately NOT resolved by writing that spec — authoring the requirement
       a tag is checked against turns the gate green over an unspecified capability.

 KNOWINGLY DANGLING — do not repoint this tag at a spec (gate-46, shillinq#499).
 The change directory it named was never committed, and the `reporting`
 capability has NO canonical spec. One was drafted during gate remediation and
 withdrawn: a spec written to fit the code, by the process whose job is to
 check the code against a spec, is not a specification anyone agreed to.
 Authoring it is the capability owner's decision, not a gate fix.

 The dangling path is replaced by the reason-bearing `@spec exclude` above —
 the same declaration lib/Controller/ReportingController.php already carries for
 the same capability. The prose note alone did not say this to gate-46, which
 reads the tag and not the paragraph under it, so the two halves of the same
 decision disagreed and only the PHP half was legible.
-->
<template>
	<div class="generated-reports" data-testid="generated-reports">
		<header class="generated-reports__header">
			<div>
				<h2 data-testid="generated-reports-title">
					{{ t('shillinq', 'Generated reports') }}
				</h2>
				<p class="generated-reports__hint">
					{{
						t(
							'shillinq',
							'Every report generated from the Reporting & Compliance overview is archived here with a download link to the stored file.',
						)
					}}
				</p>
			</div>
			<router-link
				class="generated-reports__back"
				data-testid="generated-reports-back"
				:to="{ name: 'ReportingComplianceOverview' }">
				{{ t('shillinq', 'Back to overview') }}
			</router-link>
		</header>

		<div
			class="generated-reports__filters"
			data-testid="generated-reports-filters">
			<div class="generated-reports__filter">
				<label
					class="generated-reports__filter-label"
					for="generated-category-filter">
					{{ t('shillinq', 'Category') }}
				</label>
				<select
					id="generated-category-filter"
					v-model="categoryFilter"
					data-testid="generated-category-filter">
					<option value="">
						{{ t('shillinq', 'All categories') }}
					</option>
					<option
						v-for="cat in categoryOptions"
						:key="cat.value"
						:value="cat.value">
						{{ cat.label }}
					</option>
				</select>
			</div>
			<div class="generated-reports__filter">
				<label
					class="generated-reports__filter-label"
					for="generated-period-filter">
					{{ t('shillinq', 'Period') }}
				</label>
				<select
					id="generated-period-filter"
					v-model="periodFilter"
					data-testid="generated-period-filter">
					<option value="">
						{{ t('shillinq', 'All periods') }}
					</option>
					<option
						v-for="period in periodOptions"
						:key="period"
						:value="period">
						{{ period }}
					</option>
				</select>
			</div>
			<div class="generated-reports__filter">
				<label
					class="generated-reports__filter-label"
					for="generated-administration-filter">
					{{ t('shillinq', 'Administration') }}
				</label>
				<select
					id="generated-administration-filter"
					v-model="administrationFilter"
					data-testid="generated-administration-filter">
					<option value="">
						{{ t('shillinq', 'All administrations') }}
					</option>
					<option
						v-for="admin in administrationOptions"
						:key="admin"
						:value="admin">
						{{ administrationLabel(admin) }}
					</option>
				</select>
			</div>
		</div>

		<div
			v-if="loading"
			class="generated-reports__loading"
			data-testid="generated-reports-loading">
			{{ t('shillinq', 'Loading generated reports…') }}
		</div>

		<div
			v-else-if="error"
			class="generated-reports__error"
			data-testid="generated-reports-error">
			{{ error }}
		</div>

		<CnDataTable
			v-else
			data-testid="generated-reports-table"
			:columns="columns"
			:rows="filteredRows"
			:emptyLabel="
				t('shillinq', 'No generated reports match the current filters.')
			">
			<template #cell-reportType="{ row }">
				{{ reportLabel(row) }}
			</template>
			<template #cell-category="{ row }">
				{{ categoryLabel(row.category) }}
			</template>
			<template #cell-generatedAt="{ row }">
				{{ formatDate(row.generatedAt) }}
			</template>
			<template #cell-format="{ row }">
				<span class="generated-reports__format">{{
					(row.format || '').toUpperCase()
				}}</span>
			</template>
			<template #cell-administrationId="{ row }">
				{{ administrationLabel(row.administrationId) }}
			</template>
			<template #cell-download="{ row }">
				<a
					class="generated-reports__download"
					:href="downloadUrl(row)"
					target="_blank"
					rel="noopener noreferrer"
					:data-testid="`generated-download-${row.id}`">
					{{ t('shillinq', 'Download') }}
				</a>
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'GeneratedReportsIndex',
	components: {
		CnDataTable,
	},

	data() {
		return {
			rows: [],
			categories: {},
			administrationNames: {},
			loading: true,
			error: '',
			categoryFilter: '',
			periodFilter: '',
			administrationFilter: '',
		}
	},

	computed: {
		columns() {
			return [
				{
					key: 'reportType',
					label: this.t('shillinq', 'Report'),
					sortable: true,
				},
				{
					key: 'category',
					label: this.t('shillinq', 'Category'),
					sortable: true,
				},
				{
					key: 'period',
					label: this.t('shillinq', 'Period'),
					sortable: true,
				},
				{
					key: 'administrationId',
					label: this.t('shillinq', 'Administration'),
					sortable: true,
				},
				{
					key: 'format',
					label: this.t('shillinq', 'Format'),
					sortable: true,
				},
				{
					key: 'generatedAt',
					label: this.t('shillinq', 'Generated at'),
					sortable: true,
				},
				{
					key: 'download',
					label: this.t('shillinq', 'File'),
					sortable: false,
				},
			]
		},

		categoryOptions() {
			const present = [
				...new Set(this.rows.map((r) => r.category).filter(Boolean)),
			]
			return present.map((key) => ({
				value: key,
				label: this.categories[key] || key,
			}))
		},

		periodOptions() {
			return [...new Set(this.rows.map((r) => r.period).filter(Boolean))]
				.sort()
				.reverse()
		},

		administrationOptions() {
			return [
				...new Set(this.rows.map((r) => r.administrationId).filter(Boolean)),
			]
		},

		filteredRows() {
			return this.rows.filter((row) => {
				if (this.categoryFilter && row.category !== this.categoryFilter) {
					return false
				}
				if (this.periodFilter && row.period !== this.periodFilter) {
					return false
				}
				if (
					this.administrationFilter
					&& row.administrationId !== this.administrationFilter
				) {
					return false
				}
				return true
			})
		},
	},

	async created() {
		await this.loadAdministrationContext()
		await this.loadGenerated()
	},

	methods: {
		t,
		async loadGenerated() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/reporting/generated'),
				)
				const data = response.data || {}
				const rows = Array.isArray(data.generated)
					? data.generated
					: Array.isArray(data.results)
						? data.results
						: Array.isArray(data)
							? data
							: []
				this.rows = rows
				this.categories = data.categories || {}
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load generated reports')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Best-effort administration names so the administration column +
		 * filter read names instead of raw ids. A failure is non-fatal: the
		 * column falls back to the raw administrationId.
		 */
		async loadAdministrationContext() {
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/administrations/context'),
				)
				const admins = response.data?.administrations || []
				const names = {}
				for (const a of admins) {
					names[a.administrationId] =
						a.name || a.administrationCode || a.administrationId
				}
				this.administrationNames = names
			} catch (e) {
				this.administrationNames = {}
			}
		},

		downloadUrl(row) {
			return generateUrl(`/apps/shillinq/api/reporting/download/${row.id}`)
		},

		reportLabel(row) {
			return row.reportLabel || row.reportType || '—'
		},

		categoryLabel(category) {
			return this.categories[category] || category || '—'
		},

		administrationLabel(administrationId) {
			if (!administrationId) {
				return '—'
			}
			return this.administrationNames[administrationId] || administrationId
		},

		formatDate(iso) {
			if (!iso) {
				return '—'
			}
			try {
				return new Date(iso).toLocaleString()
			} catch (e) {
				return iso
			}
		},
	},
}
</script>

<style scoped>
.generated-reports {
	padding: 1rem;
}

.generated-reports__header {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	align-items: flex-start;
	justify-content: space-between;
	margin-bottom: 1rem;
}

.generated-reports__header h2 {
	margin: 0 0 0.25rem 0;
}

.generated-reports__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
	max-width: 48rem;
}

.generated-reports__back {
	font-weight: 600;
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.generated-reports__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.generated-reports__filter {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.generated-reports__filter-label {
	font-weight: 600;
	font-size: var(--default-font-size, 0.875rem);
}

.generated-reports__loading,
.generated-reports__error {
	padding: 1rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.generated-reports__error {
	color: var(--color-error);
}

.generated-reports__format {
	display: inline-block;
	padding: 0.125rem 0.5rem;
	border-radius: var(--border-radius-pill);
	font-size: 0.75rem;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.generated-reports__download {
	font-weight: 600;
	color: var(--color-primary-element);
}
</style>
