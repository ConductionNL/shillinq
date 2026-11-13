/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * DocumentsView rendered with recording stand-ins (tests/vitest/ssrCapture.js)
 * and a CnDataTable stand-in that streams its cell slots: the upload button
 * and the status / type / filed-from filters are used through the handlers
 * the template bound, and each sends the documents request with exactly the
 * filters that are set.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-22
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ssrRenderSlot } from 'vue/server-renderer'
import { session } from './ssrCapture.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const { default: DocumentsView } = await import('../../src/views/bookkeeping/DocumentsView.vue')

const DOCUMENTS = '/index.php/apps/shillinq/api/v1/documents'
const t = (app, text) => text

/**
 * A CnDataTable stand-in: one row per document, each cell its `cell-<key>`
 * slot streamed, or the raw value without one; the empty label without rows.
 */
const CnDataTable = {
	name: 'CnDataTable',
	props: ['columns', 'rows', 'emptyLabel'],
	ssrRender(ctx, push, parent) {
		if (ctx.rows.length === 0) {
			push(`<p data-stub="CnDataTable">${ctx.emptyLabel}</p>`)
			return
		}
		push('<table data-stub="CnDataTable">')
		for (const row of ctx.rows) {
			push('<tr>')
			for (const column of ctx.columns) {
				push(`<td data-key="${column.key}">`)
				ssrRenderSlot(ctx.$slots, 'cell-' + column.key, { row }, () => push(String(row[column.key])), push, parent)
				push('</td>')
			}
			push('</tr>')
		}
		push('</table>')
	},
}

/**
 * Render the view with part of its data replaced.
 *
 * @param {object} state The data to lay over the view's own.
 * @return {Promise<{html: string, view: object}>}
 */
async function documents(state) {
	const view = session()
	const html = await view.render(DocumentsView, { loading: false, ...state }, { t }, [
		'NcAppContent',
		'NcButton',
		['CnDataTable', CnDataTable],
	])
	return { html: html.replace(/<!--[[\]]-->/g, '').replace(/ data-v-[0-9a-f]+/g, ''), view }
}

/**
 * The text of each cell of a column, in row order.
 *
 * @param {string} html The rendered HTML.
 * @param {string} key The column key.
 * @return {Array<string>}
 */
function column(html, key) {
	return [...html.matchAll(new RegExp(`<td data-key="${key}">([\\s\\S]*?)</td>`, 'g'))]
		.map((match) => match[1].trim())
}

const rows = [
	{ documentNumber: 'D-1', documentType: 'receipt', documentDate: '2026-02-01', status: 'archived', fileReference: '/f/1' },
	{ documentNumber: 'D-2', documentType: 'other', documentDate: '2026-02-02', status: 'draft', fileReference: '' },
]

beforeEach(() => {
	axios.get.mockReset()
	vi.stubGlobal('t', t)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('DocumentsView rendering', () => {
	it('streams translated types, statuses and the file link into the table cells', async () => {
		const { html, view } = await documents({ documents: rows, canCreate: true })

		expect(column(html, 'documentNumber')).toEqual(['D-1', 'D-2'])
		expect(column(html, 'documentType')).toEqual(['Receipt', 'other'])
		expect(column(html, 'status')).toEqual(['<span data-status="archived">Archived</span>', '<span data-status="draft">Draft</span>'])
		expect(column(html, 'fileReference')).toEqual([
			'<a href="/f/1" target="_blank" rel="noopener">/f/1</a>',
			'<span>—</span>',
		])
		expect(view.labelled('NcButton', 'Upload Document').variant).toBe('primary')
		expect(html).not.toContain('role="alert"')
	})

	it('shows the loading text instead of the table, and the error', async () => {
		const { html, view } = await documents({ documents: rows, loading: true, errorMessage: 'Failed to load documents.' })
		expect(html).toMatch(/class="wbso-documents__loading"[^>]*>\s*Loading…\s*</)
		expect(html).not.toContain('data-stub="CnDataTable"')
		expect(html).toMatch(/role="alert"[^>]*>\s*Failed to load documents\.\s*</)
		expect(view.labelled('NcButton', 'Upload Document')).toBeUndefined()
	})

	it('emits the upload request through the Upload Document button', async () => {
		const { view } = await documents({ documents: [], canCreate: true })
		view.vm.$.emit = vi.fn()

		view.labelled('NcButton', 'Upload Document').onClick()

		expect(view.vm.$.emit).toHaveBeenCalledWith('upload-document')
	})
})

describe('DocumentsView filters', () => {
	it('reloads with each filter the selects and the date input set', async () => {
		axios.get.mockResolvedValue({ data: { documents: rows, canCreate: true } })
		const { view } = await documents({ documents: [] })
		const [status, type] = view.vnodes((vnode) => vnode.type === 'select')
		const [filedFrom] = view.vnodes((vnode) => vnode.type === 'input')

		status.props['onUpdate:modelValue']('filed')
		await status.props.onChange()
		expect(axios.get).toHaveBeenLastCalledWith(DOCUMENTS, { params: { status: 'filed' } })

		type.props['onUpdate:modelValue']('contract')
		await type.props.onChange()
		expect(axios.get).toHaveBeenLastCalledWith(DOCUMENTS, { params: { status: 'filed', type: 'contract' } })

		filedFrom.props['onUpdate:modelValue']('2026-03-01')
		await filedFrom.props.onChange()
		expect(axios.get).toHaveBeenLastCalledWith(DOCUMENTS, {
			params: { status: 'filed', type: 'contract', filedFrom: '2026-03-01' },
		})
		expect(axios.get).toHaveBeenCalledTimes(3)
		expect([view.vm.documents, view.vm.canCreate, view.vm.loading]).toEqual([rows, true, false])
	})

	it('loads the documents when mounted', () => {
		const self = { load: vi.fn() }
		DocumentsView.mounted.call(self)
		expect(self.load).toHaveBeenCalledOnce()
	})
})
