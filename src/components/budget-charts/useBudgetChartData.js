// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Fetch-once-per-page-visit data layer for budget-charts (REQ-BCH-008),
// modelled directly on
// src/components/dashboard/financial/useFinancialData.js's own shape:
// module-scoped refs, an in-flight-promise guard, load()/reload(). The
// FIRST BudgetTrendChart a user opens on a page (BudgetGrid row, or the
// ChartOfAccountsDetail sidebar tab) triggers ONE
// GET /apps/shillinq/api/budget-charts/series request for the whole
// administration + period range; every subsequent chart-open on the SAME
// page, for the SAME (administrationId, from, to, annualBudgetId) key,
// reuses the already-resolved cache — zero additional network requests
// (design.md §8b).
//
// @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { ref } from 'vue'

const loading = ref(false)
const error = ref(null)
const data = ref(null)
let cacheKey = null
let inflight = null

/**
 * Build the cache key a (administrationId, from, to, annualBudgetId)
 * combination resolves to — reloading with a DIFFERENT key invalidates the
 * cache and refetches once (design.md §8b).
 *
 * @param {string} administrationId The administration id.
 * @param {string} from The first `YYYY-MM` month, inclusive.
 * @param {string} to The last `YYYY-MM` month, inclusive.
 * @param {string|null} annualBudgetId Optional AnnualBudget override.
 * @return {string} The cache key.
 */
function buildKey(administrationId, from, to, annualBudgetId) {
	return [administrationId, from, to, annualBudgetId || ''].join('::')
}

/**
 * Fetch the chart-series payload for one (administrationId, from, to,
 * annualBudgetId) key.
 *
 * @param {string} administrationId The administration id.
 * @param {string} from The first `YYYY-MM` month, inclusive.
 * @param {string} to The last `YYYY-MM` month, inclusive.
 * @param {string|null} annualBudgetId Optional AnnualBudget override.
 * @return {Promise<object>} `{ months, accounts, ledgerGroups }`.
 */
async function fetchSeries(administrationId, from, to, annualBudgetId) {
	const params = { administrationId, from, to }
	if (annualBudgetId) {
		params.annualBudgetId = annualBudgetId
	}
	const response = await axios.get(
		generateUrl('/apps/shillinq/api/budget-charts/series'),
		{ params },
	)
	return response.data
}

/**
 * Shared budget-chart data. Returns module-scoped refs; the first caller
 * for a given key triggers the fetch, later callers for the SAME key
 * piggyback on it. A DIFFERENT key drops the cache and refetches.
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
 * @param {string} administrationId The administration id.
 * @param {{from: string, to: string}} range The `YYYY-MM` period range.
 * @param {string|null} [annualBudgetId] Optional AnnualBudget override.
 * @return {{ loading: import('vue').Ref<boolean>, error: import('vue').Ref<Error|null>,
 *   data: import('vue').Ref<object|null>, load: () => Promise<object>, reload: () => Promise<object> }}
 */
export function useBudgetChartData(administrationId, range, annualBudgetId = null) {
	const key = buildKey(administrationId, range?.from, range?.to, annualBudgetId)

	/**
	 * Trigger the fetch for THIS key when no in-flight/cached request for
	 * it exists yet; a stale key (a previous chart's range) drops the old
	 * cache first.
	 *
	 * @return {Promise<object>}
	 */
	function load() {
		if (cacheKey !== key) {
			inflight = null
			data.value = null
			cacheKey = key
		}

		if (!inflight) {
			loading.value = true
			error.value = null
			inflight = fetchSeries(
				administrationId,
				range?.from,
				range?.to,
				annualBudgetId,
			)
				.then((result) => {
					data.value = result
				})
				.catch((e) => {
					error.value = e
				})
				.finally(() => {
					loading.value = false
				})
		}
		return inflight
	}

	/**
	 * Drop the cache for THIS key and refetch.
	 *
	 * @return {Promise<object>}
	 */
	function reload() {
		cacheKey = null
		return load()
	}

	return { loading, error, data, load, reload }
}

/**
 * Test hook: drop the module cache and state.
 *
 * @spec exclude test-only reset helper, no runtime behaviour
 * @return {void}
 */
export function resetBudgetChartData() {
	cacheKey = null
	inflight = null
	loading.value = false
	error.value = null
	data.value = null
}
