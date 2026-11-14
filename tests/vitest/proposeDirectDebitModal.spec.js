/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * receivables-sepa-direct-debit 2.1: the Propose direct debit dialog. What it
 * renders (server-side, no DOM) before and after a proposal, and what its
 * load and submit send and do with the answer.
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md#requirement-a-batch-is-proposed-from-open-invoices-with-a-mandate-req-sdd-011
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))
vi.mock('@nextcloud/vue', () => {
	const slotted = (name, tag = 'div') => ({
		name,
		inheritAttrs: false,
		props: ['name', 'label', 'disabled', 'modelValue'],
		render() {
			return h(tag, { 'data-stub': name, 'data-name': this.name, 'data-label': this.label, 'data-testid': this.$attrs['data-testid'], disabled: this.disabled || undefined }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
	return { NcDialog: slotted('NcDialog'), NcButton: slotted('NcButton', 'button'), NcTextField: slotted('NcTextField') }
})

const { default: ProposeDirectDebitModal } = await import('../../src/modals/ProposeDirectDebitModal.vue')
const { spawnDialog } = await import('@nextcloud/vue/functions/dialog')
const { openProposeDirectDebit } = await import('../../src/utils/directDebitActions.js')

/**
 * Render the dialog with its data replaced.
 *
 * @param {object} state The data.
 * @return {Promise<string>} The HTML.
 */
function render(state) {
	return renderToString(createSSRApp({ ...ProposeDirectDebitModal, data: () => state }))
}

/**
 * A plain instance of the dialog, its computed values readable.
 *
 * @param {object} over Data to set first.
 * @return {object} The instance.
 */
function instance(over = {}) {
	const self = { $emit: vi.fn() }
	Object.assign(self, ProposeDirectDebitModal.data.call(self), over)
	for (const [name, method] of Object.entries(ProposeDirectDebitModal.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	for (const [name, getter] of Object.entries(ProposeDirectDebitModal.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

const form = { administrationId: 'adm-1', collectionDate: '2026-10-15', submitting: false, result: null, error: '' }

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('ProposeDirectDebitModal', () => {
	it('is what the header action opens', () => {
		openProposeDirectDebit()
		expect(spawnDialog).toHaveBeenCalledWith(ProposeDirectDebitModal, {})
	})

	it('renders the date until there is a result, the propose button gated', async () => {
		const html = await render(form)
		expect(html).toContain('data-label="Collection date"')
		expect(html).toMatch(/data-testid="propose-dd-submit"(?![^>]*disabled)/)
		expect(await render({ ...form, administrationId: '' })).toMatch(/data-testid="propose-dd-submit"[^>]*disabled/)
	})

	it('renders the draft batches with their links and total, and what was left out', async () => {
		const html = await render({
			...form,
			result: {
				batches: [
					{ id: 'b 1', scheme: 'CORE', sequenceType: 'FRST', collectionCount: 1, controlSum: 750 },
					{ id: 'b-2', scheme: 'CORE', sequenceType: 'RCUR', collectionCount: 2, controlSum: 500 },
				],
				skipped: [{ invoiceId: 'inv-119', invoiceNumber: 'F-2026-119', reason: 'mandate-inactive', detail: 'cancelled' }],
			},
		})
		expect(html).toContain('2 draft batches, total EUR 1250.00.')
		expect(html).toContain('href="/index.php/apps/shillinq/bookkeeping/direct-debit-batches/b%201"')
		expect(html).toContain('CORE FRST: 1 collection, EUR 750.00')
		expect(html).toContain('CORE RCUR: 2 collections, EUR 500.00')
		expect(html).toContain('F-2026-119: The mandate is not active (cancelled)')
		expect(html).not.toContain('propose-dd-submit')

		const nothing = await render({ ...form, result: { batches: [], skipped: [] }, error: 'Late' })
		expect(nothing).toContain('No invoice can be collected on that date.')
		expect(nothing).toMatch(/role="alert"[^>]*>\s*Late/)
	})

	it('loads the active administration, proposes for the date and reports a failure', async () => {
		const dialog = instance({ administrationId: '' })
		axios.get.mockResolvedValueOnce({ data: { activeAdministrationId: 'adm-7' } })
		await ProposeDirectDebitModal.mounted.call(dialog)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/context')
		expect(dialog.administrationId).toBe('adm-7')
		expect(dialog.canSubmit).toBe(true)

		axios.post.mockResolvedValueOnce({ data: { batches: [{ id: 'b', controlSum: 12.5 }], skipped: [] } })
		await dialog.submit()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/direct-debit-batches/propose', { administrationId: 'adm-7', collectionDate: dialog.collectionDate })
		expect(dialog.total).toBe(12.5)
		expect(dialog.submitting).toBe(false)

		const failing = instance(form)
		axios.post.mockRejectedValueOnce({ response: { status: 500, data: { message: 'Store down' } } })
		await failing.submit()
		expect(failing.result).toBeNull()
		expect(failing.error).toBe('Store down')

		failing.close()
		expect(failing.$emit).toHaveBeenCalledWith('close', null)

		const unloaded = instance({ administrationId: '' })
		axios.get.mockRejectedValueOnce(new Error('offline'))
		await ProposeDirectDebitModal.mounted.call(unloaded)
		expect(unloaded.error).toBe('The administration could not be loaded.')
		expect(unloaded.canSubmit).toBe(false)
	})
})
