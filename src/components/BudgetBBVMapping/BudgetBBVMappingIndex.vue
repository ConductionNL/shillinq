<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Budget BBV Mapping index page (slice 06 of
 bookkeeping-waterschappen-bbv-variant).

 Renders the list of `BudgetBBVMapping` records served by the
 OpenRegister object endpoint (`/apps/openregister/api/objects/
 shillinq/BudgetBBVMapping`). The page wraps `CnIndexPage` from the
 shared `@conduction/nextcloud-vue` library so columns, sort, paging,
 empty state and the Add-button affordance are all platform-rendered.

 Columns (REQ-BBVW-004 index): GL Account, Programme, Allocation %,
 Effective From, Effective To, Status. Two facet groups expose the
 fiscal-year + allocation-range + effective-date-range filters the
 spec calls for; a single search input filters by GL account number
 OR programme code with a 250 ms debounce.

 Navigation:
   * Add button → router-push to BudgetBBVMappingDetail with id=new
     (slice 07 builds the detail form; until then the manifest detail
     page renders the schema-driven default).
   * Row click → router-push to BudgetBBVMappingDetail with id=<uuid>.

 Backing store: `useBudgetBBVMappingStore` (slice 06's
 `createObjectStore('budget-bbv-mapping', { plugins: [relations,
 auditTrails] })`). The store handles the OR fetch + cache + pagination;
 this component only orchestrates filters + nav.

 Registered in src/registry.js as a kind:"page" custom component so the
 manifest router can dispatch `component: "BudgetBBVMappingIndex"`
 once slice 04's manifest fragment swaps the `BudgetBBVMappings` page
 from `type: index` to `type: custom`. ADR-036 / ADR-037.

 @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
-->
<template>
	<div class="bbv-mapping-index" data-testid="bbv-mapping-index">
		<!--
			🔴 `:empty-title`, `:empty-action-label` and `@empty-action` below are
			LEFT KEBAB-CASED ON PURPOSE — do not "fix" them to camelCase to clear
			`vue/attribute-hyphenation` / `vue/v-on-event-hyphenation`.

			None of the three is part of CnIndexPage's API: nc-vue 2.3.0 declares
			`emptyText` (default the untranslated string `'No items found'`) and
			its `emits` list has no `empty-action`. So all three are INERT — the
			two attributes fall through onto the root DOM element and the listener
			is never called. Renaming them to camelCase changes the rendered DOM
			attribute names and leaves them exactly as inert, which is why the
			rewrite was withheld rather than applied.

			The real defect is a BEHAVIOUR one: this index shows CnIndexPage's
			untranslated default empty text, and its empty-state action does
			nothing. Fixing it means `:empty-text` plus an `#empty` slot (or an
			nc-vue change), and it needs verifying through the UI — it is not a
			lint fix and is deliberately out of this change's scope.
		-->
		<CnIndexPage
			:title="t('shillinq', 'Budget Mapping')"
			:description="
				t(
					'shillinq',
					'Allocations of GL accounts to BBV programmes (REQ-BBVW-002 / REQ-BBVW-004).',
				)
			"
			:objects="filteredObjects"
			:loading="loading"
			:pagination="pagination"
			:includeColumns="visibleColumns"
			:columnOverrides="columnOverrides"
			:emptyTitle="t('shillinq', 'No mappings recorded yet')"
			:emptyActionLabel="t('shillinq', 'Add mapping')"
			:addLabel="t('shillinq', 'Add mapping')"
			:rowKey="rowKey"
			data-testid="bbv-mapping-index-page"
			@add="onAdd"
			@emptyAction="onAdd"
			@refresh="loadMappings"
			@rowClick="onRowClick"
			@pageChanged="onPageChange">
			<!--
				`below-header`, NOT `header-actions`. CnIndexPage 2.2.0-vue3.2
				declares below-header / mass-actions / action-items / actions /
				import-fields / form-fields / empty / column-* / row-actions /
				list-item / row-icon / row-badges / card — there is no
				`header-actions` slot on it. Vue drops unmatched slot content
				SILENTLY, so this whole filter block (search, fiscal year,
				allocation, effective-from range) rendered nowhere while the
				surrounding page looked healthy. The spelling was borrowed from
				CnDashboardPage, which DOES have `header-actions` — see
				BBVComplianceDashboard.vue, where the same template name works.
			-->
			<template #below-header>
				<div
					class="bbv-mapping-index__filters"
					data-testid="bbv-mapping-index-filters">
					<span
						v-if="scope.fiscalYear"
						class="bbv-mapping-index__fy"
						data-testid="bbv-mapping-fy-label">
						{{ fyLabel }}
					</span>
					<input
						v-model="searchTerm"
						type="search"
						class="bbv-mapping-index__search"
						data-testid="bbv-mapping-search"
						:aria-label="
							t('shillinq', 'Search by GL account or programme code')
						"
						:placeholder="t('shillinq', 'Search account or programme…')"
						@input="onSearchInput" />
					<select
						v-model="fiscalYearFilter"
						class="bbv-mapping-index__filter"
						data-testid="bbv-mapping-fiscal-year"
						:aria-label="t('shillinq', 'Fiscal year')">
						<option value="">
							{{ t('shillinq', 'All fiscal years') }}
						</option>
						<option
							v-for="year in fiscalYearOptions"
							:key="year"
							:value="year">
							{{ year }}
						</option>
					</select>
					<select
						v-model="allocationBucket"
						class="bbv-mapping-index__filter"
						data-testid="bbv-mapping-allocation"
						:aria-label="t('shillinq', 'Allocation range')">
						<option value="">
							{{ t('shillinq', 'All allocations') }}
						</option>
						<option value="0-25">0-25 %</option>
						<option value="25-50">25-50 %</option>
						<option value="50-75">50-75 %</option>
						<option value="75-100">75-100 %</option>
					</select>
					<!-- The wrapping <label> is a valid IMPLICIT association, but
					     it is the only control pair on this page that relies on
					     it, and hydra gate-40 (form-label-association) requires
					     an explicit one. The `aria-label` text is deliberately
					     IDENTICAL to the visible label text: an accessible name
					     that differs from the visible text overrides it for
					     screen-reader and voice-control users and breaks WCAG
					     2.5.3 Label in Name. Keep the two strings in step. -->
					<label class="bbv-mapping-index__date-label">
						{{ t('shillinq', 'Effective on or after') }}
						<input
							v-model="effectiveFromAfter"
							type="date"
							class="bbv-mapping-index__date"
							data-testid="bbv-mapping-effective-from-after"
							:aria-label="t('shillinq', 'Effective on or after')" />
					</label>
					<label class="bbv-mapping-index__date-label">
						{{ t('shillinq', 'Effective on or before') }}
						<input
							v-model="effectiveFromBefore"
							type="date"
							class="bbv-mapping-index__date"
							data-testid="bbv-mapping-effective-from-before"
							:aria-label="t('shillinq', 'Effective on or before')" />
					</label>
				</div>
			</template>

			<template #column-allocationPercentage="{ row }">
				<span
					class="bbv-mapping-index__allocation"
					:data-testid="`bbv-allocation-${row.id}`">
					{{ formatPercentage(row.allocationPercentage) }}
				</span>
			</template>

			<template #column-effectiveFrom="{ row }">
				<span :data-testid="`bbv-eff-from-${row.id}`">
					{{ formatDate(row.effectiveFrom) }}
				</span>
			</template>

			<template #column-effectiveTo="{ row }">
				<span :data-testid="`bbv-eff-to-${row.id}`">
					{{ formatDate(row.effectiveTo) }}
				</span>
			</template>

			<template #column-lifecycleState="{ row }">
				<span
					class="bbv-mapping-index__status"
					:class="`bbv-mapping-index__status--${statusKey(row)}`"
					:data-testid="`bbv-status-${row.id}`">
					{{ statusLabel(row) }}
				</span>
			</template>
		</CnIndexPage>

		<p
			v-if="error"
			class="bbv-mapping-index__error"
			data-testid="bbv-mapping-index-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { useBudgetBBVMappingStore } from '../../store/modules/budgetBBVMappingStore.js'

const SEARCH_DEBOUNCE_MS = 250
const TYPE_SLUG = 'budgetBBVMapping'

export default {
	name: 'BudgetBBVMappingIndex',
	components: {
		CnIndexPage,
	},

	setup() {
		const store = useBudgetBBVMappingStore()
		return { store }
	},

	data() {
		return {
			objects: [],
			pagination: null,
			loading: true,
			error: '',
			searchTerm: '',
			searchTermApplied: '',
			fiscalYearFilter: '',
			allocationBucket: '',
			effectiveFromAfter: '',
			effectiveFromBefore: '',
			rowKey: 'id',
			searchDebounce: null,
			scope: {
				administrationId: null,
				fiscalYear: null,
				startDate: null,
				endDate: null,
			},

			// Live-updates handle for the
			// or-collection-{register-slug}-{schema-slug} subscription of
			// the budgetBBVMapping collection (nc-vue beta.212,
			// liveUpdatesPlugin default-on). Managed by
			// syncLiveSubscription(); livePending marks an in-flight
			// subscribe so a concurrent call doesn't double-subscribe;
			// liveEpoch invalidates in-flight resolutions after a release
			// (destroy).
			liveHandle: null,
			livePending: false,
			liveEpoch: 0,
			liveUnwatch: null,
		}
	},

	computed: {
		/**
		 * Columns rendered by CnIndexPage, in render order (REQ-BBVW-004).
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return [
				'glAccountNumber',
				'programmeCode',
				'allocationPercentage',
				'effectiveFrom',
				'effectiveTo',
				'lifecycleState',
			]
		},

		/**
		 * Per-column labels + flags. Pass through to CnIndexPage so the
		 * BBV-specific spec language ("Programme", "Allocation %") wins
		 * over the schema's `glAccountNumber`-style raw field names.
		 *
		 * @return {object} The CnIndexPage columnOverrides map.
		 */
		columnOverrides() {
			return {
				glAccountNumber: {
					label: this.t('shillinq', 'GL Account'),
					sortable: true,
				},

				programmeCode: {
					label: this.t('shillinq', 'Programme'),
					sortable: true,
				},

				allocationPercentage: {
					label: this.t('shillinq', 'Allocation %'),
					sortable: true,
				},

				effectiveFrom: {
					label: this.t('shillinq', 'Effective From'),
					sortable: true,
				},

				effectiveTo: {
					label: this.t('shillinq', 'Effective To'),
					sortable: true,
				},

				lifecycleState: {
					label: this.t('shillinq', 'Status'),
					sortable: true,
				},
			}
		},

		/**
		 * Fiscal-year option list — three years either side of "now" so the
		 * dropdown stays bounded without a server round-trip.
		 *
		 * @return {Array<number>} Descending year list.
		 */
		fiscalYearOptions() {
			const now = new Date().getFullYear()
			const list = []
			for (let y = now + 1; y >= now - 5; y -= 1) {
				list.push(y)
			}
			return list
		},

		/**
		 * Server-derived "FY YYYY" label so the index header always reflects
		 * the active fiscal year inherited from the Administration context
		 * (REQ-BBVW-006). Empty when the user has no accessible administration.
		 *
		 * @return {string} The fiscal year label, e.g. "FY 2026".
		 */
		fyLabel() {
			if (!this.scope.fiscalYear) {
				return ''
			}
			return this.t('shillinq', 'FY {year}', { year: this.scope.fiscalYear })
		},

		/**
		 * In-memory filter pipeline. Server-side scoping by administration
		 * arrives in slice 09; until then the index reads the fiscal-year
		 * partition the user requests via the dropdown and applies the
		 * search / allocation / effective-date facets client-side. The row
		 * count is bounded by the active programme catalogue (~50–200
		 * mappings per administration) so an in-memory pass is well-sized.
		 *
		 * @return {Array<object>} The filtered objects.
		 */
		filteredObjects() {
			const term = (this.searchTermApplied || '').trim().toLowerCase()
			const after = this.effectiveFromAfter
				? new Date(this.effectiveFromAfter)
				: null
			const before = this.effectiveFromBefore
				? new Date(this.effectiveFromBefore)
				: null
			const allocation = this.parseAllocationBucket(this.allocationBucket)
			const yearFilter = this.fiscalYearFilter
				? Number(this.fiscalYearFilter)
				: null

			return (this.objects || []).filter((row) => {
				if (term) {
					const account = String(row.glAccountNumber || '').toLowerCase()
					const programme = String(row.programmeCode || '').toLowerCase()
					if (!account.includes(term) && !programme.includes(term)) {
						return false
					}
				}
				if (yearFilter !== null) {
					const startYear = row.effectiveFrom
						? new Date(row.effectiveFrom).getFullYear()
						: null
					const endYear = row.effectiveTo
						? new Date(row.effectiveTo).getFullYear()
						: null
					// A mapping matches the fiscal year if its effective
					// window overlaps Jan 1 → Dec 31 of that year.
					if (startYear !== null && startYear > yearFilter) {
						return false
					}
					if (endYear !== null && endYear < yearFilter) {
						return false
					}
				}
				if (allocation) {
					const value = Number(row.allocationPercentage)
					if (Number.isNaN(value)) {
						return false
					}
					if (value < allocation.min || value > allocation.max) {
						return false
					}
				}
				if (after || before) {
					const eff = row.effectiveFrom
						? new Date(row.effectiveFrom)
						: null
					if (!eff) {
						return false
					}
					if (after && eff < after) {
						return false
					}
					if (before && eff > before) {
						return false
					}
				}
				return true
			})
		},
	},

	async created() {
		await this.loadScope()
		await this.loadMappings()
		this.syncLiveSubscription()
	},

	beforeUnmount() {
		if (this.searchDebounce) {
			clearTimeout(this.searchDebounce)
		}
		this.releaseLiveSubscription()
	},

	methods: {
		/**
		 * Subscribe to live updates for the budgetBBVMapping collection
		 * (or-collection-shillinq-budget-bbv-mapping). Events are refetch
		 * hints only: the liveUpdatesPlugin re-runs fetchCollection with
		 * the last-used params (current page preserved), so the store's
		 * collection cache refreshes; the watcher installed here bridges
		 * the fresh rows + pagination into this view's local copies.
		 * Idempotent (single-shot per mount — the type is fixed). Uses
		 * notify_push when available, visibility-gated polling otherwise.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		async syncLiveSubscription() {
			if (
				typeof this.store.subscribe !== 'function'
				|| this.liveHandle
				|| this.livePending
			) {
				return
			}
			this.livePending = true
			const epoch = this.liveEpoch
			try {
				const handle = await this.store.subscribe(TYPE_SLUG)
				this.livePending = false
				if (this.liveEpoch !== epoch) {
					// Released while awaiting (component destroyed) — drop the
					// now-stale subscription instead of leaking it.
					this.store.unsubscribe(handle)
					return
				}
				this.liveHandle = handle
				// Bridge: event → plugin refetch → store collection cache →
				// local rows/pagination (which the table renders from).
				this.liveUnwatch = this.$watch(
					() => this.store.getCollection(TYPE_SLUG),
					(fresh) => {
						if (Array.isArray(fresh) && this.liveHandle) {
							this.objects = fresh
							this.pagination =
								this.store.pagination?.[TYPE_SLUG] || null
						}
					},
				)
			} catch (e) {
				this.livePending = false
				this.liveHandle = null
				// eslint-disable-next-line no-console
				console.warn(
					'[BudgetBBVMappingIndex] live subscription failed:',
					e?.message ?? e,
				)
			}
		},

		/**
		 * Release the live collection subscription and its cache watcher,
		 * and invalidate any in-flight subscribe (its resolution
		 * unsubscribes itself via the epoch check).
		 *
		 * @return {void}
		 * @spec openspec/specs/realtime-updates/spec.md
		 */
		releaseLiveSubscription() {
			this.liveEpoch += 1
			this.livePending = false
			if (this.liveUnwatch) {
				this.liveUnwatch()
				this.liveUnwatch = null
			}
			if (this.liveHandle && typeof this.store.unsubscribe === 'function') {
				this.store.unsubscribe(this.liveHandle)
			}
			this.liveHandle = null
		},

		/**
		 * Load the active administration + fiscal-year scope from the
		 * slice-04 envelope so the page header surfaces "FY YYYY" and the
		 * filter dropdown pre-selects the active year (REQ-BBVW-006).
		 *
		 * Errors are silently absorbed — the page still renders without the
		 * server-derived defaults; the user can manually pick a year.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
		 */
		async loadScope() {
			try {
				// Lazy-require here so the index works in unit tests that mock
				// axios at the module level; the dashboard module also pulls
				// from axios via the same generateUrl helper.
				const axios = (await import('@nextcloud/axios')).default
				const { generateUrl } = await import('@nextcloud/router')
				// `/api/` prefix is load-bearing — see appinfo/routes.php: the
				// un-prefixed path is this component's OWN SPA page route, and
				// registering the JSON endpoint there made the page unreachable
				// in a browser (Access forbidden — CSRF check failed).
				const response = await axios.get(
					generateUrl('/apps/shillinq/api/budget-mappings'),
				)
				const data = response?.data?.scope || {}
				this.scope = {
					administrationId: data.administrationId || null,
					fiscalYear: data.fiscalYear || null,
					startDate: data.startDate || null,
					endDate: data.endDate || null,
				}
				if (this.scope.fiscalYear && !this.fiscalYearFilter) {
					this.fiscalYearFilter = this.scope.fiscalYear
				}
			} catch (e) {
				// Scope is a nice-to-have; failure does not block the index.
				this.scope = {
					administrationId: null,
					fiscalYear: null,
					startDate: null,
					endDate: null,
				}
			}
		},

		/**
		 * Load BudgetBBVMapping objects from the OpenRegister API via the
		 * slice-06 store. Errors surface as the inline `error` banner so the
		 * page still renders the filter chrome.
		 *
		 * @return {Promise<void>}
		 */
		async loadMappings() {
			this.loading = true
			this.error = ''
			try {
				const params = { _limit: 200 }
				const result = await this.store.fetchCollection(TYPE_SLUG, params)
				const rows = Array.isArray(result?.results)
					? result.results
					: Array.isArray(result)
						? result
						: []
				this.objects = rows
				this.pagination = this.store.pagination?.[TYPE_SLUG] || null
				const storeError = this.store.errors?.[TYPE_SLUG]
				if (storeError) {
					this.error =
						storeError.message
						|| this.t('shillinq', 'Failed to load budget mappings')
				}
			} catch (e) {
				this.objects = []
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load budget mappings')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Debounce the search term so type-into-the-search-box does not
		 * trigger a re-filter on every keystroke.
		 */
		onSearchInput() {
			if (this.searchDebounce) {
				clearTimeout(this.searchDebounce)
			}
			this.searchDebounce = setTimeout(() => {
				this.searchTermApplied = this.searchTerm
				this.searchDebounce = null
			}, SEARCH_DEBOUNCE_MS)
		},

		/**
		 * Navigate to the detail view in create mode (id=new). Slice 07
		 * builds the bespoke detail Vue; until then the manifest detail
		 * page renders the schema-driven default form.
		 */
		onAdd() {
			this.$router.push({
				name: 'BudgetBBVMappingDetail',
				params: { id: 'new' },
			})
		},

		/**
		 * Navigate to a mapping's detail view.
		 *
		 * @param {object} row The clicked row.
		 */
		onRowClick(row) {
			if (!row || !row.id) {
				return
			}
			this.$router.push({
				name: 'BudgetBBVMappingDetail',
				params: { id: String(row.id) },
			})
		},

		/**
		 * Pagination change handler — re-fetch via the store. The OR
		 * endpoint paginates; CnIndexPage emits 1-based page numbers.
		 *
		 * @param {number} page The new page number.
		 * @return {Promise<void>}
		 */
		async onPageChange(page) {
			this.loading = true
			try {
				const result = await this.store.fetchCollection(TYPE_SLUG, {
					_page: page,
					_limit: this.pagination?.limit || 200,
				})
				const rows = Array.isArray(result?.results)
					? result.results
					: Array.isArray(result)
						? result
						: []
				this.objects = rows
				this.pagination = this.store.pagination?.[TYPE_SLUG] || null
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('shillinq', 'Failed to load budget mappings')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Parse the allocation-bucket select value into a {min,max} range.
		 *
		 * @param {string} value The dropdown value (e.g. "25-50").
		 * @return {?{min:number,max:number}} The range, or null when empty.
		 */
		parseAllocationBucket(value) {
			if (!value) {
				return null
			}
			const parts = String(value).split('-')
			if (parts.length !== 2) {
				return null
			}
			const min = Number(parts[0])
			const max = Number(parts[1])
			if (Number.isNaN(min) || Number.isNaN(max)) {
				return null
			}
			return { min, max }
		},

		/**
		 * Lifecycle-status palette key.
		 *
		 * @param {object} row The row.
		 * @return {string} The CSS modifier suffix.
		 */
		statusKey(row) {
			const value = String(row.lifecycleState || '').toLowerCase()
			if (!value) {
				return 'unknown'
			}
			return value.replace(/[^a-z0-9_-]/g, '-')
		},

		/**
		 * Localised lifecycle-status label. Falls back to the raw value
		 * when the state is not in the known palette (e.g. a future state
		 * introduced by a downstream slice).
		 *
		 * @param {object} row The row.
		 * @return {string} The label.
		 */
		statusLabel(row) {
			const value = String(row.lifecycleState || '').toLowerCase()
			const labels = {
				draft: this.t('shillinq', 'Draft'),
				active: this.t('shillinq', 'Active'),
				expired: this.t('shillinq', 'Expired'),
				archived: this.t('shillinq', 'Archived'),
			}
			return labels[value] || row.lifecycleState || '—'
		},

		/**
		 * Format an allocation percentage as "n %".
		 *
		 * @param {number|string|null} value The raw allocation %.
		 * @return {string} The formatted value.
		 */
		formatPercentage(value) {
			if (value === null || value === undefined || value === '') {
				return '—'
			}
			const num = Number(value)
			if (Number.isNaN(num)) {
				return '—'
			}
			return `${num} %`
		},

		/**
		 * Format an ISO-8601 date for display. Empty / null values render
		 * as an en-dash so the column reads cleanly.
		 *
		 * @param {?string} iso The ISO-8601 date string.
		 * @return {string} The localised date.
		 */
		formatDate(iso) {
			if (!iso) {
				return '—'
			}
			try {
				return new Date(iso).toLocaleDateString()
			} catch (e) {
				return iso
			}
		},
	},
}
</script>

<style scoped>
.bbv-mapping-index {
	width: 100%;
	min-height: 100%;
}

.bbv-mapping-index__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
}

.bbv-mapping-index__search,
.bbv-mapping-index__filter,
.bbv-mapping-index__date {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	color: var(--color-main-text);
	padding: 0.25rem 0.5rem;
	border-radius: var(--border-radius);
}

.bbv-mapping-index__search {
	min-width: 14rem;
}

.bbv-mapping-index__date-label {
	display: inline-flex;
	flex-direction: column;
	font-size: 0.78em;
	color: var(--color-text-maxcontrast);
}

.bbv-mapping-index__allocation {
	font-variant-numeric: tabular-nums;
}

.bbv-mapping-index__status {
	display: inline-block;
	padding: 0.125rem 0.5rem;
	border-radius: var(--border-radius-pill);
	font-size: 0.85em;
	background: var(--color-background-hover);
}

.bbv-mapping-index__status--active {
	background: var(--color-success);
	color: var(--color-primary-text);
}

.bbv-mapping-index__status--draft {
	background: var(--color-warning);
	color: var(--color-primary-text);
}

.bbv-mapping-index__status--expired,
.bbv-mapping-index__status--archived {
	background: var(--color-background-darker);
	color: var(--color-text-lighter);
}

.bbv-mapping-index__error {
	margin: 1rem;
	padding: 0.75rem 1rem;
	background: var(--color-background-hover);
	color: var(--color-error);
	border-radius: var(--border-radius);
}

.bbv-mapping-index__fy {
	display: inline-flex;
	align-items: center;
	padding: 0.25rem 0.5rem;
	margin-right: 0.5rem;
	border-radius: var(--border-radius);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: 600;
	font-size: var(--default-font-size, 0.875rem);
}
</style>
