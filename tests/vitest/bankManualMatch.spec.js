/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * banking-manual-match: the unmatched items actions reach shillinq.
 *
 * The three bulk actions declared an api-call to
 * "/api/reconciliations/:reconId/matches/bulk-resolve": outside the app
 * route, with a token nothing fills, without the reason the endpoint needs,
 * and CnIndexPage does not dispatch a bulk api-call at all. These tests hold
 * the manifest, the handler map in main.js, the dialogs' URLs and
 * appinfo/routes.php to each other.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'),
)
// The handler map main.js hands to both lookups (live pass S4).
const mainJs = fs.readFileSync(path.join(ROOT, 'src/manifestActions.js'), 'utf8')
const routes = fs.readFileSync(path.join(ROOT, 'appinfo/routes.php'), 'utf8')

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: vi.fn(() => Promise.resolve(null)),
}))
vi.mock('../../src/modals/BankLineMatchModal.vue', () => ({
	default: { name: 'BankLineMatchModal' },
}))
vi.mock('../../src/modals/UnmatchedClassifyDialog.vue', () => ({
	default: { name: 'UnmatchedClassifyDialog' },
}))

const axiosMock = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

/**
 * A page by id.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	return manifest.pages.find((p) => p.id === id)
}

describe('unmatched items actions', () => {
	it('bulk actions name a registered handler and no unreachable URL', async () => {
		const { CLASSIFY_ACTIONS } =
			await import('../../src/utils/bankMatchActions.js')
		const bulk = page('UnmatchedItems').config.bulkActions
		expect(bulk.map((a) => a.id).sort()).toEqual(
			Object.keys(CLASSIFY_ACTIONS).sort(),
		)
		for (const action of bulk) {
			expect(action.handler).toBe('classifyUnmatched')
			expect(action.url).toBeUndefined()
		}
		expect(mainJs).toMatch(/^\tclassifyUnmatched,$/m)
	})

	it('both pages offer match by hand through a registered handler', () => {
		for (const id of ['UnmatchedItems', 'UnmatchedBankLines']) {
			const actions = page(id).config.actions
			expect(actions.find((a) => a.id === 'match-by-hand').handler).toBe(
				'openBankLineMatch',
			)
		}
		expect(mainJs).toMatch(/^\topenBankLineMatch,$/m)
	})

	it('the endpoints the dialogs call are routed', () => {
		expect(routes).toContain(
			"'url' => '/api/v1/bank-lines/{lineId}/match', 'verb' => 'POST'",
		)
		expect(routes).toContain(
			"'url'  => '/api/reconciliations/{reconId}/matches/bulk-resolve'",
		)
	})
})

describe('lineIdOfRow', () => {
	it('reads the line from a match row or a line row', async () => {
		const { lineIdOfRow } = await import('../../src/utils/bankMatchActions.js')
		expect(lineIdOfRow({ id: 'm1', bankStatementLineId: 'uuid-line' })).toBe(
			'uuid-line',
		)
		expect(lineIdOfRow({ id: 'm1', bankLineId: 'L-7' })).toBe('L-7')
		expect(lineIdOfRow({ id: 'uuid-line', statementId: 's1' })).toBe('uuid-line')
		expect(lineIdOfRow({ id: 'm1' })).toBe('')
	})
})

describe('classifyUnmatchedItems', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('posts one call per reconciliation with the matches and the reason', async () => {
		const recon = { a: 'recon-1', b: 'recon-2', c: 'recon-1' }
		axiosMock.get.mockImplementation((url) =>
			Promise.resolve({ data: { reconId: recon[url.split('/').pop()] } }),
		)
		axiosMock.post.mockImplementation((url, body) =>
			Promise.resolve({ data: { applied: body.matchIds.length, failed: {} } }),
		)

		const { classifyUnmatchedItems } =
			await import('../../src/utils/bankMatchApi.js')
		const result = await classifyUnmatchedItems(
			['a', 'b', 'c'],
			'timing',
			'Betaling onderweg',
		)

		expect(result).toEqual({ applied: 3, failed: 0 })
		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/reconciliations/recon-1/matches/bulk-resolve',
			{
				matchIds: ['a', 'c'],
				resolutionStatus: 'timing',
				resolutionReason: 'Betaling onderweg',
			},
		)
		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/api/reconciliations/recon-2/matches/bulk-resolve',
			{
				matchIds: ['b'],
				resolutionStatus: 'timing',
				resolutionReason: 'Betaling onderweg',
			},
		)
	})

	it('counts a match without a reconciliation as failed', async () => {
		axiosMock.get.mockResolvedValue({ data: { reconId: '' } })
		const { classifyUnmatchedItems } =
			await import('../../src/utils/bankMatchApi.js')
		expect(await classifyUnmatchedItems(['x'], 'pending', 'r')).toEqual({
			applied: 0,
			failed: 1,
		})
		expect(axiosMock.post).not.toHaveBeenCalled()
	})
})
