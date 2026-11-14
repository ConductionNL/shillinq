/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * receivables-sepa-direct-debit 2.1: the mandate, batch and collection pages
 * reach shillinq. The manifest fragment, the handler map, the helpers' URLs
 * and appinfo/routes.php are held to each other, the request the dialog
 * sends is the one DirectDebitBatchController reads, and every reason the
 * proposal can give has a sentence.
 *
 * @spec openspec/changes/receivables-sepa-direct-debit/specs/bookkeeping-sepa-direct-debit/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.d/receivables-sepa-direct-debit.json'), 'utf8'),
)
const register = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'lib/Settings/register.d/bookkeeping-sepa-direct-debit.json'), 'utf8'),
)
const actionsJs = fs.readFileSync(path.join(ROOT, 'src/manifestActions.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')
const controller = fs.readFileSync(path.join(ROOT, 'lib/Controller/DirectDebitBatchController.php'), 'utf8')
const proposal = fs.readFileSync(path.join(ROOT, 'lib/Service/Receivables/DirectDebitBatchService.php'), 'utf8')

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
const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

const page = (id) => fragment.pages.find((p) => p.id === id)
const schemas = register.components.schemas

describe('the direct debit pages', () => {
	it('puts mandates, batches and collections under Bookkeeping, each with its page', () => {
		const [group] = fragment.menu
		expect(group.id).toBe('Bookkeeping')
		expect(group.children.map((c) => c.route)).toEqual(['SepaMandates', 'DirectDebitBatches', 'DirectDebitCollections'])
		for (const child of group.children) {
			const index = page(child.route)
			expect(index.type).toBe('index')
			expect(page(index.config.detailRoute).config.indexRoute).toBe(index.id)
		}
	})

	it('binds every page to a schema the register ships, with columns it declares', () => {
		for (const p of fragment.pages) {
			const schema = schemas[p.config.schema]
			expect(schema, p.id).toBeDefined()
			const keys = (p.config.columns || []).map((c) => c.key)
			for (const widget of p.config.widgets || []) {
				keys.push(...(widget.content.include || []))
			}
			for (const key of keys) {
				expect(Object.keys(schema.properties), `${p.id}.${key}`).toContain(key)
			}
		}
	})

	it('lists a batch\'s collections on its page by the field the proposal sets', () => {
		const list = page('DirectDebitBatchDetail').config.widgets.find((w) => w.type === 'object-list')
		expect(list.content.filter).toEqual({ submittedInBatchId: '@objectId' })
		expect(proposal).toContain("$line['submittedInBatchId']")
		expect(list.content.rowRoute).toBe('DirectDebitCollectionDetail')
	})

	it('offers generate, settle and reject as the schemas\' transitions, reject asking for its code', () => {
		for (const id of ['DirectDebitBatchDetail', 'DirectDebitCollectionDetail', 'SepaMandateDetail']) {
			expect(page(id).config.lifecycleActions).toBe(true)
		}
		const batch = schemas.DirectDebitBatch['x-openregister-lifecycle'].transitions
		const collection = schemas.DirectDebitCollection['x-openregister-lifecycle'].transitions
		expect(batch.generate.actions[0].action).toContain('GenerateDirectDebitFileAction')
		expect(collection.succeed.actions[0].action).toContain('SettleDirectDebitCollectionAction')
		expect(collection.reject.actions[0].action).toContain('RecordDirectDebitRejectAction')
		expect(collection.reject.inputs).toEqual([{ field: 'pain002ReasonCode', required: true }])
	})

	it('wires both header actions to handlers the action map provides', () => {
		const propose = page('DirectDebitBatches').config.headerActions[0]
		expect(propose.handler).toBe('openProposeDirectDebit')
		const download = page('DirectDebitBatchDetail').config.headerActions[0]
		expect(download.handler).toBe('downloadDirectDebitFile')
		expect(download.visibleWhen).toEqual({ field: 'pain008Xml', op: 'notEmpty' })
		expect(actionsJs).toContain('openProposeDirectDebit,')
		expect(actionsJs).toContain('downloadDirectDebitFile,')
	})
})

describe('Propose direct debit', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
	})

	it('posts to the route the controller serves, with the parameters it reads', async () => {
		const { buildProposalRequest, proposeDirectDebitBatches } = await import('../../src/utils/directDebitApi.js')
		expect(routes).toContain(
			"'directDebitBatch#propose', 'url' => '/api/v1/direct-debit-batches/propose', 'verb' => 'POST'",
		)
		const body = buildProposalRequest({ administrationId: ' adm-1 ', collectionDate: '2026-10-15', extra: 'x' })
		expect(body).toEqual({ administrationId: 'adm-1', collectionDate: '2026-10-15' })
		for (const key of Object.keys(body)) {
			expect(controller).toContain(`getParam('${key}'`)
		}
		axiosMock.post.mockResolvedValue({ data: { batches: [{ id: 'b-1' }], skipped: [] } })
		const result = await proposeDirectDebitBatches(body)
		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/shillinq/api/v1/direct-debit-batches/propose', body)
		expect(result.batches[0].id).toBe('b-1')
	})

	it('reads a 422 as a result with no batches, and passes other errors on', async () => {
		const { proposeDirectDebitBatches } = await import('../../src/utils/directDebitApi.js')
		axiosMock.post.mockRejectedValueOnce({
			response: { status: 422, data: { error: 'nothing-to-collect', skipped: [{ reason: 'no-mandate' }] } },
		})
		const result = await proposeDirectDebitBatches({})
		expect(result.batches).toEqual([])
		expect(result.skipped[0].reason).toBe('no-mandate')

		const failure = { response: { status: 403 } }
		axiosMock.post.mockRejectedValueOnce(failure)
		await expect(proposeDirectDebitBatches({})).rejects.toBe(failure)
	})

	it('totals the batches in cents', async () => {
		const { proposalTotal } = await import('../../src/utils/directDebitApi.js')
		expect(proposalTotal([{ controlSum: 750.1 }, { controlSum: 500.2 }, {}])).toBe(1250.3)
		expect(proposalTotal(undefined)).toBe(0)
	})

	it('has a sentence for every reason the server can give', async () => {
		const { skipReasonText } = await import('../../src/utils/directDebitApi.js')
		const reasons = [...proposal.matchAll(/REASON_\w+ = '([a-z-]+)'/g)].map((m) => m[1])
		expect(reasons).toHaveLength(6)
		for (const reason of reasons) {
			expect(skipReasonText({ reason }), reason).not.toBe('Left out')
		}
		expect(skipReasonText({ reason: 'mandate-inactive', detail: 'cancelled' })).toBe('The mandate is not active (cancelled)')
		expect(skipReasonText({ reason: 'mandate-ended', detail: '2026-09-30' })).toBe('The mandate ended on 2026-09-30')
		expect(skipReasonText({ reason: 'mystery' })).toBe('Left out')
	})
})

describe('Download pain.008', () => {
	let clicked
	beforeEach(() => {
		axiosMock.get.mockReset()
		showError.mockReset()
		clicked = []
		globalThis.document = {
			body: { appendChild: () => {} },
			createElement: () => {
				const link = { click: () => clicked.push({ href: link.href, download: link.download }), remove: () => {} }
				return link
			},
		}
		globalThis.window = { location: { pathname: '/apps/shillinq/bookkeeping/direct-debit-batches/b-path' } }
		vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:pain008')
		vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
	})
	afterEach(() => {
		delete globalThis.document
		delete globalThis.window
		vi.restoreAllMocks()
	})

	it('fetches the batch\'s file from the route the controller serves and saves it', async () => {
		const { downloadDirectDebitFile } = await import('../../src/utils/directDebitApi.js')
		expect(routes).toContain(
			"'directDebitBatch#file', 'url' => '/api/v1/direct-debit-batches/{id}/file', 'verb' => 'GET'",
		)
		axiosMock.get.mockResolvedValue({
			data: '<Document/>',
			headers: { 'content-disposition': 'attachment; filename="DD-2026-10.pain008.xml"' },
		})
		const name = await downloadDirectDebitFile({ item: { id: 'b-1' } })
		expect(axiosMock.get.mock.calls[0][0]).toBe('/index.php/apps/shillinq/api/v1/direct-debit-batches/b-1/file')
		expect(name).toBe('DD-2026-10.pain008.xml')
		expect(clicked).toEqual([{ href: 'blob:pain008', download: 'DD-2026-10.pain008.xml' }])
		expect(showError).not.toHaveBeenCalled()
	})

	it('falls back to the page\'s id and the batch\'s message id', async () => {
		const { downloadDirectDebitFile, downloadFileName } = await import('../../src/utils/directDebitApi.js')
		axiosMock.get.mockResolvedValue({ data: '<Document/>', headers: {} })
		await downloadDirectDebitFile({})
		expect(axiosMock.get.mock.calls[0][0]).toBe('/index.php/apps/shillinq/api/v1/direct-debit-batches/b-path/file')
		expect(downloadFileName({}, { messageId: 'DD-X' })).toBe('DD-X.pain008.xml')
		expect(downloadFileName(undefined, undefined)).toBe('direct-debit-batch.pain008.xml')
	})

	it('says to generate first on a 409, and that it failed otherwise', async () => {
		const { downloadDirectDebitFile } = await import('../../src/utils/directDebitApi.js')
		axiosMock.get.mockRejectedValueOnce({ response: { status: 409 } })
		expect(await downloadDirectDebitFile({ item: { id: 'b-1' } })).toBeNull()
		expect(showError).toHaveBeenLastCalledWith('Generate the pain.008 file before downloading it.')
		axiosMock.get.mockRejectedValueOnce({ response: { status: 404 } })
		await downloadDirectDebitFile({ item: { id: 'b-1' } })
		expect(showError).toHaveBeenLastCalledWith('The pain.008 file could not be downloaded.')
		expect(clicked).toEqual([])
	})
})
