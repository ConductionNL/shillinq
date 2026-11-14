/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * AdministrationSwitcherPage driven through its template with its slot
 * rendered the server way: the switcher is asked to reload after a switch,
 * its error event turns into the page's alert, and a 401 asks the user to
 * sign in. Only the components are replaced.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { renderWithStandIns } from './helpers/standInRender.js'

vi.mock('@nextcloud/vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return { NcAppContent: standIn('NcAppContent') }
})
vi.mock('../../src/components/AdministratieSwitcher.vue', async () => {
	const { standIn } = await import('./helpers/standInRender.js')
	return { default: standIn('AdministratieSwitcher') }
})

const { default: Page } = await import('../../src/views/AdministrationSwitcherPage.vue')

const translate = (app, text) => text
const globals = { t: translate }

beforeEach(() => {
	vi.stubGlobal('t', translate)
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('AdministrationSwitcherPage template events', () => {
	it('wires the switcher to reload after a switch and shows no alert at first', async () => {
		const view = await renderWithStandIns(Page, { globals, slots: 'ssr' })

		const [switcher] = view.standIns('AdministratieSwitcher')
		expect(switcher.reloadAfterSwitch).toBe(true)
		expect(view.html).not.toContain('role="alert"')
		expect(view.html).toContain('Switch administration')
	})

	it('turns a switcher error into the page alert', async () => {
		const view = await renderWithStandIns(Page, { globals, slots: 'ssr' })

		view.standIns('AdministratieSwitcher')[0].onError({ response: { status: 500 } })
		expect(view.vm.errorMessage).toBe('Failed to load administrations.')
		const after = await view.rerender()
		expect(after.html).toMatch(/role="alert"[^>]*>\s*Failed to load administrations\.\s*</)
	})

	it('asks the user to sign in when the switcher is refused with 401', async () => {
		const view = await renderWithStandIns(Page, { globals, slots: 'ssr' })

		view.standIns('AdministratieSwitcher')[0].onError({ response: { status: 401 } })
		const after = await view.rerender()
		expect(after.html).toContain('Please sign in to switch administrations.')
	})
})
