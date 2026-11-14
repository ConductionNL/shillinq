/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * ReceiptCapture: what the detail page renders while loading, after a failed
 * load, for a plain receipt and for an extraction draft (per-field confidence
 * badges, review hint, GL-account suggestion), and how it loads, suggests,
 * saves and re-requests an extraction. The template is rendered with Vue's
 * server renderer, so no DOM is needed; the methods run bound to a plain
 * context seeded from the real `data()`.
 *
 * Only the HTTP boundary is mocked (@nextcloud/axios) plus the two toast
 * helpers — the extraction-confidence and draft-API helpers run for real, so
 * these tests pin the page's behaviour and not a re-statement of its mocks.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }))
const toast = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (whole, key) =>
			vars && key in vars ? String(vars[key]) : whole,
		),
	translatePlural: (app, singular) => singular,
}))
vi.mock('../../src/components/FieldConfidenceBadge.vue', () => ({
	default: {
		name: 'FieldConfidenceBadge',
		props: ['field', 'confidence', 'corrected'],
		render() {
			return h(
				'span',
				{
					'data-testid': `confidence-${this.field}`,
					'data-confidence': String(this.confidence),
					'data-corrected': String(this.corrected),
				},
				this.field,
			)
		},
	},
}))
vi.mock('../../src/components/expenses/ExpenseCategoryPicker.vue', () => ({
	default: {
		name: 'ExpenseCategoryPicker',
		props: ['modelValue', 'administrationId'],
		render() {
			return h('input', {
				'data-testid': 'expense-category-picker',
				value: this.modelValue,
			})
		},
	},
}))
vi.mock('../../src/components/BudgetBBVMapping/GlAccountPicker.vue', () => ({
	default: {
		name: 'GlAccountPicker',
		props: ['modelValue'],
		render() {
			return h('input', {
				'data-testid': 'gl-account-picker',
				value: this.modelValue,
			})
		},
	},
}))

const { default: ReceiptCapture } = await import(
	'../../src/views/ReceiptCapture.vue'
)

const DRAFT = {
	id: 'rec-1',
	extractionStatus: 'pending-review',
	overallConfidence: 0.62,
	fieldConfidence: { amount: 0.91, receiptDate: 0.4, glAccount: 'n/a' },
	humanCorrected: ['receiptDate'],
	sourceDocumentUri: 'files://receipt.pdf',
	docudeskExtractionId: 'extr-9',
	amount: 12.5,
	currency: 'EUR',
	receiptDate: '2026-03-01',
	category: 'Travel',
	extractedText: 'Taxi Amsterdam',
}

/**
 * Render the page with its data overridden. The `id` watcher is dropped so a
 * render does not immediately re-load over the state under test.
 *
 * @param {object} data The data to use instead of the defaults.
 * @param {Array<object>} [routes] The router's routes.
 * @return {Promise<string>} The HTML.
 */
async function render(data, routes = []) {
	const app = createSSRApp({
		...ReceiptCapture,
		watch: {},
		data: () => ({ ...ReceiptCapture.data(), ...data }),
	})
	app.config.globalProperties.$router = { options: { routes } }
	app.component('router-link', {
		props: ['to'],
		render() {
			return h('a', { href: this.to?.name }, this.$slots.default?.())
		},
	})
	return renderToString(app)
}

/**
 * The page's methods and computed properties bound to a plain context seeded
 * from its data.
 *
 * @param {object} [state] The state to seed on top of the defaults.
 * @return {object} The context.
 */
function context(state = {}) {
	const ctx = { id: 'rec-1', ...ReceiptCapture.data(), ...state }
	for (const [name, fn] of Object.entries(ReceiptCapture.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	for (const [name, fn] of Object.entries(ReceiptCapture.computed)) {
		Object.defineProperty(ctx, name, { get: fn.bind(ctx), configurable: true })
	}
	return ctx
}

/**
 * Let every already-queued promise settle.
 *
 * @return {Promise<void>}
 */
function flush() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

beforeEach(() => {
	http.get.mockReset()
	http.put.mockReset()
	http.post.mockReset()
	toast.showError.mockReset()
	toast.showSuccess.mockReset()
})

describe('ReceiptCapture rendering', () => {
	it('shows only the loading line while loading', async () => {
		const html = await render({})

		expect(html).toContain('Loading receipt…')
		expect(html).not.toContain('data-testid="rc-save"')
		expect(html).not.toContain('data-testid="receipt-capture-error"')
	})

	it('offers a retry on a failed load and no form', async () => {
		const html = await render({ loading: false, loadError: 'Boom' })

		expect(html).toContain('data-testid="receipt-capture-retry"')
		expect(html).toContain('Boom')
		expect(html).not.toContain('data-testid="rc-amount"')
	})

	it('links back to the index only when the router has that route', async () => {
		expect(await render({}, [{ name: 'Receipts' }])).toContain(
			'data-testid="receipt-capture-back"',
		)
		expect(await render({}, [{ name: 'Bookings' }])).not.toContain(
			'data-testid="receipt-capture-back"',
		)
	})

	it('renders a plain receipt without confidence badges, hint or re-request', async () => {
		const html = await render({
			loading: false,
			record: { id: 'rec-1', amount: 10 },
			form: { ...ReceiptCapture.data().form, amount: 10, category: 'Food' },
		})

		expect(html).toContain('data-testid="rc-save"')
		expect(html).not.toContain('data-testid="confidence-amount"')
		expect(html).not.toContain('data-testid="receipt-capture-review-hint"')
		expect(html).not.toContain('data-testid="rc-rerequest"')
		expect(html).not.toContain('data-testid="rc-source-document"')
	})

	it('renders a draft with its badges, review hint, source document and re-request', async () => {
		const html = await render({ loading: false, record: DRAFT })

		expect(html).toContain(
			'Some fields have low confidence — please review before confirming.',
		)
		expect(html).toContain('data-testid="confidence-amount"')
		expect(html).toMatch(
			/data-testid="confidence-receiptDate"[^>]*data-confidence="0.4"[^>]*data-corrected="true"/,
		)
		// 'n/a' is not a number, so that field carries no confidence.
		expect(html).toMatch(
			/data-testid="confidence-glAccount"[^>]*data-confidence="null"[^>]*data-corrected="false"/,
		)
		expect(html).toContain('files://receipt.pdf')
		expect(html).toContain('data-testid="rc-rerequest"')
	})

	it('words a high-confidence draft hint differently and hides re-request without a source document', async () => {
		const html = await render({
			loading: false,
			record: {
				extractionStatus: 'pending-review',
				overallConfidence: 0.95,
			},
		})

		expect(html).toContain('Extraction confidence is high. Review and confirm.')
		expect(html).not.toContain('data-testid="rc-rerequest"')
	})

	it('renders the GL-account suggestion with its rationale and use button', async () => {
		const html = await render({
			loading: false,
			record: DRAFT,
			glSuggestion: {
				code: '4300',
				label: 'Reiskosten',
				confidence: 0.88,
				rationale: 'Vendor matches prior taxi bookings',
			},
		})

		expect(html).toContain('Suggested: 4300 Reiskosten')
		expect(html).toContain('Vendor matches prior taxi bookings')
		expect(html).toContain('data-testid="rc-use-suggestion"')
	})

	it('disables save until the required fields are filled and shows a save error', async () => {
		const incomplete = await render({
			loading: false,
			record: { id: 'rec-1' },
			saveError: 'Rejected',
		})

		expect(incomplete).toMatch(/disabled[^>]*data-testid="rc-save"/)
		expect(incomplete).toContain('Rejected')

		const complete = await render({
			loading: false,
			record: { id: 'rec-1' },
			form: {
				...ReceiptCapture.data().form,
				amount: 1,
				receiptDate: '2026-03-01',
				category: 'Food',
			},
		})

		expect(complete).not.toMatch(/disabled[^>]*data-testid="rc-save"/)
	})

	it('labels the save button while busy and disables both actions', async () => {
		const html = await render({
			loading: false,
			busy: true,
			record: DRAFT,
			form: {
				...ReceiptCapture.data().form,
				amount: 1,
				receiptDate: '2026-03-01',
				category: 'Food',
			},
		})

		expect(html).toContain('Saving…')
		expect(html).toMatch(/disabled[^>]*data-testid="rc-rerequest"/)
	})
})

describe('ReceiptCapture state', () => {
	it('reads a draft record field by field', () => {
		const vm = context({ record: DRAFT })

		expect([
			vm.isDraftReview,
			vm.requiresReview,
			vm.canRerequest,
			vm.sourceDocumentUri,
			vm.confidenceFor('amount'),
			vm.confidenceFor('currency'),
			vm.isCorrected('receiptDate'),
			vm.isCorrected('amount'),
		]).toEqual([true, true, true, 'files://receipt.pdf', 0.91, null, true, false])
	})

	it('treats a confirmed record as no draft and offers no re-request', () => {
		const vm = context({ record: { extractionStatus: 'confirmed' } })

		expect([
			vm.isDraftReview,
			vm.requiresReview,
			vm.canRerequest,
			vm.sourceDocumentUri,
		]).toEqual([false, false, false, ''])
	})

	it('requires amount, currency, date and category before a save is possible', () => {
		const full = {
			amount: 5,
			currency: 'EUR',
			receiptDate: '2026-03-01',
			category: 'Food',
		}

		expect(context({ form: full }).canSave).toBe(true)
		expect(context({ form: { ...full, amount: -1 } }).canSave).toBe(false)
		expect(context({ form: { ...full, category: ' ' } }).canSave).toBe(false)
	})
})

describe('ReceiptCapture loading', () => {
	it('reads the receipt from its OpenRegister object endpoint and fills the form', async () => {
		http.get.mockResolvedValue({ data: { object: DRAFT } })
		http.post.mockResolvedValue({
			data: {
				suggestion: {
					code: '4300',
					label: 'Reiskosten',
					confidence: 0.88,
					rationale: 'Taxi',
				},
			},
		})
		const vm = context({ loadError: 'stale' })

		await vm.reload()
		// reload() fires the suggestion request without awaiting it, so the
		// page can render the record before docudesk answers.
		await flush()

		expect(http.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/Receipt/rec-1',
		)
		expect(http.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/v1/extraction/drafts/rec-1/suggest-account?schema=Receipt',
		)
		expect([vm.record, vm.loading, vm.loadError]).toEqual([DRAFT, false, ''])
		expect(vm.form).toMatchObject({
			amount: 12.5,
			currency: 'EUR',
			receiptDate: '2026-03-01',
			category: 'Travel',
			exchangeRate: null,
			glAccount: '',
		})
		expect(vm.glSuggestion).toEqual({
			code: '4300',
			label: 'Reiskosten',
			confidence: 0.88,
			rationale: 'Taxi',
			source: 'none',
		})
	})

	it('accepts a bare object body', async () => {
		http.get.mockResolvedValue({ data: { id: 'rec-1', amount: 3 } })
		const vm = context()

		await vm.reload()

		expect([vm.record.amount, vm.loadError]).toEqual([3, ''])
	})

	it('words an empty body and a failed request, and stops loading either way', async () => {
		http.get.mockResolvedValue({ data: null })
		const empty = context()
		await empty.reload()

		http.get.mockRejectedValue({ response: { data: { error: 'Gone' } } })
		const failed = context()
		await failed.reload()

		http.get.mockRejectedValue({})
		const unworded = context()
		await unworded.reload()

		expect([empty.loadError, empty.loading, empty.record]).toEqual([
			'Empty response',
			false,
			null,
		])
		expect([failed.loadError, failed.loading]).toEqual(['Gone', false])
		expect(unworded.loadError).toBe('Failed to load or save the receipt.')
	})

	it('asks for no suggestion without a known extraction id', async () => {
		const vm = context({ record: { ...DRAFT, docudeskExtractionId: '  ' }, glSuggestion: 'stale' })

		await vm.fetchGlSuggestion()

		expect(http.post).not.toHaveBeenCalled()
		expect(vm.glSuggestion).toBeNull()
	})

	it('keeps no suggestion when the proxy has none or fails', async () => {
		http.post.mockResolvedValue({ data: { suggestion: null } })
		const none = context({ record: DRAFT })
		await none.fetchGlSuggestion()

		http.post.mockRejectedValue(new Error('down'))
		const failed = context({ record: DRAFT })
		await failed.fetchGlSuggestion()

		expect([none.glSuggestion, failed.glSuggestion]).toEqual([null, null])
	})

	it('fills the picker from the suggestion only on the explicit action', () => {
		const vm = context({
			glSuggestion: { code: '4300' },
			form: { ...ReceiptCapture.data().form },
		})

		vm.onUseSuggestion()
		expect(vm.form.glAccount).toBe('4300')

		const without = context({ form: { ...ReceiptCapture.data().form } })
		without.onUseSuggestion()
		expect(without.form.glAccount).toBe('')
	})
})

describe('ReceiptCapture saving', () => {
	const form = {
		...ReceiptCapture.data().form,
		receiptNumber: 'R-1',
		amount: 12.5,
		currency: 'EUR',
		receiptDate: '2026-03-01',
		category: 'Travel',
		glAccount: '4300',
		exchangeRate: '',
	}

	it('commits a draft correction through the confirm proxy', async () => {
		http.put.mockResolvedValue({ data: { record: { ...DRAFT, amount: 12.5, extractionStatus: 'confirmed' } } })
		const vm = context({ record: DRAFT, form })

		await vm.onSave()

		expect(http.put).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/v1/extraction/drafts/rec-1?schema=Receipt',
			expect.objectContaining({
				receiptNumber: 'R-1',
				amount: 12.5,
				currency: 'EUR',
				receiptDate: '2026-03-01',
				category: 'Travel',
				glAccount: '4300',
				exchangeRate: null,
				amountInBaseCurrency: 0,
			}),
		)
		expect(vm.record.extractionStatus).toBe('confirmed')
		expect(vm.form.amount).toBe(12.5)
		expect([vm.busy, vm.saveError]).toEqual([false, ''])
		expect(toast.showSuccess).toHaveBeenCalledWith('Receipt saved.')
	})

	it('updates a confirmed receipt through the object endpoint, merged over the record', async () => {
		http.put.mockResolvedValue({ data: { object: { id: 'rec-1', amount: 12.5 } } })
		const vm = context({ record: { id: 'rec-1', amount: 1, vendorName: 'Taxi' }, form })

		await vm.onSave()

		expect(http.put).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/Receipt/rec-1',
			expect.objectContaining({ id: 'rec-1', amount: 12.5, vendorName: '' }),
		)
		expect(vm.record).toEqual({ id: 'rec-1', amount: 12.5 })
	})

	it('keeps the record when the response carries no body', async () => {
		http.put.mockResolvedValue({})
		const record = { id: 'rec-1' }
		const vm = context({ record, form })

		await vm.onSave()

		expect(vm.record).toBe(record)
	})

	it('reports a failed save inline and as a toast, and frees the button', async () => {
		http.put.mockRejectedValue({ response: { data: { message: 'Schema refused' } } })
		const vm = context({ record: { id: 'rec-1' }, form })

		await vm.onSave()

		expect([vm.saveError, vm.busy]).toEqual(['Schema refused', false])
		expect(toast.showError).toHaveBeenCalledWith('Schema refused')
		expect(toast.showSuccess).not.toHaveBeenCalled()
	})

	it('sends nothing while busy or while a required field is missing', async () => {
		await context({ record: { id: 'rec-1' }, form, busy: true }).onSave()
		await context({ record: { id: 'rec-1' } }).onSave()

		expect(http.put).not.toHaveBeenCalled()
	})
})

describe('ReceiptCapture re-request', () => {
	it('asks docudesk for the source document again and confirms it', async () => {
		http.post.mockResolvedValue({})
		const vm = context({ record: DRAFT })

		await vm.onRerequest()

		expect(http.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/v1/extraction/request',
			{ documentUri: 'files://receipt.pdf', docType: 'receipt', id: 'rec-1' },
		)
		expect([vm.busy, vm.saveError]).toEqual([false, ''])
		expect(toast.showSuccess).toHaveBeenCalledWith(
			'Extraction requested. The draft will update once docudesk responds.',
		)
	})

	it('reports a refused re-request', async () => {
		http.post.mockRejectedValue({ message: 'Proxy unavailable' })
		const vm = context({ record: DRAFT })

		await vm.onRerequest()

		expect([vm.saveError, vm.busy]).toEqual(['Proxy unavailable', false])
		expect(toast.showError).toHaveBeenCalledWith('Proxy unavailable')
	})

	it('sends nothing while busy or without a source document', async () => {
		await context({ record: DRAFT, busy: true }).onRerequest()
		await context({ record: { extractionStatus: 'pending-review' } }).onRerequest()

		expect(http.post).not.toHaveBeenCalled()
	})
})
