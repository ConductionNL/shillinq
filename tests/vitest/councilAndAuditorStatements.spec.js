/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * public-sector-council-and-auditor-statements 2.2 and 3.2: the Draw sample
 * action and aggregation panel on the audit protocol page, and the XML and
 * statement downloads on an ENSIA cycle. The helpers' requests are the ones
 * the controllers read, the manifest names handlers that exist and URLs the
 * routes serve, and the dialog sends what it was given.
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-bado-controleprotocol/spec.md
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8')
const fragment = JSON.parse(read('src/manifest.d/bookkeeping-bado-controleprotocol.json'))
const manifest = JSON.parse(read('src/manifest.json'))
const routes = read('appinfo/routes.php')
const actionsJs = read('src/manifestActions.js')

const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) =>
		'/index.php' + url.replace(/{(\w+)}/g, (m, k) => (k in params ? params[k] : m)),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)),
}))
const showError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showError }))
const spawnDialog = vi.fn()
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({ name, render() { return h('div', this.$slots.default?.()) } })
	return { NcDialog: stub('NcDialog'), NcButton: stub('NcButton'), NcTextField: stub('NcTextField') }
})

const { drawFields, drawAuditSample, sampleError, openAuditSampleDraw } = await import('../../src/utils/auditSampleApi.js')
const { downloadEnsiaXml, downloadEnsiaStatement, ensiaFileName } = await import('../../src/utils/ensiaApi.js')
const { default: AuditSampleDrawModal } = await import('../../src/modals/AuditSampleDrawModal.vue')

/**
 * A plain instance of the dialog, its computed values readable.
 *
 * @param {object} over Data to set first.
 * @return {object} The instance.
 */
function instance(over = {}) {
	const self = { $emit: vi.fn(), protocolId: 'prot-1' }
	Object.assign(self, AuditSampleDrawModal.data.call(self), over)
	for (const [name, method] of Object.entries(AuditSampleDrawModal.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	for (const [name, getter] of Object.entries(AuditSampleDrawModal.computed)) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

let saved = []

beforeEach(() => {
	vi.clearAllMocks()
	saved = []
	globalThis.document = {
		body: { appendChild: () => {} },
		createElement: () => {
			const link = { click: () => saved.push(link.download), remove: () => {} }
			return link
		},
	}
	globalThis.window = { location: { pathname: '/apps/shillinq/compliance/ensia/cycles/c-path' } }
	vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:x')
	vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
})

afterEach(() => {
	delete globalThis.document
	delete globalThis.window
	vi.restoreAllMocks()
})

describe('the draw request', () => {
	it('keeps whole positive numbers and the trimmed prefix, and drops an empty seed', () => {
		expect(drawFields({ size: ' 25 ', seed: '20261', accountPrefix: ' 40 ' })).toEqual({ size: 25, seed: 20261, account_prefix: '40' })
		expect(drawFields({ size: 5, seed: '', accountPrefix: '' })).toEqual({ size: 5 })
	})

	it('refuses a size or seed that is not a positive whole number', () => {
		for (const input of [{ size: '0' }, { size: '2.5' }, { size: 'ten' }, { size: '5', seed: '-1' }, { size: '' }]) {
			expect(drawFields(input)).toBeNull()
		}
	})

	it('posts the protocol and the fields to the samples route', async () => {
		axiosMock.post.mockResolvedValue({ data: { id: 's1' } })
		expect(await drawAuditSample('prot-1', { size: 25 })).toEqual({ id: 's1' })
		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/bado/controleprotocol/samples', { protocol_id: 'prot-1', size: 25 })
		expect(routes).toContain("['name' => 'auditSample#draw', 'url' => '/api/bado/controleprotocol/samples', 'verb' => 'POST']")
	})

	it('shows the server reason for a refused size and a sentence for the rest', () => {
		expect(sampleError({ response: { status: 422, data: { error: 'A sample must hold between 1 and 10 lines; 11 were asked.' } } }))
			.toBe('A sample must hold between 1 and 10 lines; 11 were asked.')
		expect(sampleError({ response: { status: 404 } })).toBe('This audit protocol was not found.')
		expect(sampleError(new Error('network'))).toBe('The sample could not be drawn.')
	})

	it('opens the dialog for the protocol on the page', () => {
		openAuditSampleDraw({ item: { id: 'prot-1' } })
		expect(spawnDialog).toHaveBeenCalledWith(AuditSampleDrawModal, { protocolId: 'prot-1' })
	})
})

describe('the draw dialog', () => {
	it('draws, keeps the seed and says what was stored', async () => {
		axiosMock.post.mockResolvedValue({ data: { id: 's1', sampledLineIds: ['a', 'b'], reproducibleSeed: '777', populationSize: 40 } })
		const self = instance({ size: '2', seed: '', accountPrefix: '40' })
		await self.submit()

		expect(axiosMock.post).toHaveBeenCalledWith(expect.any(String), { protocol_id: 'prot-1', size: 2, account_prefix: '40' })
		expect(self.seed).toBe('777')
		expect(self.resultText).toBe('2 of 40 lines drawn with seed 777.')
		self.onClose()
		expect(self.$emit).toHaveBeenCalledWith('close', self.drawn)
	})

	it('refuses a malformed size without asking the server, and shows a refusal', async () => {
		const self = instance({ size: '0' })
		await self.submit()
		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(self.error).toBe('The number of lines and the seed are positive whole numbers.')

		axiosMock.post.mockRejectedValue({ response: { status: 422, data: { error: 'Too many.' } } })
		const refused = instance({ size: '99' })
		await refused.submit()
		expect(refused.error).toBe('Too many.')
		expect(refused.drawn).toBeNull()
		expect(refused.submitting).toBe(false)
	})
})

describe('the ENSIA downloads', () => {
	it('downloads the XML and the statement of the cycle on the page under the server name', async () => {
		axiosMock.get.mockResolvedValue({ data: new Blob(['<x/>']), headers: { 'content-disposition': 'attachment; filename="ensia-gem-2026.xml"' } })
		expect(await downloadEnsiaXml({ item: { id: 'c1' } })).toBe('ensia-gem-2026.xml')
		expect(axiosMock.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/public-sector/ensia/c1/xml', { responseType: 'blob' })

		axiosMock.get.mockResolvedValue({ data: 'PK', headers: {} })
		expect(await downloadEnsiaStatement({ item: { id: 'c1' } })).toBe('college-verklaring-ensia.docx')
		expect(axiosMock.get).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/v1/public-sector/ensia/c1/statement', { responseType: 'blob' })
		expect(saved).toEqual(['ensia-gem-2026.xml', 'college-verklaring-ensia.docx'])

		await downloadEnsiaXml({})
		expect(axiosMock.get).toHaveBeenLastCalledWith('/index.php/apps/shillinq/api/v1/public-sector/ensia/c-path/xml', { responseType: 'blob' })
		expect(routes).toContain("'url' => '/api/v1/public-sector/ensia/{id}/xml', 'verb' => 'GET'")
		expect(routes).toContain("'url' => '/api/v1/public-sector/ensia/{id}/statement', 'verb' => 'GET'")
	})

	it('says what is missing on a 409 and that it failed otherwise', async () => {
		axiosMock.get.mockRejectedValue({ response: { status: 409 } })
		expect(await downloadEnsiaXml({ item: { id: 'c1' } })).toBeNull()
		expect(showError).toHaveBeenLastCalledWith('Submit the cycle before downloading its XML.')

		axiosMock.get.mockRejectedValue({ response: { status: 500 } })
		expect(await downloadEnsiaStatement({ item: { id: 'c1' } })).toBeNull()
		expect(showError).toHaveBeenLastCalledWith('The college statement could not be downloaded.')
	})

	it('reads the file name from the header', () => {
		expect(ensiaFileName({ 'content-disposition': 'attachment; filename=a.xml' }, 'b.xml')).toBe('a.xml')
		expect(ensiaFileName({}, 'b.xml')).toBe('b.xml')
	})
})

describe('the pages', () => {
	it('puts Draw sample and the aggregation over the protocol endpoint on the protocol page', () => {
		const config = fragment.pages.find((p) => p.id === 'AuditProtocolDetail').config
		expect(config.headerActions.map((a) => a.handler)).toEqual(['openAuditSampleDraw'])
		const widgets = Object.fromEntries(config.widgets.map((w) => [w.id, w]))
		expect(config.band.map((b) => b.widgetId).every((id) => id in widgets)).toBe(true)
		expect(widgets['audit-aggregation'].content.endpointSource).toEqual({
			url: '/apps/shillinq/api/bado/controleprotocol/aggregation?protocol_id=@objectId',
			responsePath: 'topics',
		})
		expect(widgets['audit-aggregation'].content.columns.map((c) => c.key)).toContain('verdict')
		expect(widgets['audit-opinion'].content.valueField).toBe('proposedOpinion')
		expect(routes).toContain("'url' => '/api/bado/controleprotocol/aggregation', 'verb' => 'GET'")
	})

	it('shows each ENSIA download once its file is stored, through a registered handler', () => {
		const config = manifest.pages.find((p) => p.id === 'ENSIACycleDetail').config
		const actions = Object.fromEntries(config.headerActions.map((a) => [a.handler, a.visibleWhen.field]))
		expect(actions).toEqual({ downloadEnsiaStatement: 'declarationDocx', downloadEnsiaXml: 'submissionXml' })
		for (const handler of ['openAuditSampleDraw', 'downloadEnsiaXml', 'downloadEnsiaStatement']) {
			expect(actionsJs).toMatch(new RegExp(`^\\t${handler},$`, 'm'))
		}
	})
})
