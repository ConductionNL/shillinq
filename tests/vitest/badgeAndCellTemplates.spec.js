/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Two small components rendered from their real templates: the scanner's
 * sync status badge (colour, label per sync state, the conflict list) and
 * the budget grid cell (budget only, actual, a text-labelled deviation, an
 * account row).
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T4.2
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-004
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { renderTemplate } from './helpers/renderTemplate.js'

const store = vi.hoisted(() => ({ value: {} }))
vi.mock('../../src/store/modules/inventoryMobileScanner.js', () => ({ useInventoryMobileScannerStore: () => store.value }))

const { default: SyncStatusBadge } = await import('../../src/components/inventory/SyncStatusBadge.vue')
const { default: BudgetGridCell } = await import('../../src/components/BudgetGridCell.vue')

const NOW = Date.parse('2026-06-01T12:00:00Z')

/**
 * The text of the HTML, tags removed and whitespace collapsed.
 *
 * @param {string} html The HTML.
 * @return {string} The text.
 */
const text = (html) => html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim()

/**
 * Render the badge for a store state, at NOW.
 *
 * @param {object} state Store fields.
 * @return {Promise<string>} The HTML.
 */
function badge(state) {
	store.value = { syncState: 'idle', statusLabel: 'Sync status', conflicts: [], pendingCount: 0, lastSyncedAt: null, ...state }
	return renderTemplate(SyncStatusBadge, { data: { now: NOW } })
}

/**
 * The colour modifier of the rendered badge.
 *
 * @param {string} html The HTML.
 * @return {string} green, yellow or red.
 */
const colour = (html) => html.match(/sync-status-badge__button--(\w+)/)[1]

/**
 * An amount as the grid writes it.
 *
 * @param {number} cents The amount in cents.
 * @return {string} The formatted amount.
 */
const eur = (cents) => new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cents / 100)

describe('SyncStatusBadge template', () => {
	afterEach(() => {
		store.value = {}
	})

	it('is green with the last sync time when synced recently', async () => {
		const html = await badge({ lastSyncedAt: '2026-06-01T11:59:00Z' })
		const at = new Date('2026-06-01T11:59:00Z').toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
		expect(colour(html)).toBe('green')
		expect(text(html)).toBe(`Last synced ${at}`)
		expect(html).toContain('aria-label="Sync status"')
	})

	it('turns yellow when the last sync is stale, pending, syncing or failed', async () => {
		expect(colour(await badge({ lastSyncedAt: '2026-06-01T11:50:00Z' }))).toBe('yellow')
		const pending = await badge({ syncState: 'pending', pendingCount: 3 })
		expect([colour(pending), text(pending)]).toEqual(['yellow', 'Pending (3)'])
		const syncing = await badge({ syncState: 'syncing' })
		expect([colour(syncing), text(syncing)]).toEqual(['yellow', 'Syncing…'])
		const failed = await badge({ syncState: 'failed', retryInMs: 4200 })
		expect([colour(failed), text(failed)]).toEqual(['yellow', 'Sync failed; retry 5s'])
		expect(text(await badge({ syncState: 'failed' }))).toBe('Sync failed; retry 0s')
	})

	it('is red offline, and invites a sync when it never synced or cannot read the time', async () => {
		const offline = await badge({ syncState: 'offline' })
		expect([colour(offline), text(offline)]).toEqual(['red', 'Offline'])
		expect(text(await badge({}))).toBe('Sync now')
		const unreadable = await badge({ lastSyncedAt: 'yesterday' })
		expect([colour(unreadable), text(unreadable)]).toEqual(['green', 'Last synced yesterday'])
	})

	it('lists every merge conflict with a dismiss button', async () => {
		const html = await badge({ conflicts: [{ applied: 2, at: '12:01' }, { applied: 1, at: '12:03' }] })
		expect(text(html)).toContain('Stock was updated by another user. 2 record(s) merged at 12:01. Dismiss')
		expect(text(html)).toContain('1 record(s) merged at 12:03. Dismiss')
		expect(html.match(/role="status"/g)).toHaveLength(2)
	})
})

describe('BudgetGridCell template', () => {
	const cell = (props) => renderTemplate(BudgetGridCell, { props })

	it('shows a dash without a cell, and only the actual on an account row', async () => {
		expect(text(await cell({}))).toBe('—')
		expect(text(await cell({ cell: { actual: 12345, budget: 99 }, isAccount: true }))).toBe(eur(12345))
	})

	it('shows a future column as the budget alone', async () => {
		expect(text(await cell({ cell: { budget: 50000, actual: null } }))).toBe(eur(50000))
	})

	it('labels a favourable and an unfavourable deviation in words', async () => {
		const good = await cell({ cell: { budget: 50000, actual: 40000, deviation: 10000, favorable: true } })
		expect(text(good)).toBe(`${eur(50000)} Actual: ${eur(40000)} Favorable: ${eur(10000)}`)
		expect(good).toContain('budget-grid-cell__deviation--favorable')
		const bad = await cell({ cell: { budget: 50000, actual: 60000, deviation: -10000, favorable: 0 } })
		expect(text(bad)).toBe(`${eur(50000)} Actual: ${eur(60000)} Unfavorable: ${eur(-10000)}`)
		expect(bad).toContain('budget-grid-cell__deviation--unfavorable')
	})

	it('names a deviation without a direction a difference', async () => {
		expect(text(await cell({ cell: { budget: 100, actual: 100, deviation: 0, favorable: null } }))).toBe(`${eur(100)} Actual: ${eur(100)} Difference: ${eur(0)}`)
	})
})
