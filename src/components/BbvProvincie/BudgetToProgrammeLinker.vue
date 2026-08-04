<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Budget-to-Programme Linker — index
 (bookkeeping-provincies-bbv-variant, REQ-BBL-001 / 003 / 004).

 Lists the GL lines of the active administration and lets a controller
 assign them to a BBV provincie programme in bulk. The table itself is
 the shared `CnIndexPage` (columns, sort, paging, selection, empty state
 all platform-rendered); this component adds the three affordances the
 built-in index page has no vocabulary for and which the manifest
 declares:

   * `config.mappingStatus` — the "Unmapped GL lines: N of M (P%)" badge
     with its red/yellow/green thresholds (REQ-BBL-004).
   * `config.filters[]`     — the three facets (account type, programme,
     assignment status), applied cumulatively (REQ-BBL-001.1).
   * `config.bulkActions[]` — the "Link to Programme" CTA plus its
     declarative dialog (target programme + effective date).

 All three are rendered by iterating the manifest config, and every
 `data-testid` is derived from the declared key / id, so a new facet is a
 manifest edit rather than a Vue edit.

 Writes go through OpenRegister's object API one row at a time
 (ADR-022); OR's own audit trail is what records the before/after
 programme per line (REQ-BBL-003) — this component records nothing
 itself.

 Registered in src/registry.js as a kind:"page" component so the manifest
 router can dispatch `component: "BudgetToProgrammeLinker"`.
 ADR-036 / ADR-037.

 @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
-->
<template>
	<div class="bbv-linker" data-testid="bbv-linker-index">
		<header class="bbv-linker__header">
			<h2 class="bbv-linker__title">
				{{ title }}
			</h2>
			<span
				v-if="mappingStatus"
				class="bbv-linker__badge"
				:class="`bbv-linker__badge--${mappingBand}`"
				data-testid="bbv-linker-mapping-status">
				{{ mappingStatusLabel }}
			</span>
		</header>

		<!-- REQ-BBL-001.1 — the three declared filter facets. -->
		<div
			v-if="filters.length"
			class="bbv-linker__filters"
			data-testid="bbv-linker-filters">
			<label
				v-for="filter in filters"
				:key="filter.key"
				class="bbv-linker__filter">
				<span class="bbv-linker__filter-label">{{ filter.label || filter.key }}</span>
				<select
					v-model="filterState[filter.key]"
					class="bbv-linker__filter-control"
					:aria-label="filter.label || filter.key"
					:data-testid="`bbv-linker-filter-${filter.key}`">
					<option value="">
						{{ t('shillinq', 'All') }}
					</option>
					<option
						v-for="option in filter.options || []"
						:key="option"
						:value="option">
						{{ option }}
					</option>
				</select>
			</label>
		</div>

		<!-- REQ-BBL-001.2 — bulk action, disabled until a row is selected.
		     The toolbar only exists while there are rows to act on, so an
		     empty table never shows a permanently-dead CTA. -->
		<div v-if="bulkActions.length && filteredRows.length" class="bbv-linker__bulk">
			<button
				type="button"
				class="bbv-linker__select-all"
				data-testid="bbv-linker-select-all"
				@click="toggleSelectAll">
				{{ allSelected ? t('shillinq', 'Clear selection') : t('shillinq', 'Select all') }}
			</button>
			<button
				v-for="action in bulkActions"
				:key="action.id"
				type="button"
				class="bbv-linker__bulk-button"
				:disabled="isBulkDisabled(action)"
				:data-testid="bulkActionTestId(action)"
				@click="openBulkDialog(action)">
				{{ action.label || action.id }}
			</button>
			<span class="bbv-linker__selection-count">
				{{ n('shillinq', '%n line selected', '%n lines selected', selectedIds.length) }}
			</span>
		</div>

		<div class="bbv-linker__table" data-testid="bbv-linker-table">
			<CnIndexPage
				:objects="filteredRows"
				:loading="loading"
				:columns="columns"
				:selectable="selectable"
				:selected-ids="selectedIds"
				:row-click-to-view="true"
				:sort-key="defaultSort"
				:empty-text="t('shillinq', 'No GL lines match the current filters.')"
				row-key="id"
				@select="onSelect"
				@row-click="onRowClick"
				@view="onRowClick"
				@refresh="load" />
		</div>

		<BbvLinkDialog
			v-if="activeAction"
			:action="activeAction"
			:count="selectedIds.length"
			:submitting="submitting"
			:error="dialogError"
			@submit="onBulkSubmit"
			@close="closeBulkDialog" />

		<p
			v-if="error"
			class="bbv-linker__error"
			role="alert"
			data-testid="bbv-linker-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import BbvLinkDialog from '../../modals/BbvLinkDialog.vue'
import { fetchObjects, saveAssignment } from './bbvProvincieData.js'

/** Field carrying a GL line's BBV programme assignment. */
const PROGRAMME_FIELD = 'programmaStructure'

export default {
	name: 'BudgetToProgrammeLinker',

	components: {
		BbvLinkDialog,
		CnIndexPage,
	},

	/** See BbvProvincieComplianceDashboard — no stray config key on the root. */
	inheritAttrs: false,

	props: {
		/** Page title, lifted out of the manifest page entry. */
		title: {
			type: String,
			default: 'Budget Links',
		},
		/** Register slug the GLLine schema lives in. */
		register: {
			type: String,
			default: 'shillinq',
		},
		/** Schema slug the index lists. */
		schema: {
			type: String,
			default: 'GLLine',
		},
		/** Declared columns (`config.columns`). */
		columns: {
			type: Array,
			default: () => [],
		},
		/** Declared facets (`config.filters`). */
		filters: {
			type: Array,
			default: () => [],
		},
		/** Declared bulk actions (`config.bulkActions`). */
		bulkActions: {
			type: Array,
			default: () => [],
		},
		/** Declared mapping-status badge config (`config.mappingStatus`). */
		mappingStatus: {
			type: Object,
			default: null,
		},
		/** Whether rows carry selection checkboxes. */
		selectable: {
			type: Boolean,
			default: true,
		},
		/** Declared default sort column. */
		defaultSort: {
			type: String,
			default: '',
		},
		/** Declared page size. */
		pageSize: {
			type: Number,
			default: 50,
		},
		/** Route name of the per-line detail page. */
		detailRoute: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			rows: [],
			loading: true,
			error: '',
			selectedIds: [],
			filterState: {},
			activeAction: null,
			submitting: false,
			dialogError: '',
		}
	},

	computed: {
		/**
		 * Rows narrowed by the active facets, applied cumulatively.
		 *
		 * @return {Array<object>} The in-scope GL lines.
		 */
		filteredRows() {
			return this.rows.filter((row) => this.filters.every((filter) => this.rowMatches(row, filter)))
		},
		/**
		 * GL lines with no programme assignment (REQ-BBL-004's numerator).
		 *
		 * @return {number} The unmapped count.
		 */
		unmappedCount() {
			const field = this.mappingStatus?.unmappedField || PROGRAMME_FIELD
			return this.rows.filter((row) => !row[field]).length
		},
		/**
		 * Percentage of GL lines still unmapped, rounded to a whole percent.
		 *
		 * @return {number} The percentage (0 when there are no lines).
		 */
		unmappedPercentage() {
			if (!this.rows.length) {
				return 0
			}
			return Math.round((this.unmappedCount / this.rows.length) * 100)
		},
		/**
		 * The badge text REQ-BBL-004 specifies.
		 *
		 * @return {string} "Unmapped GL lines: N of M (P%)".
		 */
		mappingStatusLabel() {
			const label = this.mappingStatus?.label || this.t('shillinq', 'Unmapped GL lines')
			return `${label}: ${this.unmappedCount} ${this.t('shillinq', 'of')} ${this.rows.length} (${this.unmappedPercentage}%)`
		},
		/**
		 * Whether every in-scope row is currently selected — drives the
		 * bulk toolbar's select-all / clear-selection toggle.
		 *
		 * @return {boolean} True when the selection covers every filtered row.
		 */
		allSelected() {
			return this.filteredRows.length > 0
				&& this.selectedIds.length === this.filteredRows.length
		},
		/**
		 * Badge colour band from the declared thresholds (red above the red
		 * threshold, yellow above the yellow one, green below both).
		 *
		 * @return {string} `red`, `yellow`, or `green`.
		 */
		mappingBand() {
			const thresholds = this.mappingStatus?.thresholds || {}
			const percentage = this.unmappedPercentage
			if (thresholds.red !== undefined && percentage > thresholds.red) {
				return 'red'
			}
			if (thresholds.yellow !== undefined && percentage >= thresholds.yellow) {
				return 'yellow'
			}
			return 'green'
		},
	},

	created() {
		this.initFilterState()
		this.load()
	},

	methods: {
		/**
		 * Seed the facet state, honouring a programme pre-selection handed
		 * over from the dashboard's exception remediation link.
		 *
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		initFilterState() {
			const query = this.$route?.query ?? {}
			const state = {}
			this.filters.forEach((filter) => {
				const raw = query[filter.key]
				state[filter.key] = raw === undefined ? '' : String(raw)
			})
			this.filterState = state
		},
		/**
		 * Load the GL lines this index paginates over.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.rows = await fetchObjects(this.register, this.schema, { _limit: this.pageSize })
			} catch (e) {
				this.rows = []
				this.error = this.t('shillinq', 'Failed to load GL lines.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Whether a row satisfies one declared facet. The
		 * `assignmentStatus` facet is derived (a line is mapped when it
		 * carries a programme) rather than a stored field; the programme
		 * facet's `unmapped` option is the same derivation.
		 *
		 * @param {object} row The GL line.
		 * @param {object} filter The manifest filter descriptor.
		 * @return {boolean} True when the row is in scope.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		rowMatches(row, filter) {
			const selected = this.filterState[filter.key]
			if (!selected) {
				return true
			}
			if (filter.key === 'assignmentStatus') {
				const mapped = Boolean(row[PROGRAMME_FIELD])
				return selected === 'mapped' ? mapped : !mapped
			}
			if (filter.key === PROGRAMME_FIELD && selected === 'unmapped') {
				return !row[PROGRAMME_FIELD]
			}
			return String(row[filter.key] ?? '') === selected
		},
		/**
		 * Track CnIndexPage's selection so the bulk CTA can enable itself.
		 *
		 * @param {Array<string>} ids The selected row ids.
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		onSelect(ids) {
			this.selectedIds = Array.isArray(ids) ? ids : []
		},
		/**
		 * Select every in-scope row, or clear the selection when they are
		 * already all selected. The bulk toolbar's counterpart to the
		 * per-row checkboxes CnIndexPage renders.
		 *
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		toggleSelectAll() {
			if (this.allSelected) {
				this.selectedIds = []
				return
			}
			this.selectedIds = this.filteredRows
				.map((row) => row.id ?? row?.['@self']?.id)
				.filter(Boolean)
		},
		/**
		 * Open a GL line's detail page.
		 *
		 * @param {object} row The clicked row.
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		onRowClick(row) {
			const id = row?.id ?? row?.['@self']?.id
			if (!id || !this.detailRoute || !this.$router) {
				return
			}
			this.$router.push({ name: this.detailRoute, params: { id: String(id) } }).catch(() => {})
		},
		/**
		 * Stable testid for a declared bulk action, taken from the first
		 * segment of its id (`link-to-programme` → `bbv-linker-bulk-link`).
		 *
		 * @param {object} action The manifest bulk-action descriptor.
		 * @return {string} The testid.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		bulkActionTestId(action) {
			const stem = String(action?.id || 'action').split('-')[0]
			return `bbv-linker-bulk-${stem}`
		},
		/**
		 * Whether a bulk action is disabled — `requiresSelection` actions
		 * stay disabled while nothing is selected (REQ-BBL-001.2).
		 *
		 * @param {object} action The manifest bulk-action descriptor.
		 * @return {boolean} True when disabled.
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		isBulkDisabled(action) {
			return action?.requiresSelection !== false && this.selectedIds.length === 0
		},
		/**
		 * Open a bulk action's declarative dialog.
		 *
		 * @param {object} action The manifest bulk-action descriptor.
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		openBulkDialog(action) {
			if (this.isBulkDisabled(action)) {
				return
			}
			this.dialogError = ''
			this.activeAction = action
		},
		/**
		 * Close the bulk dialog.
		 *
		 * @return {void}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		closeBulkDialog() {
			this.activeAction = null
			this.submitting = false
		},
		/**
		 * Write the assignment onto every selected GL line (REQ-BBL-001.3).
		 * Each row is saved individually so a partial failure is reported as
		 * such rather than silently dropping the successful writes; the
		 * dialog stays open on any failure so the operator sees which rows
		 * still need attention (REQ-BBL-001.4).
		 *
		 * @param {object} values The dialog's field values.
		 * @return {Promise<void>}
		 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
		 */
		async onBulkSubmit(values) {
			this.submitting = true
			this.dialogError = ''
			const selected = this.rows.filter((row) => this.selectedIds.includes(row.id))
			let failed = 0
			for (const row of selected) {
				try {
					await saveAssignment(this.register, this.schema, row, values)
				} catch (e) {
					failed += 1
				}
			}
			this.submitting = false
			if (failed > 0) {
				this.dialogError = this.t(
					'shillinq',
					'Linked {done} of {total} GL lines; {failed} had errors.',
					{ done: selected.length - failed, total: selected.length, failed },
				)
				await this.load()
				return
			}
			this.closeBulkDialog()
			this.selectedIds = []
			await this.load()
		},
	},
}
</script>

<style scoped>
.bbv-linker {
	width: 100%;
	min-height: 100%;
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	padding-inline-start: 56px;
	box-sizing: border-box;
}

.bbv-linker__header {
	display: flex;
	align-items: center;
	gap: 1rem;
	flex-wrap: wrap;
}

.bbv-linker__title {
	margin: 0;
}

.bbv-linker__badge {
	display: inline-flex;
	align-items: center;
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius-pill, 16px);
	font-weight: 600;
	font-size: 0.85em;
	background: var(--color-background-hover);
}

.bbv-linker__badge--red {
	background: var(--color-error);
	color: var(--color-primary-text);
}

.bbv-linker__badge--yellow {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.bbv-linker__badge--green {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.bbv-linker__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 1rem;
	margin: 1rem 0;
}

.bbv-linker__filter {
	display: inline-flex;
	flex-direction: column;
	gap: 0.25rem;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.bbv-linker__filter-control {
	min-width: 12rem;
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	border-radius: var(--border-radius);
	padding: 0.25rem 0.5rem;
}

.bbv-linker__bulk {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	margin-bottom: 0.75rem;
}

.bbv-linker__select-all {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.bbv-linker__bulk-button {
	border: 1px solid var(--color-border);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	padding: 0.25rem 0.75rem;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.bbv-linker__bulk-button:disabled {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
}

.bbv-linker__selection-count {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.bbv-linker__table {
	min-height: 8rem;
}

.bbv-linker__error {
	margin-top: 1rem;
	color: var(--color-error);
}
</style>
