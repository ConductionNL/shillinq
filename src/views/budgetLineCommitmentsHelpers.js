// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure helpers for BudgetLineCommitments.vue (REQ-VPL-011).
 *
 * Normalises the `committedVsRealisedPerBudgetLine` aggregation response
 * (Verplichtingsregel buckets grouped by programma/kostenplaats/boekjaar/
 * grootboekrekening, joined to Budget.geautoriseerd_bedrag /
 * Budget.gerealiseerd_bedrag) into display rows with the four BBV columns
 * (geautoriseerd / verplicht / gerealiseerd / vrij). `vrij` is computed
 * client-side from the three declared figures — display arithmetic, not a
 * parallel PHP reporting service (ADR-031 / REQ-VPL-011).
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */

/**
 * Normalise the raw aggregation payload into budget-line rows.
 *
 * @param {object} payload Raw response from the aggregation endpoint.
 * @return {Array<object>} Rows with programma/kostenplaats/boekjaar/grootboekrekening + the four amount columns (minor units).
 */
export function normaliseBudgetLineRows(payload) {
	const buckets = Array.isArray(payload?.buckets)
		? payload.buckets
		: Array.isArray(payload)
			? payload
			: []

	return buckets.map((bucket) => {
		const geautoriseerd = Number(
			bucket?.['Budget.geautoriseerd_bedrag'] ?? bucket?.geautoriseerd ?? 0,
		)
		const verplicht = Number(bucket?.restant_verplicht ?? bucket?.verplicht ?? 0)
		const gerealiseerd = Number(
			bucket?.gefactureerd_bedrag ?? bucket?.gerealiseerd ?? 0,
		)
		const vrij = geautoriseerd - verplicht - gerealiseerd

		return {
			key: [
				bucket?.programma,
				bucket?.kostenplaats,
				bucket?.boekjaar,
				bucket?.grootboekrekening,
			].join('|'),
			programma: String(bucket?.programma ?? ''),
			kostenplaats: String(bucket?.kostenplaats ?? ''),
			boekjaar: bucket?.boekjaar ?? null,
			grootboekrekening: String(bucket?.grootboekrekening ?? ''),
			geautoriseerd,
			verplicht,
			gerealiseerd,
			vrij,
		}
	})
}

/**
 * Format a minor-units (cents) amount as an EUR currency string.
 *
 * @param {number} cents Amount in minor units.
 * @return {string} Formatted currency string.
 */
export function formatAmount(cents) {
	const value = (Number(cents) || 0) / 100
	try {
		return new Intl.NumberFormat(undefined, {
			style: 'currency',
			currency: 'EUR',
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		}).format(value)
	} catch (e) {
		return value.toFixed(2)
	}
}

/**
 * Build the exact-match filter set to drill down from a budget-line row to
 * its underlying Verplichtingsregel records.
 *
 * @param {object} row Normalised budget-line row.
 * @return {object} Filters keyed by Verplichtingsregel field name.
 */
export function drilldownFilters(row) {
	const filters = {}
	if (row.programma) {
		filters.programma = row.programma
	}
	if (row.kostenplaats) {
		filters.kostenplaats = row.kostenplaats
	}
	if (row.boekjaar !== null && row.boekjaar !== undefined) {
		filters.boekjaar = row.boekjaar
	}
	if (row.grootboekrekening) {
		filters.grootboekrekening = row.grootboekrekening
	}
	return filters
}
