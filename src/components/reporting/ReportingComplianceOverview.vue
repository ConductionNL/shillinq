<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Reporting & Compliance — overview page (reporting-compliance-consolidation).

 The single landing page for the consolidated "Reporting & Compliance"
 section. It is the operator-facing front end of the static
 ReportCatalogue (lib/Reporting/ReportCatalogue.php): every report shillinq
 can produce — tax filings, statutory statements, ledger exports, audit
 files, public-sector reports and the compliance audit trail — is surfaced
 here as a card grouped by category, replacing the report leaves that used
 to be scattered across the Belastingen / Bookkeeping / PublicSector /
 Purchasing menus.

 Layout:

   ┌────────────────────────────────────────────────────────────────┐
   │  Compliance KPI (CnStatsBlock)   +   link to generated reports  │
   ├────────────────────────────────────────────────────────────────┤
   │  Filters: category select  +  free-text search                 │
   ├────────────────────────────────────────────────────────────────┤
   │  Category heading                                              │
   │   ┌──────────┐ ┌──────────┐ ┌──────────┐                       │
   │   │ card     │ │ card     │ │ card     │  (one per report type)│
   │   │ label    │ │  …       │ │  …       │                       │
   │   │ format ▾ │ │          │ │          │                       │
   │   │ Genereer │ │          │ │          │                       │
   │   └──────────┘ └──────────┘ └──────────┘                       │
   └────────────────────────────────────────────────────────────────┘

 Data source: GET /api/reporting/types returns the catalogue rows (id,
 label, category, kind, formats, description) plus the category display
 labels. The "Genereer" button opens an isolated GenerateReportDialog
 (modal isolation per hydra gate-13) that collects period + administration
 + format and POSTs /api/reporting/generate. On success a toast links to
 the generated file and the user is pointed at the generated-reports
 index.

 Registered in src/registry.js as a kind:"page" custom component — neither
 the category-grouped card grid, the per-card format picker, nor the
 generate dialog fit a built-in index/detail/dashboard page type
 (ADR-024 / ADR-036). The manifest fragment
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
	<div class="reporting-overview" data-testid="reporting-overview">
		<header class="reporting-overview__header">
			<div class="reporting-overview__heading">
				<h2 data-testid="reporting-overview-title">
					{{ t('shillinq', 'Reporting & Compliance') }}
				</h2>
				<p class="reporting-overview__hint">
					{{
						t(
							'shillinq',
							'Generate every statutory, tax and public-sector report shillinq supports from one place. Pick a report, choose a period and format, and generate the file.',
						)
					}}
				</p>
			</div>

			<div class="reporting-overview__kpi">
				<CnStatsBlock
					data-testid="reporting-overview-kpi"
					:title="t('shillinq', 'Available reports')"
					:count="reports.length"
					:countLabel="t('shillinq', 'report types')"
					variant="default"
					:loading="loading"
					:error="error" />
				<router-link
					class="reporting-overview__generated-link"
					data-testid="reporting-overview-generated-link"
					:to="{ name: 'GeneratedReportsIndex' }">
					{{ t('shillinq', 'View generated reports') }}
				</router-link>
			</div>
		</header>

		<div
			class="reporting-overview__filters"
			data-testid="reporting-overview-filters">
			<div class="reporting-overview__filter">
				<label
					class="reporting-overview__filter-label"
					for="reporting-category-filter">
					{{ t('shillinq', 'Category') }}
				</label>
				<select
					id="reporting-category-filter"
					v-model="categoryFilter"
					data-testid="reporting-category-filter">
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
			<div class="reporting-overview__filter">
				<label
					class="reporting-overview__filter-label"
					for="reporting-search">
					{{ t('shillinq', 'Search') }}
				</label>
				<input
					id="reporting-search"
					v-model="search"
					type="search"
					data-testid="reporting-search"
					:placeholder="t('shillinq', 'Search reports…')" />
			</div>
		</div>

		<div
			v-if="loading"
			class="reporting-overview__loading"
			data-testid="reporting-overview-loading">
			{{ t('shillinq', 'Loading report catalogue…') }}
		</div>

		<div
			v-else-if="error"
			class="reporting-overview__error"
			data-testid="reporting-overview-error">
			{{ error }}
		</div>

		<p
			v-else-if="filteredGroups.length === 0"
			class="reporting-overview__empty"
			data-testid="reporting-overview-empty">
			{{ t('shillinq', 'No reports match the current filters.') }}
		</p>

		<div v-else class="reporting-overview__groups">
			<section
				v-for="group in filteredGroups"
				:key="group.category"
				class="reporting-overview__group"
				:data-testid="`reporting-group-${group.category}`">
				<h3 class="reporting-overview__group-title">
					{{ group.label }}
				</h3>
				<div class="reporting-overview__cards">
					<article
						v-for="report in group.reports"
						:key="report.id"
						class="reporting-overview__card"
						:data-testid="`reporting-card-${report.id}`">
						<header class="reporting-overview__card-head">
							<h4 class="reporting-overview__card-title">
								{{ report.label }}
							</h4>
							<span
								class="reporting-overview__card-kind"
								:class="`reporting-overview__card-kind--${report.kind}`">
								{{ kindLabel(report.kind) }}
							</span>
						</header>
						<p class="reporting-overview__card-desc">
							{{ report.description }}
						</p>
						<div
							v-if="report.kind !== 'view'"
							class="reporting-overview__card-format">
							<label
								class="reporting-overview__filter-label"
								:for="`reporting-format-${report.id}`">
								{{ t('shillinq', 'Format') }}
							</label>
							<select
								:id="`reporting-format-${report.id}`"
								v-model="selectedFormat[report.id]"
								:data-testid="`reporting-format-${report.id}`">
								<option
									v-for="format in report.formats || []"
									:key="format"
									:value="format">
									{{ format.toUpperCase() }}
								</option>
							</select>
						</div>
						<div class="reporting-overview__card-actions">
							<router-link
								v-if="report.kind === 'view'"
								class="reporting-overview__generate"
								:data-testid="`reporting-open-${report.id}`"
								:to="{ name: report.id }">
								{{ t('shillinq', 'Open') }}
							</router-link>
							<button
								v-else
								type="button"
								class="reporting-overview__generate"
								:data-testid="`reporting-generate-${report.id}`"
								@click="openGenerate(report)">
								{{ t('shillinq', 'Generate') }}
							</button>
						</div>
					</article>
				</div>
			</section>
		</div>

		<GenerateReportDialog
			v-if="dialogReport"
			:report="dialogReport"
			:format="selectedFormat[dialogReport.id]"
			:administrationOptions="administrationOptions"
			:defaultAdministrationId="activeAdministrationId"
			@close="dialogReport = null"
			@generated="onGenerated" />
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import GenerateReportDialog from '../../modals/GenerateReportDialog.vue'
import { reportViewCategories, reportViews } from './reportViews.js'

export default {
	name: 'ReportingComplianceOverview',
	components: {
		CnStatsBlock,
		GenerateReportDialog,
	},

	data() {
		return {
			reports: [],
			categories: {},
			loading: true,
			error: '',
			categoryFilter: '',
			search: '',
			selectedFormat: {},
			dialogReport: null,
			administrationOptions: [],
			activeAdministrationId: '',
		}
	},

	computed: {
		/**
		 * Category select options, in catalogue order, restricted to the
		 * categories that actually have a report in the loaded catalogue.
		 */
		categoryOptions() {
			const present = new Set(this.reports.map((r) => r.category))
			return Object.keys(this.categories)
				.filter((key) => present.has(key))
				.map((key) => ({ value: key, label: this.categories[key] }))
		},

		/**
		 * The catalogue filtered by the category select + the free-text
		 * search, then grouped by category in catalogue-declared order. The
		 * category label comes from the `categories` map returned alongside
		 * the report rows (falls back to the raw key).
		 */
		filteredGroups() {
			const term = this.search.trim().toLowerCase()
			const matches = this.reports.filter((report) => {
				if (this.categoryFilter && report.category !== this.categoryFilter) {
					return false
				}
				if (!term) {
					return true
				}
				const haystack =
					`${report.label} ${report.description} ${report.id}`.toLowerCase()
				return haystack.includes(term)
			})

			const order = Object.keys(this.categories)
			const byCategory = {}
			for (const report of matches) {
				if (!byCategory[report.category]) {
					byCategory[report.category] = []
				}
				byCategory[report.category].push(report)
			}

			// Keep catalogue order; append any unknown categories last.
			const orderedKeys = [
				...order.filter((key) => byCategory[key]),
				...Object.keys(byCategory).filter((key) => !order.includes(key)),
			]
			return orderedKeys.map((key) => ({
				category: key,
				label: this.categories[key] || key,
				reports: byCategory[key],
			}))
		},
	},

	async created() {
		await this.loadAdministrationContext()
		await this.loadTypes()
	},

	methods: {
		t,
		/**
		 * Pull the report catalogue from the consolidation endpoint. The
		 * response carries the catalogue rows plus the category display
		 * labels so the grouping headings stay server-authoritative.
		 */
		async loadTypes() {
			this.loading = true
			this.error = ''
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/reporting/types'),
				)
				const data = response.data || {}
				const rows = Array.isArray(data.types)
					? data.types
					: Array.isArray(data)
						? data
						: []
				// Merge the existing report VIEW pages (navigate-cards) so the one
				// overview holds every report — generate-to-file and on-screen
				// views alike — instead of leaving them as scattered menu items.
				const views = reportViews.map((v) => ({
					...v,
					kind: 'view',
					formats: [],
					description: this.t('shillinq', 'Open this report.'),
				}))
				this.reports = [...rows, ...views]
				this.categories = {
					...(data.categories || {}),
					...reportViewCategories,
				}

				// Seed each card's format picker with the first offered format.
				const seed = {}
				for (const report of this.reports) {
					seed[report.id] = (report.formats && report.formats[0]) || ''
				}
				this.selectedFormat = seed
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load the report catalogue')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Best-effort administration context so the generate dialog can
		 * pre-select the active administration and offer a switcher. A
		 * failure here is non-fatal — the dialog falls back to a free-text
		 * administration field.
		 */
		async loadAdministrationContext() {
			try {
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/administrations/context'),
				)
				const admins = response.data?.administrations || []
				this.administrationOptions = admins.map((a) => ({
					value: a.administrationId,
					label: a.name || a.administrationCode || a.administrationId,
				}))
				if (response.data?.activeAdministrationId) {
					this.activeAdministrationId =
						response.data.activeAdministrationId
				}
			} catch (e) {
				this.administrationOptions = []
			}
		},

		openGenerate(report) {
			this.dialogReport = report
		},

		/**
		 * The dialog POSTed /api/reporting/generate successfully; surface a
		 * toast linking to the file and point the user at the index.
		 *
		 * @param {object} result The generate-endpoint response body.
		 */
		onGenerated(result) {
			this.dialogReport = null
			const downloadUrl =
				result?.downloadUrl
				|| (result?.id
					? generateUrl(
							`/apps/shillinq/api/reporting/download/${result.id}`,
						)
					: null)
			if (downloadUrl) {
				showSuccess(
					this.t('shillinq', 'Report generated — {link}', {
						link: `<a href="${downloadUrl}" target="_blank" rel="noopener noreferrer">${this.t('shillinq', 'download the file')}</a>`,
					}),
					{ isHTML: true },
				)
			} else {
				showSuccess(this.t('shillinq', 'Report generated.'))
			}
		},

		onGenerateError(message) {
			showError(message || this.t('shillinq', 'Report generation failed'))
		},

		kindLabel(kind) {
			if (kind === 'document') {
				return this.t('shillinq', 'Document')
			}
			if (kind === 'data') {
				return this.t('shillinq', 'Data export')
			}
			return kind || ''
		},
	},
}
</script>

<style scoped>
.reporting-overview {
	padding: 1rem;
}

.reporting-overview__header {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	align-items: flex-start;
	justify-content: space-between;
	margin-bottom: 1rem;
}

.reporting-overview__heading h2 {
	margin: 0 0 0.25rem 0;
}

.reporting-overview__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
	max-width: 48rem;
}

.reporting-overview__kpi {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 0.5rem;
	min-width: 14rem;
}

.reporting-overview__generated-link {
	font-weight: 600;
	color: var(--color-primary-element);
}

.reporting-overview__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.reporting-overview__filter {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.reporting-overview__filter-label {
	font-weight: 600;
	font-size: var(--default-font-size, 0.875rem);
}

.reporting-overview__loading,
.reporting-overview__error,
.reporting-overview__empty {
	padding: 1rem;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.reporting-overview__error {
	color: var(--color-error);
}

.reporting-overview__group {
	margin-bottom: 2rem;
}

.reporting-overview__group-title {
	margin: 0 0 0.75rem 0;
	padding-bottom: 0.25rem;
	border-bottom: 1px solid var(--color-border);
}

.reporting-overview__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(20rem, 1fr));
	gap: 1rem;
}

.reporting-overview__card {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.reporting-overview__card-head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 0.5rem;
}

.reporting-overview__card-title {
	margin: 0;
	font-size: 1rem;
}

.reporting-overview__card-kind {
	flex-shrink: 0;
	padding: 0.125rem 0.5rem;
	border-radius: var(--border-radius-pill);
	font-size: 0.75rem;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.reporting-overview__card-kind--document {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.reporting-overview__card-desc {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--default-font-size, 0.875rem);
	flex-grow: 1;
}

.reporting-overview__card-format {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.reporting-overview__card-format select {
	flex-grow: 1;
}

.reporting-overview__card-actions {
	display: flex;
	justify-content: flex-end;
}

.reporting-overview__generate {
	padding: 0.375rem 1rem;
	border: 0;
	border-radius: var(--border-radius);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
	cursor: pointer;
}

.reporting-overview__generate:hover {
	background: var(--color-primary-element-hover);
}
</style>
