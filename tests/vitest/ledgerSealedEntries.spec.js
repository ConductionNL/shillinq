/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * ledger-sealed-entries: Check ledger seal on the general ledger page reaches
 * the seal check, and the dialog reports the chain intact or names the first
 * transaction that no longer matches. The helper, the manifest, the handler
 * map and appinfo/routes.php are held to each other.
 *
 * @spec openspec/changes/ledger-sealed-entries/specs/bookkeeping-audit-trail/spec.md#requirement-a-controller-checks-the-seal-chain-req-lse-002
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8')

const axiosMock = { get: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
const spawnDialog = vi.fn()
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog }))
vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcDialog: { name: 'NcDialog', setup: (props, { slots }) => () => h('div', { class: 'nc-dialog' }, [slots.default?.(), slots.actions?.()]) },
		NcButton: { name: 'NcButton', setup: (props, { slots }) => () => h('button', slots.default?.()) },
	}
})

const api = await import('../../src/utils/ledgerSealApi.js')
const { default: LedgerSealCheckModal } = await import('../../src/modals/LedgerSealCheckModal.vue')

/**
 * A plain instance of the dialog, with its data, methods and computed.
 *
 * @param {object} state Data to set after its own.
 * @return {object} The instance.
 */
function dialog(state = {}) {
	const self = { $emit: vi.fn() }
	for (const [name, method] of Object.entries(LedgerSealCheckModal.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	Object.assign(self, LedgerSealCheckModal.data.call(self), state)
	for (const [name, getter] of Object.entries(LedgerSealCheckModal.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

/**
 * Render the dialog with part of its data replaced.
 *
 * @param {object} state Data to lay over its own.
 * @return {Promise<string>} The HTML.
 */
async function render(state = {}) {
	const view = {
		...LedgerSealCheckModal,
		mounted() {},
		data() {
			return { ...LedgerSealCheckModal.data.call(this), ...state }
		},
	}
	return (await renderToString(createSSRApp(view, {}))).replace(/<!--[[\]]-->/g, '')
}

beforeEach(() => {
	axiosMock.get.mockReset()
	spawnDialog.mockReset()
})

describe('The seal check request', () => {
	it('asks the route the app declares, for one administration', async () => {
		axiosMock.get.mockResolvedValue({ data: { intact: true, entries: 3 } })
		expect(await api.checkLedgerSeal('adm-1')).toEqual({ intact: true, entries: 3 })
		expect(axiosMock.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/ledger/seal-check', { params: { administrationId: 'adm-1' } })
		expect(read('appinfo/routes.php')).toContain("['name' => 'ledgerSeal#verify', 'url' => '/api/v1/ledger/seal-check', 'verb' => 'GET']")
	})

	it('shows the server sentence of a refusal, else the fallback', () => {
		expect(api.sealCheckError({ response: { data: { message: 'Only a controller can check the ledger seal.' } } }, 'x')).toBe('Only a controller can check the ledger seal.')
		expect(api.sealCheckError(new Error('network'), 'The ledger seal could not be checked.')).toBe('The ledger seal could not be checked.')
	})

	it('is a header action on the general ledger page through a registered handler', async () => {
		const manifest = JSON.parse(read('src/manifest.json'))
		const page = manifest.pages.find((p) => p.id === 'GeneralLedger')
		expect(page.config.headerActions).toEqual([expect.objectContaining({ id: 'check-ledger-seal', label: 'Check ledger seal', type: 'handler', handler: 'openLedgerSealCheck' })])
		const handlers = read('src/manifestActions.js')
		expect(handlers).toContain("import { openLedgerSealCheck } from './utils/ledgerSealApi.js'")
		expect(handlers).toMatch(/manifestActions = Object\.freeze\(\{[\s\S]*\n\topenLedgerSealCheck,\n/)
		api.openLedgerSealCheck()
		expect(spawnDialog).toHaveBeenCalledWith(LedgerSealCheckModal, {})
	})
})

describe('LedgerSealCheckModal', () => {
	it('checks the active administration and keeps the result', async () => {
		axiosMock.get.mockResolvedValue({ data: { intact: true, entries: 3, message: 'The ledger seal chain is intact over 3 entries.' } })
		const self = dialog({ administrationId: 'adm-1' })
		await self.check()
		expect(self.result.intact).toBe(true)
		expect(self.checking).toBe(false)
		expect(self.error).toBe('')
	})

	it('says so without an administration and sends nothing', async () => {
		const self = dialog()
		await self.check()
		expect(axiosMock.get).not.toHaveBeenCalled()
		expect(self.error).toBe('Choose an administration first.')
	})

	it('shows the refusal the server sends', async () => {
		axiosMock.get.mockRejectedValue({ response: { status: 403, data: { message: 'Only a controller can check the ledger seal.' } } })
		const self = dialog({ administrationId: 'adm-1' })
		await self.check()
		expect(self.result).toBeNull()
		expect(self.error).toBe('Only a controller can check the ledger seal.')
	})

	it('renders an intact chain as a status', async () => {
		const html = await render({ result: { intact: true, entries: 3, message: 'The ledger seal chain is intact over 3 entries.' } })
		expect(html).toMatch(/data-testid="ledger-seal-intact"[^>]*>\s*The ledger seal chain is intact over 3 entries\./)
		expect(html).not.toContain('ledger-seal-broken')
	})

	it('names the first changed transaction and why', async () => {
		const html = await render({ result: { intact: false, entries: 3, transactionNumber: '2026-0902', reason: 'content', message: 'Transaction 2026-0902 is the first entry that no longer matches its seal.' } })
		expect(html).toMatch(/role="alert"[^>]*data-testid="ledger-seal-broken"[^>]*>\s*Transaction 2026-0902/)
		expect(html).toMatch(/data-testid="ledger-seal-reason"[^>]*>\s*Its content changed after it was posted\./)
	})
})
