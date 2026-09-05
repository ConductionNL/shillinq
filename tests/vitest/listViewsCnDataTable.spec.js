/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Unit tests for the migrate-list-views-to-cndatatable change: the five
 * record-list views that were migrated from a hand-rolled <table> to the
 * shared nc-vue CnDataTable universal-list-widget.
 *
 *   src/views/invoice/AdminInvoiceList.vue
 *   src/views/bookkeeping/DocumentsView.vue
 *   src/views/bookkeeping/TransactionsView.vue
 *   src/components/three-way-match/ThreeWayMatchIndex.vue
 *   src/components/vendor-performance/VendorPerformanceIndex.vue
 *
 * Each SFC is compiled by @vitejs/plugin-vue2 (see vitest.config.js) and its
 * `columns` computed is invoked bound to a fake `this` — no DOM mount.
 * @conduction/nextcloud-vue (CnDataTable), @nextcloud/vue, @nextcloud/axios
 * and @nextcloud/router are aliased to light stubs. The test asserts:
 *   (1) each view registers CnDataTable as a component (the list renderer),
 *   (2) each view's `columns` computed exposes the spec-required column keys.
 *
 * @spec openspec/changes/migrate-list-views-to-cndatatable/specs/list-views-cndatatable/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ThreeWayMatchIndex from '../../src/components/three-way-match/ThreeWayMatchIndex.vue'
import VendorPerformanceIndex from '../../src/components/vendor-performance/VendorPerformanceIndex.vue'
import DocumentsView from '../../src/views/bookkeeping/DocumentsView.vue'
import TransactionsView from '../../src/views/bookkeeping/TransactionsView.vue'
import AdminInvoiceList from '../../src/views/invoice/AdminInvoiceList.vue'

/** Identity translator: returns the source string (or fills {placeholders}). */
function tIdentity(app, text, vars) {
	if (!vars) {
		return text
	}
	return text.replace(/\{(\w+)\}/g, (_, k) =>
		k in vars ? String(vars[k]) : `{${k}}`,
	)
}

/** Invoke a component's `columns` computed bound to a fake instance. */
function columnKeys(Component) {
	const fakeThis = { t: tIdentity }
	const cols = Component.computed.columns.call(fakeThis)
	return cols.map((c) => c.key)
}

beforeEach(() => {
	globalThis.t = tIdentity
})

afterEach(() => {
	vi.restoreAllMocks()
	delete globalThis.t
})

describe('migrate-list-views-to-cndatatable: CnDataTable is the list renderer', () => {
	const views = [
		['AdminInvoiceList', AdminInvoiceList],
		['DocumentsView', DocumentsView],
		['TransactionsView', TransactionsView],
		['ThreeWayMatchIndex', ThreeWayMatchIndex],
		['VendorPerformanceIndex', VendorPerformanceIndex],
	]

	it.each(views)('%s registers CnDataTable as a component', (_name, Component) => {
		expect(Component.components).toBeDefined()
		expect(Component.components.CnDataTable).toBeDefined()
	})

	it.each(views)('%s exposes a columns computed', (_name, Component) => {
		expect(Component.computed).toBeDefined()
		expect(typeof Component.computed.columns).toBe('function')
	})
})

describe('AdminInvoiceList columns', () => {
	it('has the invoice #, dates, customer, billing model, gross, status and actions columns', () => {
		expect(columnKeys(AdminInvoiceList)).toEqual([
			'invoiceNumber',
			'invoiceDate',
			'dueDate',
			'customerId',
			'billingModel',
			'grossAmount',
			'status',
			'actions',
		])
	})
})

describe('DocumentsView columns', () => {
	it('has the document number/type/date/status/file-reference columns', () => {
		expect(columnKeys(DocumentsView)).toEqual([
			'documentNumber',
			'documentType',
			'documentDate',
			'status',
			'fileReference',
		])
	})
})

describe('TransactionsView columns', () => {
	it('has the date/number/type/description/amount/status columns', () => {
		expect(columnKeys(TransactionsView)).toEqual([
			'transactionDate',
			'transactionNumber',
			'transactionType',
			'description',
			'amount',
			'status',
		])
	})
})

describe('ThreeWayMatchIndex columns', () => {
	it('has the invoice/supplier/amount/match-date/status/linked-PO-GRN/actions columns', () => {
		expect(columnKeys(ThreeWayMatchIndex)).toEqual([
			'invoice',
			'supplier',
			'amount',
			'matchDate',
			'matchStatus',
			'refs',
			'actions',
		])
	})
})

describe('VendorPerformanceIndex columns', () => {
	it('has the supplier/period/score/trend/disputes/eligible/actions columns', () => {
		expect(columnKeys(VendorPerformanceIndex)).toEqual([
			'supplierId',
			'period',
			'overallScore',
			'scoreTrend',
			'disputeCount',
			'automatedReviewEligible',
			'actions',
		])
	})
})
