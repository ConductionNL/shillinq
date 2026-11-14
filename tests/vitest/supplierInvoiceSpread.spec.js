/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The per-line "Spread over periods" action on SupplierInvoiceDetail.vue:
 * it opens SpreadOverPeriodsModal for that line of the loaded invoice.
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 */

import { describe, expect, it, vi } from 'vitest'

const dialog = vi.hoisted(() => ({ spawnDialog: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@nextcloud/vue/functions/dialog', () => dialog)

const { default: SupplierInvoiceDetail } = await import('../../src/components/supplier-invoice/SupplierInvoiceDetail.vue')
const { default: SpreadOverPeriodsModal } = await import('../../src/modals/SpreadOverPeriodsModal.vue')

describe('SupplierInvoiceDetail spread action', () => {
	it('opens the spread modal for the line, with the invoice it already has', async () => {
		const invoice = { id: 'si-1', invoiceNumber: 'INK-1', lines: [{ lineNumber: 3 }] }
		const vm = { id: 'si-1', invoice }

		await SupplierInvoiceDetail.methods.openSpread.call(vm, { lineNumber: 3 })

		expect(dialog.spawnDialog).toHaveBeenCalledWith(SpreadOverPeriodsModal, { open: true, side: 'ap', objectId: 'si-1', invoice, lineKey: '3' })
	})
})
