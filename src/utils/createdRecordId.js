/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The id a create endpoint answers with: the record's `id`, else its
 * `@self.id`, else its human number field.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 */

/**
 * The created record's id.
 *
 * @param {object} record The response body ({} when there was none).
 * @param {string} numberField The record's number field (poNumber, grnNumber).
 * @return {string|undefined} The id, a falsy value when the record carries none.
 */
export function createdRecordId(record, numberField) {
	return record.id || record['@self']?.id || record[numberField]
}
