/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The quick draft opens from every page of the app (REQ-IQD-009, task 1.1):
 * the navigation's primary action stays New Booking (the drawn board) and
 * carries Create invoice as its one sub-action, an `open-modal` of
 * InvoiceQuickDraftModal. The navigation is rendered on every page, so the
 * action is there on every page. CnAppNav hands that entry to CnActionButtons
 * outside a page, where there is no cnDispatchAction: the click goes through
 * the INSTALLED library's dispatchAction with the router, the registry and
 * CnAppRoot's cnOpenModal. This runs that dispatch on the purchase invoices
 * page and asserts the modal opens and the route stays. The vitest
 * environment is `node` (no DOM), so the component itself is not mounted;
 * its fallback wiring is pinned against the installed source instead.
 *
 * @spec openspec/changes/sales-quick-draft-from-any-page/tasks.md#task-1.1
 * @spec openspec/changes/sales-quick-draft-from-any-page/specs/shillinq-invoice-quick-draft/spec.md#requirement-the-quick-draft-opens-from-every-page-of-the-app-req-iqd-009
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => null,
	getRequestToken: () => '',
	onRequestTokenUpdate: () => {},
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showSuccess: vi.fn(),
	showInfo: vi.fn(),
	showWarning: vi.fn(),
	getFilePickerBuilder: vi.fn(),
}))

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8')

const LIB = 'node_modules/@conduction/nextcloud-vue/dist/esm'
const { dispatchAction } = await import('@conduction/nextcloud-vue/dist/esm/utils/actionsDispatcher.js')

/**
 * The navigation's primary action of the bundled manifest.
 *
 * @return {object} `nav.primaryAction`.
 */
function primaryAction() {
	return JSON.parse(read('src/manifest.json')).nav.primaryAction
}

/**
 * The CnActionButtons entry CnAppNav's `primaryDispatchEntry` builds from a
 * primary action that carries an `action`.
 *
 * @param {object} action The primary action.
 * @return {object} The entry.
 */
function dispatchEntry(action) {
	const inner = action.action
	return {
		icon: action.icon || 'Plus',
		...inner,
		id: inner.id || action.id || 'primary-action',
		label: action.label,
		variant: 'primary',
	}
}

/**
 * Click a navigation action on the purchase invoices page the way
 * CnActionButtons does outside a page (its `dispatch` fallback).
 *
 * @param {object} action The clicked action.
 * @return {Promise<{ openModal: Function, router: object, route: object }>}
 */
async function clickOnPurchaseInvoices(action) {
	const openModal = vi.fn()
	const route = { name: 'PurchaseInvoices', path: '/purchase-invoices', params: {}, query: {} }
	const router = { currentRoute: { value: route }, push: vi.fn(), replace: vi.fn() }
	await dispatchAction(action, {
		router,
		registry: { InvoiceQuickDraftModal: { kind: 'modal', component: {} } },
		openModal,
		openForm: vi.fn(),
		openRunNode: vi.fn(),
		tokenCtx: {},
		translate: (key) => key,
	})
	return { openModal, router, route }
}

describe('Create invoice in the navigation (REQ-IQD-009)', () => {
	it('keeps New Booking as the primary action and adds Create invoice as its sub-action', () => {
		const action = primaryAction()
		expect(action.label).toBe('New Booking')
		expect(action.action.type).toBe('open-form')
		const children = action.action.children ?? []
		expect(children).toHaveLength(1)
		expect(children[0]).toMatchObject({
			id: 'create-invoice',
			label: 'Create invoice',
			type: 'open-modal',
			target: 'InvoiceQuickDraftModal',
			props: { open: true },
		})
	})

	it('names a modal the registry knows', () => {
		expect(read('src/registry.js')).toMatch(/InvoiceQuickDraftModal: \{ kind: 'modal', component: InvoiceQuickDraftModal \}/)
	})

	it('opens the quick draft over the purchase invoices page and leaves the route as it was', async () => {
		const child = primaryAction().action.children[0]
		const { openModal, router, route } = await clickOnPurchaseInvoices(child)

		expect(openModal).toHaveBeenCalledTimes(1)
		expect(openModal).toHaveBeenCalledWith('InvoiceQuickDraftModal', { open: true })
		expect(router.push).not.toHaveBeenCalled()
		expect(router.replace).not.toHaveBeenCalled()
		expect(router.currentRoute.value).toBe(route)
	})

	it('builds the entry CnAppNav hands to CnActionButtons with the sub-action kept', () => {
		const entry = dispatchEntry(primaryAction())
		expect(entry).toMatchObject({ id: 'new-booking', label: 'New Booking', variant: 'primary', type: 'open-form' })
		expect(entry.children.map((child) => child.id)).toEqual(['create-invoice'])
	})

	it('relies on an installed library whose navigation action opens a registered modal outside a page', () => {
		const nav = read(LIB + '/components/CnAppNav/CnAppNav2.vue.js')
		expect(nav).toMatch(/primaryDispatchEntry/)
		const buttons = read(LIB + '/components/CnActionButtons/CnActionButtons2.vue.js')
		expect(buttons).toMatch(/cnOpenModal/)
		expect(buttons).toMatch(/openModal: typeof this\.cnOpenModal === 'function'/)
	})
})
