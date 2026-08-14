// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Fetch-once data layer for the Financial overview dashboard. All
// seven widgets share the same module-scoped reactive state, so a
// dashboard page load issues exactly one request per schema no
// matter how many widgets mount.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { ref } from 'vue'

const REGISTER_SLUG = 'shillinq'
const PAGE_LIMIT = 2000

/** Schemas the dashboard consumes, keyed by the name widgets use. */
const SCHEMAS = {
	accounts: 'Account',
	transactions: 'GLTransaction',
	lines: 'GLLine',
	arInvoices: 'ARInvoice',
	apTransactions: 'APTransaction',
	hourEntries: 'UrenRegistratie',
	cashflowWeeks: 'CashflowWeek',
	customers: 'CustomerMaster',
	vendors: 'Payee',
}

const loading = ref(false)
const error = ref(null)
const data = ref(null)
let inflight = null

/**
 * Fetch one schema's objects from the OpenRegister objects API.
 * A failing schema resolves to an empty list (and records the
 * error) so one missing schema cannot blank the whole dashboard.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @param {string} schema Schema slug.
 * @return {Promise<object[]>}
 */
async function fetchSchema(schema) {
	const response = await axios.get(
		generateUrl(`/apps/openregister/api/objects/${REGISTER_SLUG}/${schema}`),
		{ params: { _limit: PAGE_LIMIT } },
	)
	const rows = response.data?.results ?? response.data?.objects ?? []
	return Array.isArray(rows) ? rows : []
}

/** @return {Promise<object>} */
async function fetchAll() {
	const entries = Object.entries(SCHEMAS)
	const settled = await Promise.all(
		entries.map(async ([key, schema]) => {
			try {
				return [key, await fetchSchema(schema)]
			} catch (e) {
				error.value = e
				return [key, []]
			}
		}),
	)
	return Object.fromEntries(settled)
}

/**
 * Shared dashboard data. Returns module-scoped refs; the first
 * caller triggers the fetch, later callers piggyback on it.
 * `reload()` drops the cache and refetches into the same refs, so
 * every mounted widget updates.
 *
 * @spec openspec/specs/financial-dashboard-graphs/spec.md
 * @return {{ loading: import('vue').Ref<boolean>, error: import('vue').Ref<Error|null>,
 *   data: import('vue').Ref<object|null>, load: Function, reload: Function }}
 */
export function useFinancialData() {
	/**
	 *
	 */
	function load() {
		if (!inflight) {
			loading.value = true
			error.value = null
			inflight = fetchAll()
				.then((result) => {
					data.value = result
				})
				.finally(() => {
					loading.value = false
				})
		}
		return inflight
	}

	/**
	 *
	 */
	function reload() {
		inflight = null
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
export function resetFinancialData() {
	inflight = null
	loading.value = false
	error.value = null
	data.value = null
}
