// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// tax-vat-position-and-suppletie 1.3: the totals under the lines behind a box
// of the running VAT position (src/modals/VatPositionLinesModal.vue).

/**
 * The base and VAT of a box's lines added up, in whole cents.
 *
 * @param {Array<{taxableAmount: number, vatAmount: number}>} lines The lines.
 * @return {{base: number, vat: number}} The totals in euros.
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-1.3
 */
export function positionLineTotals(lines) {
	let base = 0
	let vat = 0
	for (const line of lines || []) {
		base += Math.round((Number(line?.taxableAmount) || 0) * 100)
		vat += Math.round((Number(line?.vatAmount) || 0) * 100)
	}
	return { base: base / 100, vat: vat / 100 }
}
