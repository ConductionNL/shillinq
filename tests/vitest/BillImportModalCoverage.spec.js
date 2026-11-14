/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * BillImportModal rendered server-side with recording stand-ins
 * (tests/vitest/ssrCapture.js) and an NcDialog stand-in that keeps its body
 * as vnodes and streams body and #actions: the upload step (dropzone, PDF
 * deferral, error, pending extraction drafts) and the review step (fields,
 * per-field confidence badges, GL suggestion, re-request, Save gating), and
 * the handlers the template bound on them.
 *
 * @spec openspec/specs/shillinq-bill-import-modal/spec.md
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ssrRenderAttrs, ssrRenderSlot } from 'vue/server-renderer'
import { session } from './ssrCapture.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (m, key) => (vars && key in vars ? String(vars[key]) : m)),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcDialog: {}, NcSelect: {}, NcTextField: {} }))

const { default: BillImportModal } = await import('../../src/modals/BillImportModal.vue')

const OBJECTS = '/index.php/apps/openregister/api/objects/shillinq/SupplierInvoice'

/**
 * An NcDialog stand-in: records its attributes and its body as vnodes (with
 * the default slots of the components inside expanded), and streams the
 * body and the #actions slot.
 *
 * @param {object} record Where the attributes and vnodes go.
 * @return {object}
 */
function dialogStub(record) {
	const expand = (list) => {
		for (const vnode of [].concat(list || [])) {
			if (vnode && typeof vnode.children?.default === 'function') {
				vnode.children = expand(vnode.children.default())
			} else if (Array.isArray(vnode?.children)) {
				expand(vnode.children)
			}
		}
		return list
	}
	return {
		name: 'NcDialog',
		inheritAttrs: false,
		ssrRender(ctx, push, parent) {
			record.dialog = ctx.$attrs
			record.body = expand([].concat(ctx.$slots.default?.() || []))
			push(`<section${ssrRenderAttrs({ 'data-stub': 'NcDialog', title: ctx.$attrs.name })}>`)
			ssrRenderSlot(ctx.$slots, 'default', {}, null, push, parent)
			push('<footer>')
			ssrRenderSlot(ctx.$slots, 'actions', {}, null, push, parent)
			push('</footer></section>')
		},
	}
}

/**
 * Every vnode of the dialog body that matches.
 *
 * @param {object} view The render session.
 * @param {(vnode: object) => boolean} match The predicate.
 * @return {Array<object>}
 */
function bodyVnodes(view, match) {
	const found = []
	const walk = (list) => {
		for (const vnode of [].concat(list || [])) {
			if (!vnode || typeof vnode !== 'object') {
				continue
			}
			if (match(vnode)) {
				found.push(vnode)
			}
			if (Array.isArray(vnode.children)) {
				walk(vnode.children)
			}
		}
	}
	walk(view.body)
	return found
}

const byTestId = (view, id) => bodyVnodes(view, (vnode) => vnode.props?.['data-testid'] === id)[0]

/**
 * Render the opened modal with part of its data replaced.
 *
 * @param {object} [state] Data to lay over the modal's own.
 * @param {boolean} [open] The open prop.
 * @return {Promise<{html: string, view: object}>}
 */
async function modal(state = {}, open = true) {
	const view = session()
	const withProps = { ...BillImportModal, props: { open: { type: Boolean, default: open } } }
	const html = await view.render(withProps, state, {}, [
		'NcButton',
		'GlAccountPicker',
		'FieldConfidenceBadge',
		['NcDialog', dialogStub(view)],
	])
	view.vm.$.emit = vi.fn()
	return { html: html.replace(/<!--[[\]]-->/g, '').replace(/ data-v-[0-9a-f]+/g, ''), view }
}

const DRAFT = {
	id: 'si-9',
	extractionStatus: 'pending-review',
	overallConfidence: 0.6,
	fieldConfidence: { supplierId: 0.95, invoiceNumber: 0.5, invoiceDate: 0.9, totalInclVat: 0.7, glAccount: 0.4 },
	humanCorrected: ['invoiceNumber'],
	sourceDocumentUri: 'docudesk://doc/9',
	supplierId: 'sup-1',
	invoiceNumber: 'F-9',
	invoiceDate: '2026-10-01',
	totalInclVat: 12100,
	totalVat: 2100,
	glAccount: '',
}

const REVIEW_FORM = { supplier: 'sup-1', invoiceNumber: 'F-9', invoiceDate: '2026-10-01', amount: 121, vatAmount: 21, glAccount: '' }

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.put.mockReset()
	showError.mockReset()
	showSuccess.mockReset()
	emit.mockReset()
})

describe('BillImportModal upload step', () => {
	it('renders nothing while closed', async () => {
		const { html, view } = await modal({}, false)
		expect(html).not.toContain('bill-import-modal')
		expect(view.dialog).toBeUndefined()
	})

	it('shows the dropzone, the deferral, the error and the pending drafts', async () => {
		const { html, view } = await modal({
			pdfDeferred: true,
			error: 'PDF not supported',
			pendingDraftRows: [
				{ id: 'd-1', invoiceNumber: 'F-1', overallConfidence: 0.92 },
				{ id: '', overallConfidence: 'x' },
			],
		})

		expect(view.dialog.name).toBe('Import bill')
		expect(html).toContain('data-testid="bim-upload-step"')
		expect(html).toContain('data-testid="bim-pdf-deferred"')
		expect(html).toMatch(/data-testid="bim-upload-error"[^>]*>\s*PDF not supported\s*</)
		expect(html).toContain('data-testid="bim-pending-d-1"')
		expect(html).toContain('F-1')
		expect(html).toContain('(no invoice number)')
		expect(view.attrsOf('FieldConfidenceBadge').map((attrs) => attrs.confidence)).toEqual([0.92, null])
		expect(html).not.toContain('bim-review-step')
		expect(view.labelled('NcButton', 'Cancel').disabled).toBe(false)
		expect(view.labelled('NcButton', 'Save')).toBeUndefined()
	})

	it('hides the deferral, error and drafts list when there are none', async () => {
		const { html } = await modal()
		expect(html).not.toContain('bim-pdf-deferred')
		expect(html).not.toContain('bim-upload-error')
		expect(html).not.toContain('bim-pending-drafts')
	})

	it('opens a pending draft for review from its button', async () => {
		axios.get.mockResolvedValue({ data: { ...DRAFT, docudeskExtractionId: 'x-1' } })
		axios.post.mockResolvedValue({ data: { suggestion: { code: '4000', label: 'Rent', confidence: 0.8 } } })
		const { view } = await modal({ pendingDraftRows: [{ id: 'si-9', invoiceNumber: 'F-9' }] })
		const [button] = bodyVnodes(view, (vnode) => vnode.type === 'button')

		await button.props.onClick()
		await vi.waitFor(() => expect(view.vm.glSuggestion).not.toBe(null))

		expect(axios.get).toHaveBeenCalledWith(`${OBJECTS}/si-9`)
		expect(view.vm.step).toBe('review')
		expect(view.vm.form).toEqual(REVIEW_FORM)
		expect(view.vm.glSuggestion).toEqual(expect.objectContaining({ code: '4000', label: 'Rent', confidence: 0.8 }))
	})

	it('imports the file dropped on the dropzone, and refuses an unsupported pick', async () => {
		axios.post.mockResolvedValue({ data: { records: [{ id: 'si-1', supplierId: 'sup-2', invoiceNumber: 'U-1' }] } })
		const { view } = await modal()
		const dropzone = byTestId(view, 'bim-dropzone')
		const file = { name: 'bill.xml', text: async () => '<Invoice/>' }

		await dropzone.props.onDrop({ preventDefault: vi.fn(), dataTransfer: { files: [file] } })
		await vi.waitFor(() => expect(view.vm.step).toBe('review'))
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/supplier-invoices/import', expect.anything())
		expect(view.vm.form.invoiceNumber).toBe('U-1')

		await byTestId(view, 'bim-file-input').props.onChange({ target: { files: [{ name: 'notes.txt' }] } })
		expect(view.vm.error).toBe('Unsupported file. Upload a UBL/e-invoice XML or CSV.')
		expect(axios.post).toHaveBeenCalledTimes(1)
	})
})

describe('BillImportModal review step', () => {
	it('shows the draft fields with their confidence, the suggestion and the re-request', async () => {
		const { html, view } = await modal({
			step: 'review',
			importedRecord: DRAFT,
			form: { ...REVIEW_FORM },
			glSuggestion: { code: '4000', label: 'Rent', confidence: 0.8, rationale: 'Seen before' },
			error: 'Duplicate',
		})

		expect(html).toContain('data-testid="bim-review-step"')
		expect(html).toMatch(/data-testid="bim-review-hint"[^>]*>\s*Some fields have low confidence — please review before confirming\.\s*</)
		expect(view.attrsOf('FieldConfidenceBadge').map(({ field, confidence, corrected }) => [field, confidence, corrected])).toEqual([
			['supplierId', 0.95, false],
			['invoiceNumber', 0.5, true],
			['invoiceDate', 0.9, false],
			['totalInclVat', 0.7, false],
			['glAccount', 0.4, false],
			['suggestedGlAccount', 0.8, undefined],
		])
		expect(html).toContain('Suggested: 4000 Rent')
		expect(html).toMatch(/data-testid="bim-gl-suggestion-rationale"[^>]*>\s*Seen before\s*</)
		expect(view.attrsOf('GlAccountPicker')[0].modelValue).toBe('')
		expect(view.labelled('NcButton', 'Request extraction').variant).toBe('secondary')
		expect(html).toMatch(/data-testid="bim-review-error"[^>]*>\s*Duplicate\s*</)
		expect(view.labelled('NcButton', 'Save').disabled).toBe(true)
	})

	it('shows a plain import without badges, suggestion or re-request, and Saving… while busy', async () => {
		const plain = await modal({ step: 'review', importedRecord: { id: 'si-1' }, form: { ...REVIEW_FORM, glAccount: '4000' } })
		expect(plain.html).not.toContain('bim-review-hint')
		expect(plain.html).not.toContain('bim-gl-suggestion')
		expect(plain.html).not.toContain('bim-review-error')
		expect(plain.view.attrsOf('FieldConfidenceBadge')).toEqual([])
		expect(plain.view.labelled('NcButton', 'Request extraction')).toBeUndefined()
		expect(plain.view.labelled('NcButton', 'Save')).toEqual(expect.objectContaining({ variant: 'primary', disabled: false }))

		const busy = await modal({ step: 'review', busy: true, importedRecord: { id: 'si-1' }, form: { ...REVIEW_FORM, glAccount: '4000' } })
		expect(busy.view.labelled('NcButton', 'Saving…').disabled).toBe(true)
		expect(busy.view.labelled('NcButton', 'Cancel').disabled).toBe(true)
	})

	it('says confidence is high for a confident draft', async () => {
		const { html } = await modal({ step: 'review', importedRecord: { ...DRAFT, overallConfidence: 0.95 }, form: { ...REVIEW_FORM } })
		expect(html).toContain('Extraction confidence is high. Review and confirm.')
	})

	it('edits every field through its bound input and the GL picker', async () => {
		const { view } = await modal({ step: 'review', importedRecord: DRAFT, form: { ...REVIEW_FORM } })
		const updates = {
			'bim-supplier': 'sup-3',
			'bim-invoice-number': 'F-10',
			'bim-invoice-date': '2026-10-02',
			'bim-amount': 50,
			'bim-vat-amount': 5,
		}
		for (const [id, value] of Object.entries(updates)) {
			byTestId(view, id).props['onUpdate:modelValue'](value)
		}
		view.attrsOf('GlAccountPicker')[0]['onUpdate:modelValue']('4100')
		expect(view.vm.form).toEqual({ supplier: 'sup-3', invoiceNumber: 'F-10', invoiceDate: '2026-10-02', amount: 50, vatAmount: 5, glAccount: '4100' })

		byTestId(view, 'bim-gl-account').props['onUpdate:modelValue']('4200')
		expect(view.vm.form.glAccount).toBe('4200')
	})

	it('uses the suggestion and re-requests the extraction from their buttons', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const { view } = await modal({
			step: 'review',
			importedRecord: DRAFT,
			form: { ...REVIEW_FORM },
			glSuggestion: { code: '4000', label: 'Rent', confidence: 0.8, rationale: '' },
		})

		view.labelled('NcButton', 'Use suggestion').onClick()
		expect(view.vm.form.glAccount).toBe('4000')

		await view.labelled('NcButton', 'Request extraction').onClick()
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/extraction/request', {
			documentUri: 'docudesk://doc/9',
			docType: 'supplier-invoice',
			id: 'si-9',
		})
		expect(showSuccess).toHaveBeenCalledWith('Extraction requested. The draft will update once docudesk responds.')
		expect(view.vm.busy).toBe(false)
	})

	it('saves the reviewed draft from Save, then closes', async () => {
		axios.put.mockResolvedValue({ data: { record: { id: 'si-9' } } })
		const { view } = await modal({ step: 'review', importedRecord: DRAFT, form: { ...REVIEW_FORM, glAccount: '4000' } })

		await view.labelled('NcButton', 'Save').onClick()

		expect(axios.put).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/extraction/drafts/si-9?schema=SupplierInvoice', {
			supplierId: 'sup-1', invoiceNumber: 'F-9', invoiceDate: '2026-10-01', glAccount: '4000', totalInclVat: 12100, totalVat: 2100,
		})
		expect(emit).toHaveBeenCalledWith('cn:widget:refresh', expect.any(Object))
		expect(view.vm.$.emit.mock.calls).toEqual([['imported', { id: 'si-9' }], ['close']])
	})

	it('closes from Cancel and from the dialog closing, not while busy', async () => {
		const { view } = await modal()
		view.labelled('NcButton', 'Cancel').onClick()
		view.dialog.onClosing()
		expect(view.vm.$.emit.mock.calls).toEqual([['close'], ['close']])

		view.vm.busy = true
		view.dialog.onClosing()
		expect(view.vm.$.emit).toHaveBeenCalledTimes(2)
	})
})
