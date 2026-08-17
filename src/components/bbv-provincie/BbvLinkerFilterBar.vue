<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Budget-to-Programme Linker filter bar (#866/#862, REQ-BBL-001).

 The Linker index declares three facets — Account type, Programme, Assignment
 status — under `pages[].config.filters[]`. **CnIndexPage has no `filters`
 prop.** Its filter concepts are `quickFilters` (a single tab strip) and a
 schema-derived facet sidebar, so that array rendered nowhere: the page was
 wired to the right schema, fetched the right rows, and showed no way to narrow
 them, for every visitor. This component is the renderer that array never had.

 ## It filters for real, and how

 It does NOT own a copy of the list. It writes the selection into `$route.query`
 and lets CnIndexPage's own self-fetch do the work: `useSelfFetchList` merges
 `resolveQueryFilters($route.query)` into `fixedFilters` on every fetch, and
 CnIndexPage watches `$route.query` deeply and re-fetches page 1 on a change.
 That is the library's documented deep-link path (`/cases?caseType=X`), so the
 rows on screen are the rows OpenRegister matched — there is no client-side
 filtering that could disagree with the row count or the pagination.

 ## Every facet resolves to a property GLLine actually DECLARES

 A filter on a non-property matches NOTHING, silently — OpenRegister's `filters`
 address the object's JSON properties, and an unknown key yields zero rows for
 every value with nothing logged. So the mapping matters more than the label:

 | facet                | GLLine query                          | why |
 |----------------------|---------------------------------------|-----|
 | `accountType`        | `accountNumber[]=<the type's numbers>` | `accountType` is a property of **Account**, not of GLLine. `/api/bbv-provincie/gl-line-facets` resolves the administration's chart of accounts to the account numbers per type, and those ARE a GL-line property. |
 | `programmeStructure` | `programmeStructure=<code>`           | declared by this change's own register fragment. |
 | `assignmentStatus`   | `programmeStructure[empty]=true|false` | "unmapped" is not a value, it is the ABSENCE of one. OpenRegister's search handler implements `empty` / `exists` / `null` operators; PHP parses the bracketed key back into `filters[programmeStructure][empty]`. |

 Programme and Assignment status address the SAME property, so selecting a
 programme clears the assignment-status facet (and vice versa) rather than
 emitting both: a scalar and an operator map on one key would collide in the
 query string, and "programme = water AND programme is empty" is unsatisfiable
 anyway.

 ## What this component does not do

 `config.bulkActions[]` (REQ-BBL-001 §2/§3) and `config.mappingStatus`
 (REQ-BBL-004) stay declared and unbuilt — see the page's `_note`.

 @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
-->
<template>
	<div class="bbv-linker-filters" data-testid="bbv-linker-filters">
		<label
			v-for="facet in facets"
			:key="facet.key"
			class="bbv-linker-filters__facet">
			<span class="bbv-linker-filters__label">{{ facet.label }}</span>
			<NcSelect
				:modelValue="selectedOption(facet)"
				:options="facet.options"
				:inputLabel="facet.label"
				:aria-label-combobox="facet.label"
				:clearable="false"
				:loading="loading"
				label="label"
				:data-testid="`bbv-linker-filter-${facet.key}`"
				@update:modelValue="onSelect(facet, $event)" />
		</label>
		<p
			v-if="error"
			class="bbv-linker-filters__error"
			role="status"
			data-testid="bbv-linker-filters-error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'

/** Manifest page id whose `config.filters[]` this bar renders. */
const PAGE_ID = 'BudgetToProgrammeLinker'

/** Facet key => the `gl-line-facets` payload key carrying its live values. */
const FACET_SOURCE = {
	accountType: 'accountTypes',
	programmeStructure: 'programmes',
	assignmentStatus: 'assignmentStatuses',
}

/**
 * Every route-query key this bar owns. Rebuilding the query from a cleared
 * copy of these — rather than deleting only the key that changed — is what
 * keeps Programme and Assignment status from both addressing
 * `programmeStructure` at once.
 */
const OWNED_QUERY_KEYS = [
	'accountNumber',
	'programmeStructure',
	'programmeStructure[empty]',
]

export default {
	name: 'BbvLinkerFilterBar',

	components: { NcSelect },

	inject: {
		/**
		 * The app manifest, provided by CnAppRoot as a reactive getter.
		 * shillinq lazy-loads a page's `config` on first navigation into its
		 * fragment and merges it into the SAME reactive object, and the router
		 * guard awaits that load before the route resolves — so by the time
		 * this component mounts the page's `config.filters[]` is present.
		 */
		cnManifest: { default: null },
	},

	data() {
		return {
			/** Live facet values keyed by payload key. */
			sources: { accountTypes: [], programmes: [], assignmentStatuses: [] },
			/** Account numbers per account type, for the accountType mapping. */
			accountNumbersByType: {},
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * The page's declared filters, each merged with its live options.
		 *
		 * The manifest is the declaration (label, order, which facets exist);
		 * the endpoint supplies the values. A facet the manifest does not
		 * declare is not rendered, and a declared facet with no live values
		 * falls back to its manifest `options[]` so the bar still renders on a
		 * fresh instance.
		 *
		 * @return {Array<{key: string, label: string, options: Array}>} The facets.
		 */
		facets() {
			const page = (this.cnManifest?.pages || []).find(
				(p) => p && p.id === PAGE_ID,
			)
			const declared = page?.config?.filters
			if (!Array.isArray(declared)) {
				return []
			}

			return declared
				.filter((f) => f && typeof f.key === 'string' && FACET_SOURCE[f.key])
				.map((f) => ({
					key: f.key,
					label: t('shillinq', f.label || f.key),
					options: [this.allOption(f), ...this.liveOptions(f)],
				}))
		},
	},

	mounted() {
		this.loadFacets()
	},

	methods: {
		t,

		/**
		 * Fetch the live facet values for the caller's administrations.
		 *
		 * A failure leaves the manifest's static `options[]` in place and says
		 * so, rather than rendering an empty bar that looks like "this
		 * administration has no accounts".
		 *
		 * @return {Promise<void>} Resolves once the facets are loaded or the failure is shown.
		 */
		async loadFacets() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/shillinq/api/bbv-provincie/gl-line-facets'),
				)
				this.sources = {
					accountTypes: Array.isArray(data?.accountTypes)
						? data.accountTypes
						: [],

					programmes: Array.isArray(data?.programmes)
						? data.programmes
						: [],

					assignmentStatuses: Array.isArray(data?.assignmentStatuses)
						? data.assignmentStatuses
						: [],
				}
				const byType = {}
				for (const entry of this.sources.accountTypes) {
					byType[entry.value] = Array.isArray(entry.accountNumbers)
						? entry.accountNumbers
						: []
				}
				this.accountNumbersByType = byType
				this.error = ''
			} catch {
				this.error = t(
					'shillinq',
					'Could not load the filter values; showing the declared options only.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * The leading "All …" option, which clears the facet.
		 *
		 * @param {object} facet The declared facet.
		 * @return {{value: string, label: string}} The option.
		 */
		allOption(facet) {
			return {
				value: '',
				label:
					t('shillinq', 'All')
					+ ' — '
					+ t('shillinq', facet.label || facet.key),
			}
		},

		/**
		 * Live options for a facet, falling back to the manifest's static list.
		 *
		 * @param {object} facet The declared facet.
		 * @return {Array<{value: string, label: string}>} The options.
		 */
		liveOptions(facet) {
			const live = this.sources[FACET_SOURCE[facet.key]] || []
			if (live.length > 0) {
				return live.map((o) => ({
					value: String(o.value),
					label: o.label || String(o.value),
				}))
			}

			return (facet.options || []).map((o) => {
				const value = o && typeof o === 'object' ? o.value : o
				const label =
					o && typeof o === 'object' && o.label ? o.label : String(value)
				return { value: String(value), label }
			})
		},

		/**
		 * The option currently selected for a facet, read back out of the route
		 * query so a deep link and a reload both show the active filter.
		 *
		 * @param {object} facet The facet.
		 * @return {object|null} The option, or null.
		 */
		selectedOption(facet) {
			const query = this.$route?.query || {}
			let value = ''
			if (facet.key === 'accountType') {
				value = this.accountTypeFromQuery(query)
			} else if (facet.key === 'programmeStructure') {
				value = String(query.programmeStructure ?? '')
			} else if (facet.key === 'assignmentStatus') {
				const empty = query['programmeStructure[empty]']
				if (empty === 'true') value = 'unmapped'
				else if (empty === 'false') value = 'mapped'
			}

			const known = facet.options.find((o) => o.value === value)
			if (known) {
				return known
			}

			// The URL carries a value this facet does not offer — a programme
			// the facet endpoint did not list, or one saved before the chart of
			// accounts changed. Falling back to "All" here would show a control
			// that DISAGREES with the list on screen: the rows really are
			// filtered, and the bar would claim they are not. Surface the
			// active value instead, so the two always tell the same story.
			if (value !== '') {
				return { value, label: value }
			}

			return facet.options[0] || null
		},

		/**
		 * Recover which account type an `accountNumber[]` filter represents.
		 *
		 * @param {object} query The route query.
		 * @return {string} The account type, or '' when none is active.
		 */
		accountTypeFromQuery(query) {
			const raw = query.accountNumber
			if (raw === undefined || raw === null || raw === '') {
				return ''
			}

			const active = Array.isArray(raw) ? raw.map(String) : [String(raw)]
			for (const [type, numbers] of Object.entries(
				this.accountNumbersByType,
			)) {
				if (
					numbers.length === active.length
					&& numbers.every((n) => active.includes(String(n)))
				) {
					return type
				}
			}

			return ''
		},

		/**
		 * Apply a facet selection by rewriting the route query.
		 *
		 * @param {object} facet The facet that changed.
		 * @param {object|null} option The chosen option.
		 * @return {void}
		 */
		onSelect(facet, option) {
			const value =
				option && typeof option === 'object'
					? String(option.value ?? '')
					: String(option ?? '')
			const query = { ...(this.$route?.query || {}) }

			// Read the OTHER facets' current values before clearing, so a
			// change to one facet preserves the rest.
			const keep = {
				accountType:
					facet.key === 'accountType'
						? value
						: this.accountTypeFromQuery(query),

				programmeStructure:
					facet.key === 'programmeStructure'
						? value
						: String(query.programmeStructure ?? ''),

				assignmentStatus:
					facet.key === 'assignmentStatus'
						? value
						: this.assignmentStatusFromQuery(query),
			}

			// Programme and Assignment status address the same property; the
			// one just changed wins and the other is dropped.
			if (facet.key === 'programmeStructure' && value !== '') {
				keep.assignmentStatus = ''
			}

			if (facet.key === 'assignmentStatus' && value !== '') {
				keep.programmeStructure = ''
			}

			for (const key of OWNED_QUERY_KEYS) {
				delete query[key]
			}

			const numbers = this.accountNumbersByType[keep.accountType] || []
			if (keep.accountType !== '' && numbers.length > 0) {
				query.accountNumber = numbers.map(String)
			}

			if (keep.programmeStructure !== '') {
				query.programmeStructure = keep.programmeStructure
			}

			if (keep.assignmentStatus === 'unmapped') {
				query['programmeStructure[empty]'] = 'true'
			} else if (keep.assignmentStatus === 'mapped') {
				query['programmeStructure[empty]'] = 'false'
			}

			this.$router.replace({ query }).catch(() => {})
		},

		/**
		 * Recover the assignment-status selection from the route query.
		 *
		 * @param {object} query The route query.
		 * @return {string} `mapped`, `unmapped`, or ''.
		 */
		assignmentStatusFromQuery(query) {
			const empty = query['programmeStructure[empty]']
			if (empty === 'true') return 'unmapped'
			if (empty === 'false') return 'mapped'
			return ''
		},
	},
}
</script>

<style scoped>
.bbv-linker-filters {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 12px;
	padding-block: 8px;
}

.bbv-linker-filters__facet {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 200px;
}

.bbv-linker-filters__label {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.bbv-linker-filters__error {
	flex-basis: 100%;
	color: var(--color-warning-text, var(--color-main-text));
}
</style>
