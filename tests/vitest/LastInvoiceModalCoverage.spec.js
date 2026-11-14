/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * LastInvoiceModal rendered server-side with recording stand-ins
 * (tests/vitest/ssrCapture.js) and an NcDialog stand-in that streams both its
 * body and its #actions slot: the release sentence before confirming, the
 * closed sentence after, the error, and the Cancel / Close / Release and
 * close buttons with the handlers the template bound.
 *
 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/specs/bookkeeping-verplichtingenadministratie/spec.md
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ssrRenderAttrs, ssrRenderSlot } from 'vue/server-renderer'
import { session } from './ssrCapture.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? String(vars[key]) : m)),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcSelect: {}, NcTextField: {} }))

const { default: LastInvoiceModal } = await import('../../src/modals/LastInvoiceModal.vue')

const LAST_INVOICE = '/index.php/apps/shillinq/api/v1/supplier-invoices/si-1/last-invoice'

/**
 * An NcDialog stand-in: records its attributes and streams the body and the
 * #actions slot.
 *
 * @param {object} record Where the attributes go.
 * @return {object}
 */
function dialogStub(record) {
	return {
		name: 'NcDialog',
		inheritAttrs: false,
		ssrRender(ctx, push, parent) {
			record.dialog = ctx.$attrs
			push(`<section${ssrRenderAttrs({ 'data-stub': 'NcDialog', title: ctx.$attrs.name })}>`)
			ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
			push('<footer>')
			ssrRenderSlot(ctx.$slots, 'actions', {}, null, push, parent)
			push('</footer></section>')
		},
	}
}

/**
 * Render the modal for invoice si-1 with part of its data replaced. The
 * mounted() load does not run server-side.
 *
 * @param {object} [state] Data to lay over the modal's own.
 * @return {Promise<{html: string, view: object}>}
 */
async function modal(state = {}) {
	const view = session()
	const withProps = {
		...LastInvoiceModal,
		props: {
			invoiceId: { type: String, default: 'si-1' },
			administrationId: { type: String, default: 'adm' },
		},
	}
	const html = await view.render(withProps, state, {}, ['NcButton', ['NcDialog', dialogStub(view)]])
	view.vm.$.emit = vi.fn()
	return { html: html.replace(/<!--[[\]]-->/g, '').replace(/ data-v-[0-9a-f]+/g, ''), view }
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('LastInvoiceModal rendering', () => {
	it('shows what is released and offers to release and close', async () => {
		const { html, view } = await modal({ preview: { commitmentNumber: 'C-7', release: 123456 } })

		expect(view.dialog.name).toBe('Mark as last invoice')
		expect(html).toMatch(
			/data-testid="last-invoice-release"[^>]*>\s*This closes commitment C-7 and releases EUR 1,234\.56 back to the budget\.\s*</,
		)
		expect(html).not.toContain('last-invoice-done')
		expect(view.attrsOf('NcButton').map((attrs) => attrs.disabled)).toEqual([undefined, false])
		expect(view.labelled('NcButton', 'Cancel')).toBeDefined()
		expect(view.labelled('NcButton', 'Release and close').variant).toBe('primary')
	})

	it('keeps confirm disabled without a preview, and while submitting', async () => {
		const empty = await modal({ error: 'Could not load' })
		expect(empty.html).not.toContain('last-invoice-release')
		expect(empty.html).toMatch(/role="alert"[^>]*>\s*Could not load\s*</)
		expect(empty.view.labelled('NcButton', 'Release and close').disabled).toBe(true)

		const busy = await modal({ preview: { commitmentNumber: 'C-7', release: 1 }, submitting: true })
		expect(busy.view.labelled('NcButton', 'Release and close').disabled).toBe(true)
	})

	it('says the commitment is closed once done, with only a Close button', async () => {
		const { html, view } = await modal({ preview: { commitmentNumber: 'C-7', released: 5000 }, done: true })

		expect(html).toMatch(/data-testid="last-invoice-done"[^>]*>\s*Commitment C-7 is closed\.\s*</)
		expect(html).not.toContain('last-invoice-release')
		expect(view.attrsOf('NcButton')).toHaveLength(1)
		expect(view.labelled('NcButton', 'Close')).toBeDefined()
		expect(view.labelled('NcButton', 'Release and close')).toBeUndefined()
	})
})

describe('LastInvoiceModal handlers', () => {
	it('releases through the confirm button, then closes reporting it done', async () => {
		axios.post.mockResolvedValue({ data: { commitmentNumber: 'C-7', released: 5000 } })
		const { view } = await modal({ preview: { commitmentNumber: 'C-7', release: 5000 } })

		await view.labelled('NcButton', 'Release and close').onClick()

		expect(axios.post).toHaveBeenCalledWith(LAST_INVOICE, { administrationId: 'adm' })
		expect([view.vm.done, view.vm.submitting, view.vm.preview]).toEqual([
			true,
			false,
			{ commitmentNumber: 'C-7', released: 5000 },
		])
		view.labelled('NcButton', 'Cancel').onClick()
		expect(view.vm.$.emit).toHaveBeenCalledWith('close', true)
	})

	it('closes not done from the dialog closing', async () => {
		const { view } = await modal({ preview: { commitmentNumber: 'C-7', release: 1 } })
		view.dialog.onClosing()
		expect(view.vm.$.emit).toHaveBeenCalledWith('close', false)
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('falls back to its own sentence when closing fails without a message', async () => {
		axios.post.mockRejectedValue(new Error('down'))
		const { view } = await modal({ preview: { commitmentNumber: 'C-7', release: 1 } })

		await view.labelled('NcButton', 'Release and close').onClick()

		expect(view.vm.error).toBe('The commitment could not be closed.')
		expect(view.vm.done).toBe(false)
	})
})
