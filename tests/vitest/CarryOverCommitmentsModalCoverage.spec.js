/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * CarryOverCommitmentsModal (planning-commitment-year-end REQ-PCYE-004):
 * driven through its template with recording stand-ins for each state (nothing open, lines with shortfalls,
 * done, an error), and its load, submit and close logic run against a
 * stand-in instance with the HTTP client and the year-end API mocked.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns, vnodeText } from './helpers/standInRender.js'

const http = vi.hoisted(() => ({ get: vi.fn() }))
const api = vi.hoisted(() => ({ previewCarryOver: vi.fn(), carryOver: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match)),
}))
vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return { NcButton: standIn('NcButton'), NcDialog: standIn('NcDialog'), NcTextField: standIn('NcTextField') }
})
vi.mock('../../src/utils/commitmentYearEndApi.js', () => ({
	previewCarryOver: api.previewCarryOver,
	carryOver: api.carryOver,
	euro: (cents) => (Number(cents || 0) / 100).toFixed(2),
}))

const { default: CarryOverCommitmentsModal } = await import('../../src/modals/CarryOverCommitmentsModal.vue')

const PREVIEW = {
	fromYear: 2026,
	toYear: 2027,
	total: 150000,
	lines: [
		{ lineId: 'l-1', commitmentNumber: 'V-1', programme: 'Wonen', remaining: 100000 },
		{ lineId: 'l-2', commitmentNumber: 'V-2', programme: 'Groen', remaining: 50000 },
	],
	shortfalls: [{ programme: 'Wonen', shortfall: 25000, commitments: ['V-1', 'V-3'] }],
}

/**
 * Render the modal with its data replaced.
 *
 * @param {object} state Data to lay over its own.
 * @param {object} props Props, listeners included.
 * @return {Promise<object>} The rendered view.
 */
function render(state = {}, props = {}) {
	return renderWithStandIns(CarryOverCommitmentsModal, { data: state, props })
}

/**
 * The text of the element carrying a test id.
 *
 * @param {object} view The rendered view.
 * @param {string} id The data-testid.
 * @return {string} Its text, or '' when absent.
 */
function textOf(view, id) {
	const vnode = view.byTestId(id)
	return vnode ? vnodeText(vnode) : ''
}

/**
 * The text of every action button, in order.
 *
 * @param {object} view The rendered view.
 * @return {Array<string>} The labels.
 */
function buttons(view) {
	return view.find((vnode) => vnode.type?.name === 'NcButton').map((vnode) => [].concat(vnode.children.default()).map(vnodeText).join(' ').trim())
}

/**
 * The modal's data and methods bound to a plain object, as Vue would.
 *
 * @param {object} over Data to lay over its own.
 * @return {object} The instance.
 */
function instance(over = {}) {
	const self = { ...CarryOverCommitmentsModal.data(), $emit: vi.fn(), ...over }
	for (const [name, method] of Object.entries(CarryOverCommitmentsModal.methods)) {
		self[name] = method.bind(self)
	}
	return self
}

describe('CarryOverCommitmentsModal rendered', () => {
	it('offers the current year and nothing to carry before a preview arrives', async () => {
		const view = await render()
		expect(view.standIns('NcDialog')[0].name).toBe('Carry open commitments to next year')
		const year = view.byTestId('carry-over-year')
		expect(year.props.label).toBe('Year that ends')
		expect(year.props.modelValue).toBe(String(new Date().getFullYear()))
		expect(year.props.disabled).toBe(false)
		expect(view.byTestId('carry-over-submit').props.disabled).toBe(true)
		expect(buttons(view)).toEqual(['Cancel', 'Carry over'])
		expect(view.byTestId('carry-over-lines')).toBeUndefined()
	})

	it('says when a year has no open lines', async () => {
		const view = await render({ preview: { ...PREVIEW, lines: [], shortfalls: [] } })
		expect(textOf(view, 'carry-over-nothing')).toBe('No open commitment lines in 2026.')
		expect(view.byTestId('carry-over-submit').props.disabled).toBe(true)
	})

	it('lists the lines, the total and each short budget', async () => {
		const view = await render({ preview: PREVIEW })
		expect(view.html).toContain('2 lines continue in 2027, EUR 1500.00 in total.')
		expect(textOf(view, 'carry-over-lines')).toBe('V-1, Wonen: EUR 1000.00 V-2, Groen: EUR 500.00')
		expect(view.html).toContain('Budgets that fall short in 2027')
		expect(textOf(view, 'carry-over-shortfalls')).toBe('Programme Wonen is EUR 250.00 short (V-1, V-3).')
		expect(view.byTestId('carry-over-submit').props.disabled).toBe(false)
	})

	it('leaves the shortfall heading out when every budget absorbs its lines', async () => {
		const view = await render({ preview: { ...PREVIEW, shortfalls: [] } })
		expect(view.byTestId('carry-over-lines')).toBeDefined()
		expect(view.byTestId('carry-over-shortfalls')).toBeUndefined()
	})

	it('disables the carry-over while it is being sent', async () => {
		const view = await render({ preview: PREVIEW, submitting: true })
		expect(view.byTestId('carry-over-submit').props.disabled).toBe(true)
	})

	it('confirms the carry-over, offers only Close and locks the year', async () => {
		const view = await render({ preview: PREVIEW, done: true })
		expect(textOf(view, 'carry-over-done')).toBe('The open commitments now continue in 2027.')
		expect(buttons(view)).toEqual(['Close'])
		expect(view.byTestId('carry-over-submit')).toBeUndefined()
		expect(view.byTestId('carry-over-year').props.disabled).toBe(true)
	})

	it('shows an error as an alert', async () => {
		const view = await render({ error: 'Kapot' })
		const alert = view.byTestId('carry-over-error')
		expect(alert.props.role).toBe('alert')
		expect(vnodeText(alert)).toBe('Kapot')
	})

	it('wires the year, the buttons and the dialog to their handlers', async () => {
		api.previewCarryOver.mockResolvedValue(PREVIEW)
		api.carryOver.mockResolvedValue({ ...PREVIEW, lines: [] })
		const onClose = vi.fn()
		const view = await render({ administrationId: '7', preview: PREVIEW }, { onClose })

		view.byTestId('carry-over-year').props['onUpdate:modelValue']('2024')
		expect(view.vm.fromYear).toBe('2024')
		await view.byTestId('carry-over-year').props.onChange()
		expect(api.previewCarryOver).toHaveBeenCalledWith('7', 2024)

		await view.byTestId('carry-over-submit').props.onClick()
		expect(api.carryOver).toHaveBeenCalledWith('7', 2024)
		expect(view.vm.done).toBe(true)

		view.standIns('NcDialog')[0].onClosing()
		expect(onClose).toHaveBeenCalledWith(true)
	})

	it('writes the same states as markup when the slots render the server way', async () => {
		const ssr = (state) => renderWithStandIns(CarryOverCommitmentsModal, { data: state, slots: 'ssr' }).then((view) => view.html.replace(/<!--[[\]]-->/g, ''))

		const lines = await ssr({ preview: PREVIEW })
		expect(lines).toContain('V-1, Wonen: EUR 1000.00')
		expect(lines).toContain('Programme Wonen is EUR 250.00 short (V-1, V-3).')
		expect(lines).toContain('Carry over')

		const nothing = await ssr({ preview: { ...PREVIEW, lines: [], shortfalls: [] } })
		expect(nothing).toContain('No open commitment lines in 2026.')
		expect(nothing).not.toContain('carry-over-lines')

		const done = await ssr({ preview: { ...PREVIEW, shortfalls: [] }, done: true, error: 'Let op' })
		expect(done).toContain('The open commitments now continue in 2027.')
		expect(done).toContain('Let op')
		expect(done).toContain('Close')
		expect(done).not.toContain('carry-over-shortfalls')
		expect(done).not.toContain('Carry over')
	})
})

describe('CarryOverCommitmentsModal logic', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('loads the administration and then the preview for the chosen year on mount', async () => {
		http.get.mockResolvedValue({ data: { activeAdministrationId: 42 } })
		api.previewCarryOver.mockResolvedValue(PREVIEW)
		const self = instance({ fromYear: '2026' })

		await CarryOverCommitmentsModal.mounted.call(self)

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/context')
		expect(api.previewCarryOver).toHaveBeenCalledWith('42', 2026)
		expect(self.administrationId).toBe('42')
		expect(self.preview).toBe(PREVIEW)
		expect(self.error).toBe('')
	})

	it('says the administration could not be loaded, with the server message when there is one', async () => {
		http.get.mockRejectedValueOnce(new Error('offline'))
		const self = instance()
		await CarryOverCommitmentsModal.mounted.call(self)
		expect(self.error).toBe('The administration could not be loaded.')
		expect(api.previewCarryOver).not.toHaveBeenCalled()

		http.get.mockRejectedValueOnce({ response: { data: { message: 'Geen toegang' } } })
		await CarryOverCommitmentsModal.mounted.call(self)
		expect(self.error).toBe('Geen toegang')
	})

	it('drops the old preview when a year cannot be loaded', async () => {
		api.previewCarryOver.mockRejectedValue(new Error('500'))
		const self = instance({ administrationId: '7', fromYear: '2025', preview: PREVIEW, error: 'old' })

		await self.load()

		expect(api.previewCarryOver).toHaveBeenCalledWith('7', 2025)
		expect(self.preview).toBeNull()
		expect(self.error).toBe('The open commitments could not be loaded.')
	})

	it('carries the lines over and marks the dialog done', async () => {
		const result = { ...PREVIEW, lines: [] }
		api.carryOver.mockResolvedValue(result)
		const self = instance({ administrationId: '7', fromYear: '2026', preview: PREVIEW })

		await self.submit()

		expect(api.carryOver).toHaveBeenCalledWith('7', 2026)
		expect(self.preview).toBe(result)
		expect(self.done).toBe(true)
		expect(self.submitting).toBe(false)
	})

	it('keeps the dialog open with the reason when the carry-over fails', async () => {
		api.carryOver.mockRejectedValue({ response: { data: { message: 'Periode gesloten' } } })
		const self = instance({ administrationId: '7', preview: PREVIEW })

		await self.submit()

		expect(self.done).toBe(false)
		expect(self.submitting).toBe(false)
		expect(self.preview).toBe(PREVIEW)
		expect(self.error).toBe('Periode gesloten')
	})

	it('closes with whether the carry-over happened', () => {
		const self = instance({ done: true })
		self.close()
		expect(self.$emit).toHaveBeenCalledWith('close', true)

		const open = instance()
		open.close()
		expect(open.$emit).toHaveBeenCalledWith('close', false)
	})
})
