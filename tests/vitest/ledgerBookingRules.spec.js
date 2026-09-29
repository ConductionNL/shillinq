/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * ledger-booking-rules: the booking lines show each account's guidance
 * (REQ-LBR-003), and the posting restrictions have their settings pages
 * (REQ-LBR-004). The manifest fragment, the registry, the settings foldout
 * and the helper that pairs a line with its account are held to each other.
 *
 * @spec openspec/changes/ledger-booking-rules/tasks.md#task-3.2
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(
	fs.readFileSync(
		path.join(ROOT, 'src/manifest.d/ledger-booking-rules.json'),
		'utf8',
	),
)
const menuLayout = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src/menu-layout.json'), 'utf8'),
)
const registryJs = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))

const { guidanceRows, loadGuidanceRows, objectsUrl } =
	await import('../../src/utils/accountGuidance.js')

describe('ledger-booking-rules pages', () => {
	const pages = Object.fromEntries(fragment.pages.map((page) => [page.id, page]))

	it('renders the lines panel on both detail pages through a registered slot', () => {
		for (const [pageId, slot, widget] of [
			['JournalDetail', 'JournalLinesGuidance', 'journal-lines'],
			['GeneralLedgerDetail', 'TransactionLinesGuidance', 'transaction-lines'],
		]) {
			const page = pages[pageId]
			expect(page.slots['widget-' + widget]).toBe(slot)
			expect(page.config.widgets.map((w) => w.id)).toContain(widget)
			expect(page.config.lifecycleActions).toBe(true)
			expect(registryJs).toMatch(new RegExp('\\b' + slot + ': \\{'))
		}
	})

	it('lists the posting restrictions under the settings gear', () => {
		expect(pages.PostingRestrictions.config.schema).toBe('PostingRestriction')
		expect(pages.PostingRestrictions.config.detailRoute).toBe(
			'PostingRestrictionDetail',
		)
		expect(fragment.menu.map((item) => item.id)).toContain('PostingRestrictions')
		expect(menuLayout.settingsSection).toContain('PostingRestrictions')
	})
})

describe('guidanceRows', () => {
	it('puts the account name and its guidance on each line', () => {
		const rows = guidanceRows(
			[
				{ accountNumber: '4000', side: 'debit', amount: 1200 },
				{ accountNumber: '1100', side: 'credit', amount: 1200 },
				{ accountNumber: '9999', side: 'credit', amount: 0 },
			],
			[
				{
					accountNumber: '4000',
					name: 'Huisvesting',
					description:
						'Huur, energie en schoonmaak van het kantoor. Niet voor thuiswerkvergoedingen.',
				},
				{
					accountNumber: '1100',
					name: 'Debiteuren',
					controlAccountFor: 'receivables',
				},
			],
		)

		expect(rows[0].accountName).toBe('Huisvesting')
		expect(rows[0].guidance).toBe(
			'Huur, energie en schoonmaak van het kantoor. Niet voor thuiswerkvergoedingen.',
		)
		expect(rows[1].controlAccountFor).toBe('receivables')
		expect(rows[1].guidance).toBe('')
		expect(rows[2].accountName).toBe('')
	})

	it("reads a ledger transaction's lines and only the accounts on them", async () => {
		get.mockReset()
		get.mockImplementation((url) => {
			if (url.includes('/GLTransaction/gl-1')) {
				return Promise.resolve({
					data: { id: 'gl-1', administrationId: 'adm-1' },
				})
			}
			if (url.includes('/GLLine')) {
				return Promise.resolve({
					data: {
						results: [
							{
								lineNumber: 2,
								accountNumber: '1100',
								side: 'credit',
								amount: 5,
							},
							{
								lineNumber: 1,
								accountNumber: '4000',
								side: 'debit',
								amount: 5,
							},
						],
					},
				})
			}
			const number = new URL('http://x' + url).searchParams.get(
				'accountNumber',
			)
			return Promise.resolve({
				data: { results: [{ accountNumber: number, name: 'A' + number }] },
			})
		})

		const rows = await loadGuidanceRows('GLTransaction', 'gl-1')

		expect(rows.map((row) => row.accountNumber)).toEqual(['4000', '1100'])
		expect(rows[0].accountName).toBe('A4000')
		const accountCalls = get.mock.calls.filter(([url]) =>
			url.includes('/Account?'),
		)
		expect(accountCalls).toHaveLength(2)
		expect(accountCalls[0][0]).toContain('administrationId=adm-1')
	})

	it('builds a filtered object list url', () => {
		expect(objectsUrl('Account', { accountNumber: '4000', empty: '' })).toBe(
			'/index.php/apps/openregister/api/objects/shillinq/Account?accountNumber=4000&_limit=200',
		)
	})
})
