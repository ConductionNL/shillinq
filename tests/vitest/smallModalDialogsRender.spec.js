/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Three small dialogs rendered server-side (no DOM): the relations export
 * with its download link, the booking-conflict list with its times, and the
 * budget-mapping delete confirmation with its summary and busy state.
 */

import { describe, expect, it, vi } from 'vitest'
import { createSSRApp, h } from 'vue'
import { renderToString } from 'vue/server-renderer'

vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/vue', () => {
	const slotted = (name, tag = 'div') => ({
		name,
		inheritAttrs: false,
		props: ['name', 'disabled', 'href'],
		render() {
			return h(tag, { 'data-stub': name, 'data-name': this.name, 'data-href': this.href, 'data-testid': this.$attrs['data-testid'], disabled: this.disabled || undefined }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
	return { NcDialog: slotted('NcDialog'), NcButton: slotted('NcButton', 'button') }
})

const { default: RelationsExportDialog } = await import('../../src/modals/RelationsExportDialog.vue')
const { default: BookingConflictDialog } = await import('../../src/modals/BookingConflictDialog.vue')
const { default: DeleteBudgetMappingDialog } = await import('../../src/modals/DeleteBudgetMappingDialog.vue')

/**
 * Render a dialog with props and, optionally, its data replaced.
 *
 * @param {object} view The component.
 * @param {object} props The props.
 * @param {object} [state] Data to lay over its own.
 * @return {Promise<string>} The HTML, without fragment comments.
 */
async function render(view, props, state) {
	const options = state ? { ...view, data: () => ({ ...view.data(), ...state }) } : view
	return (await renderToString(createSSRApp(options, props))).replace(/<!--[[\]]-->/g, '')
}

/**
 * The instance methods of a dialog bound to a plain object.
 *
 * @param {object} view The component.
 * @param {object} over Props and data.
 * @return {object} The instance.
 */
function instance(view, over = {}) {
	const self = { ...over }
	if (view.data) {
		Object.assign(self, view.data.call(self), over)
	}
	for (const [name, method] of Object.entries(view.methods)) {
		self[name] = typeof method === 'function' ? method.bind(self) : method
	}
	for (const [name, getter] of Object.entries(view.computed ?? {})) {
		Object.defineProperty(self, name, { get: getter.bind(self) })
	}
	return self
}

describe('RelationsExportDialog', () => {
	it('renders nothing while closed', async () => {
		expect(await render(RelationsExportDialog, { open: false })).not.toContain('relations-export-dialog')
	})

	it('defaults to the current calendar year and links the CSV for that period', async () => {
		const year = new Date().getFullYear()
		const html = await render(RelationsExportDialog, { open: true })
		expect(html).toContain('data-name="Export relations both ways"')
		expect(html).toContain(`value="${year}-01-01"`)
		expect(html).toContain(`value="${year}-12-31"`)
		expect(html).toContain(`data-href="/index.php/apps/shillinq/api/relations/both-sides?format=csv&amp;from=${year}-01-01&amp;to=${year}-12-31"`)
		expect(html).not.toMatch(/data-testid="relations-export-download" disabled/)
	})

	it('disables the download for a missing date or a reversed period', async () => {
		expect(await render(RelationsExportDialog, { open: true }, { from: '2026-12-01', to: '2026-01-01' })).toMatch(/data-testid="relations-export-download" disabled/)
		expect(await render(RelationsExportDialog, { open: true }, { from: '', to: '2026-01-01' })).toMatch(/data-testid="relations-export-download" disabled/)
	})

	it('encodes the dates into the download URL', () => {
		const vm = instance(RelationsExportDialog, { from: '2026-01-01', to: '2026 12&31' })
		expect(vm.downloadUrl).toBe('/index.php/apps/shillinq/api/relations/both-sides?format=csv&from=2026-01-01&to=2026%2012%2631')
	})
})

describe('BookingConflictDialog', () => {
	it('renders nothing while closed', async () => {
		expect(await render(BookingConflictDialog, { open: false })).not.toContain('bk-conflict-dialog')
	})

	it('lists each conflict by title, else by id, with its times in the configured zone', async () => {
		const html = await render(BookingConflictDialog, {
			open: true,
			timeZone: 'UTC',
			conflicts: [
				{ bookingId: 'b-1', title: 'Vergaderzaal A', startTime: '2026-11-13T09:00:00Z', endTime: '2026-11-13T10:30:00Z' },
				{ bookingId: 'b-2', startTime: '', endTime: null },
			],
		})
		expect(html).toContain('Booking conflict detected')
		expect(html).toMatch(/data-testid="bk-conflict-b-1"[^>]*><strong[^>]*>Vergaderzaal A<\/strong>/)
		expect(html).toMatch(/data-testid="bk-conflict-b-2"[^>]*><strong[^>]*>b-2<\/strong>/)
		const vm = instance(BookingConflictDialog, { timeZone: 'UTC' })
		expect(html).toContain(vm.formatTime('2026-11-13T09:00:00Z'))
		expect(vm.formatTime('2026-11-13T09:00:00Z')).toMatch(/09.00/)
	})

	it('formats nothing for no time, and gives the raw value back when the zone is invalid', () => {
		const vm = instance(BookingConflictDialog, { timeZone: 'Not/AZone' })
		expect(vm.formatTime('')).toBe('')
		expect(vm.formatTime('2026-11-13T09:00:00Z')).toBe('2026-11-13T09:00:00Z')
	})

	it('translates labels through a global t when there is one', () => {
		const vm = instance(BookingConflictDialog)
		expect(vm.label('Cancel')).toBe('Cancel')
		vi.stubGlobal('t', (app, key) => `${app}:${key}`)
		expect(vm.label('Cancel')).toBe('shillinq:Cancel')
		vi.unstubAllGlobals()
	})
})

describe('DeleteBudgetMappingDialog', () => {
	const mapping = { glAccountNumber: '4300', programmeCode: 'P-01', allocationPercentage: '12.5', effectiveFrom: '2026-01-01', effectiveTo: '2026-12-31' }

	it('renders nothing while closed', async () => {
		expect(await render(DeleteBudgetMappingDialog, { open: false })).not.toContain('bbv-delete-dialog')
	})

	it('summarises the mapping it would delete', async () => {
		const html = await render(DeleteBudgetMappingDialog, { open: true, mapping })
		expect(html).toContain('data-testid="bbv-delete-dialog-summary"')
		expect(html).toContain('4300')
		expect(html).toContain('P-01')
		expect(html).toContain('12.50 %')
		expect(html).toMatch(/2026-01-01\s*→ 2026-12-31/)
		expect(html).toMatch(/data-testid="bbv-delete-dialog-confirm"[^>]*>\s*Delete\s*</)
	})

	it('shows dashes for a sparse mapping and no summary without one', async () => {
		const sparse = await render(DeleteBudgetMappingDialog, { open: true, mapping: { allocationPercentage: 'n/a' } })
		expect(sparse.match(/<dd[^>]*>\s*—/g)).toHaveLength(4)
		expect(sparse).not.toMatch(/<dd[^>]*>[^<]*→/)
		expect(await render(DeleteBudgetMappingDialog, { open: true })).not.toContain('bbv-delete-dialog-summary')
	})

	it('disables both buttons and says Deleting… while deleting', async () => {
		const html = await render(DeleteBudgetMappingDialog, { open: true, mapping, deleting: true })
		expect(html).toMatch(/data-testid="bbv-delete-dialog-cancel" disabled/)
		expect(html).toMatch(/data-testid="bbv-delete-dialog-confirm" disabled[^>]*>\s*Deleting…/)
	})

	it('formats a percentage with two decimals and refuses a non-number', () => {
		const vm = instance(DeleteBudgetMappingDialog)
		expect(vm.formatPercentage(100)).toBe('100.00 %')
		expect(vm.formatPercentage('7.125')).toBe('7.13 %')
		expect(vm.formatPercentage(undefined)).toBe('—')
		expect(vm.label('Delete')).toBe('Delete')
	})
})
