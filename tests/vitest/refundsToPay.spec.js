/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * receivables-object-request-refund-and-credit 3.2: the "Refunds to pay"
 * page. An index page over PaymentRequest in state refund_requested, with
 * two row actions: Approve posts to /refund/approve, Mark paid opens
 * RefundPaidModal, which asks the bank reference and the bank account and
 * posts to /refund/paid.
 *
 * The row actions are dispatched through the INSTALLED library's own index
 * page dispatch (`dispatchAction`, which CnIndexPage's `mergedActions` runs on
 * every declared row action), with the handler map main.js hands the app
 * (`customComponents`, spread from manifestActions). A handler missing from
 * that map is stripped by the library, so the menu entry would do nothing.
 *
 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// The dispatch module reads the current user for @-tokens; nobody is signed in.
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => null,
	getRequestToken: () => '',
	onRequestTokenUpdate: () => {},
}))
const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
const dialogs = {
	showError: vi.fn(),
	showSuccess: vi.fn(),
}
vi.mock('@nextcloud/dialogs', () => ({
	showError: (...args) => dialogs.showError(...args),
	showSuccess: (...args) => dialogs.showSuccess(...args),
	showInfo: vi.fn(),
	showWarning: vi.fn(),
	getFilePickerBuilder: vi.fn(),
}))
const spawnDialog = vi.fn()
vi.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: (...args) => spawnDialog(...args),
}))

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8')
const FRAGMENT = 'src/manifest.d/receivables-refunds-to-pay.json'

const { manifestActions } = await import('../../src/manifestActions.js')
const { dispatchAction } = await import(
	'@conduction/nextcloud-vue/dist/esm/components/CnIndexPage/manifestActionDispatch.js'
)

/**
 * The fragment, or an empty one when it does not exist yet.
 *
 * @return {object} The parsed fragment.
 */
function fragment() {
	if (!fs.existsSync(path.join(ROOT, FRAGMENT))) {
		return { menu: [], pages: [] }
	}
	return JSON.parse(read(FRAGMENT))
}

/**
 * The page, or an empty stand-in.
 *
 * @return {object} The RefundsToPay page.
 */
function page() {
	return (
		fragment().pages.find((candidate) => candidate.id === 'RefundsToPay') ?? {
			config: { actions: [] },
		}
	)
}

/**
 * Resolve a row action the way the installed CnIndexPage does, with the
 * handler map the app hands it.
 *
 * @param {string} id The action id.
 * @return {object} The action with its handler resolved, or stripped.
 */
function resolvedAction(id) {
	const declared = (page().config.actions ?? []).find(
		(action) => action.id === id,
	)
	expect(declared, id).toBeTruthy()
	return dispatchAction(declared, {
		router: null,
		rowKey: 'id',
		registry: {},
		customComponents: { ...manifestActions },
	})
}

/**
 * A payment request in refund_requested whose newest refund is in this state,
 * shaped like the PaymentRequest schema (refunds oldest first).
 *
 * @param {string} refundState The newest refund's state.
 * @return {object} The row.
 */
function requestRow(refundState) {
	return {
		id: 'pr-0042',
		paymentReference: 'RF18 0042',
		administrationId: 'adm-1',
		amount: 45,
		state: 'refund_requested',
		refunds: [
			{
				amount: 45,
				state: refundState,
				requestedBy: 'larpinq',
				reason: 'Event cancelled',
			},
		],
	}
}

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.post.mockReset()
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
	spawnDialog.mockReset()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('the Refunds to pay page', () => {
	it('lists payment requests waiting for a refund, from the menu', () => {
		const refunds = page()
		expect(refunds.type).toBe('index')
		expect(refunds.config.register).toBe('shillinq')
		expect(refunds.config.schema).toBe('PaymentRequest')
		expect(refunds.config.filter).toEqual({ state: 'refund_requested' })
		expect(refunds.config.detailRoute).toBe('PaymentRequestDetail')
		expect(refunds.config.showAdd).toBe(false)
		const menu = fragment().menu.find((entry) => entry.route === 'RefundsToPay')
		expect(menu.label).toBe('Refunds to pay')
		expect(JSON.parse(read('src/menu-layout.json')).relocations.RefundsToPay).toBe(
			'Sales',
		)
	})

	it('labels its actions with short labels, not descriptions', () => {
		const labels = (page().config.actions ?? []).map((action) => action.label)
		expect(labels).toEqual(['Approve', 'Mark paid'])
	})
})

describe('Approve, through the index page dispatch', () => {
	it('posts the approval for the row and reports it', async () => {
		axiosMock.post.mockResolvedValue({
			data: { state: 'refund_requested', refunds: [{ state: 'approved' }] },
		})
		const approve = resolvedAction('approve-refund')
		expect(typeof approve.handler).toBe('function')

		await approve.handler(requestRow('requested'))

		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/payment-requests/pr-0042/refund/approve',
		)
		expect(dialogs.showSuccess).toHaveBeenCalledTimes(1)
	})

	it('posts nothing for a refund that is already approved', async () => {
		const approve = resolvedAction('approve-refund')
		await approve.handler(requestRow('approved'))
		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(dialogs.showError).toHaveBeenCalledTimes(1)
	})

	it('shows the refusal in the words the server used', async () => {
		axiosMock.post.mockRejectedValue({
			response: {
				status: 403,
				data: { error: 'Approving a refund needs the payment.administer action.' },
			},
		})
		const approve = resolvedAction('approve-refund')
		await approve.handler(requestRow('requested'))
		expect(dialogs.showError).toHaveBeenCalledWith(
			'Approving a refund needs the payment.administer action.',
		)
	})
})

describe('Mark paid, through the index page dispatch', () => {
	it('opens the bank payment dialog for an approved refund', async () => {
		const RefundPaidModal = (await import('../../src/modals/RefundPaidModal.vue'))
			.default
		const markPaid = resolvedAction('mark-refund-paid')
		expect(typeof markPaid.handler).toBe('function')

		const row = requestRow('approved')
		markPaid.handler(row)

		expect(spawnDialog).toHaveBeenCalledTimes(1)
		expect(spawnDialog.mock.calls[0][0] === RefundPaidModal).toBe(true)
		expect(spawnDialog.mock.calls[0][1].paymentRequest).toEqual(row)
	})

	it('asks for approval first when the refund is not approved yet', () => {
		const markPaid = resolvedAction('mark-refund-paid')
		markPaid.handler(requestRow('requested'))
		expect(spawnDialog).not.toHaveBeenCalled()
		expect(dialogs.showError).toHaveBeenCalledTimes(1)
	})
})

describe('the bank payment dialog', () => {
	/**
	 * A RefundPaidModal instance with its methods and computed bound, no DOM.
	 *
	 * @param {object} data Data overrides.
	 * @return {object} The instance.
	 */
	async function modal(data = {}) {
		const RefundPaidModal = (await import('../../src/modals/RefundPaidModal.vue'))
			.default
		const instance = {
			paymentRequest: requestRow('approved'),
			$emit: vi.fn(),
			...RefundPaidModal.data.call({}),
			...data,
		}
		for (const [name, getter] of Object.entries(RefundPaidModal.computed ?? {})) {
			Object.defineProperty(instance, name, { get: () => getter.call(instance) })
		}
		for (const [name, method] of Object.entries(RefundPaidModal.methods)) {
			instance[name] = method.bind(instance)
		}
		return instance
	}

	it('labels its account select with inputLabel', () => {
		const source = read('src/modals/RefundPaidModal.vue')
		const selects = source.match(/<NcSelect[\s\S]*?\/>/g) ?? []
		expect(selects.length).toBe(1)
		expect(selects[0]).toMatch(/:inputLabel="t\('shillinq', 'Bank account'\)"/)
	})

	it('offers the ledger accounts of the request administration', async () => {
		axiosMock.get.mockResolvedValue({
			data: {
				results: [
					{ accountNumber: '1100', name: 'Bank' },
					{ accountNumber: '1110', name: 'Savings' },
				],
			},
		})
		const dialog = await modal()
		await dialog.loadAccounts()
		expect(axiosMock.get).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/Account',
			{ params: { administrationId: 'adm-1', _limit: 1000 } },
		)
		expect(dialog.accountOptions).toEqual([
			{ value: '1100', label: '1100 Bank' },
			{ value: '1110', label: '1110 Savings' },
		])
	})

	it('sends the bank reference and account the controller reads', async () => {
		axiosMock.post.mockResolvedValue({
			data: { state: 'refunded', refunds: [{ state: 'paid' }] },
		})
		const dialog = await modal({
			bankReference: '  NL-2026-10-05-17  ',
			bankAccount: { value: '1100', label: '1100 Bank' },
		})
		await dialog.submit()

		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/payment-requests/pr-0042/refund/paid',
			{ bankReference: 'NL-2026-10-05-17', bankAccount: '1100' },
		)
		const controller = read('lib/Controller/ObjectRequestRefundController.php')
		expect(controller).toContain(
			'public function markPaid(string $id, string $bankReference = \'\', string $bankAccount = \'\')',
		)
		expect(dialog.$emit).toHaveBeenCalledWith('close', {
			state: 'refunded',
			refunds: [{ state: 'paid' }],
		})
	})

	it('sends nothing without a bank reference and an account', async () => {
		const dialog = await modal({ bankReference: ' ', bankAccount: null })
		await dialog.submit()
		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(dialog.error).not.toBe('')
	})

	it('shows the refusal in the words the server used', async () => {
		axiosMock.post.mockRejectedValue({
			response: {
				status: 400,
				data: {
					error: 'The refund is requested, so it cannot take this step; it must be approved first.',
				},
			},
		})
		const dialog = await modal({
			bankReference: 'NL-17',
			bankAccount: { value: '1100', label: '1100 Bank' },
		})
		await dialog.submit()
		expect(dialog.error).toBe(
			'The refund is requested, so it cannot take this step; it must be approved first.',
		)
		expect(dialog.$emit).not.toHaveBeenCalled()
	})
})

describe('the routes the actions call', () => {
	it('exist in appinfo/routes.php', () => {
		const routes = read('appinfo/routes.php')
		expect(routes).toContain(
			"'url' => '/api/payment-requests/{id}/refund/approve', 'verb' => 'POST'",
		)
		expect(routes).toContain(
			"'url' => '/api/payment-requests/{id}/refund/paid', 'verb' => 'POST'",
		)
	})
})
