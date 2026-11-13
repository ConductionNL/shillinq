/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The Integrations page and its Open in Integriq action
 * (platform-integration-catalogue).
 *
 * The cards are integriq's `catalog_item` objects. What is asserted here fails
 * silently in the browser: a page without `requiresApp` renders an empty grid
 * on an instance without integriq, a handler nobody registers does nothing
 * when clicked, and a menu id missing from `settingsSection` lands in the main
 * navigation.
 *
 * @spec openspec/changes/platform-integration-catalogue/specs/integration-catalogue/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	INTEGRIQ_STORE_PATH,
	openIntegriqStore,
} from '../../src/utils/integriqConnections.js'

const ROOT = path.resolve(__dirname, '..', '..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragment = readJson('src', 'manifest.d', 'platform-integration-catalogue.json')
const menuLayout = readJson('src', 'menu-layout.json')
const iconsSource = read('src', 'icons.js')
const mainSource = read('src', 'main.js')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

const page = fragment.pages.find((p) => p.id === 'IntegrationCatalogue')
const menuEntry = fragment.menu.find((m) => m.id === 'Integrations')

describe('the Integrations page (REQ-PIC-001)', () => {
	it("lists integriq's catalogue items as cards", () => {
		expect(page.type).toBe('index')
		expect(page.route).toBe('/integrations')
		expect(page.config.register).toBe('integriq')
		expect(page.config.schema).toBe('catalog_item')
		expect(page.config.viewMode).toBe('cards')
	})

	it('opens on the bookkeeping categories, as a filter a person can remove', () => {
		expect(page.config.defaultFilters).toEqual({
			category: ['Bank', 'Payments', 'E-invoicing', 'Tax filing', 'Commerce', 'Payroll'],
		})
	})

	it('shows name, description, category and status on each card', () => {
		expect(page.config.columns.map((c) => c.key)).toEqual(['name', 'description', 'category', 'status'])
		expect(page.config.cardFields).toEqual(['description', 'category', 'status'])
	})

	// Switching an item on is integriq's, under its own authorization (ADR-023).
	it('offers no Add, no selection and no view of its own', () => {
		expect(page.config.showAdd).toBe(false)
		expect(page.config.selectable).toBe(false)
		expect(page.config.showViewAction).toBe(false)
	})

	it('sends each card to integriq through a handler CnAppRoot receives', () => {
		const open = page.config.actions.find((a) => a.id === 'open-in-integriq')
		expect(open.handler).toBe('openIntegriqStore')
		expect(iconsSource).toMatch(new RegExp(`\\b${open.icon}\\b`))
		expect(iconsSource).toMatch(new RegExp(`\\b${menuEntry.icon}\\b`))
		expect(mainSource).toMatch(/customComponentsProp = \{[\s\S]*\.\.\.manifestActions,/)
		expect(read('src', 'manifestActions.js')).toContain('\topenIntegriqStore,')
	})

	it('translates every new label into Dutch', () => {
		for (const key of [menuEntry.label, page.title, page.config.description, ...page.config.actions.flatMap((a) => [a.label, a.description])]) {
			expect(en[key], key).toBe(key)
			expect(nl[key], key).toBeTruthy()
			expect(nl[key], key).not.toBe(key)
		}
	})
})

describe('without integriq (REQ-PIC-003)', () => {
	// The menu entry stays, so the page is reachable and says what it needs
	// instead of looking like an empty catalogue.
	it('names Integriq as the app it needs and keeps its menu entry', () => {
		expect(page.requiresApp).toEqual({ id: 'integriq', name: 'Integriq' })
		expect(menuEntry.visibleIf).toBeUndefined()
	})

	it('sits in the settings foldout beside External Connections', () => {
		expect(menuEntry.route).toBe(page.id)
		const section = menuLayout.settingsSection
		expect(section.indexOf('Integrations')).toBe(section.indexOf('ExternalConnections') + 1)
	})
})

describe('the Open in Integriq handler', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it("opens integriq's catalogue searched on the item's name", () => {
		const assign = vi.fn()
		vi.stubGlobal('window', { location: { assign } })

		openIntegriqStore({ name: 'Mollie & iDEAL' })
		openIntegriqStore({ name: '  ' })
		openIntegriqStore()

		expect(INTEGRIQ_STORE_PATH).toBe('/apps/integriq/store')
		expect(assign.mock.calls).toEqual([
			['/index.php/apps/integriq/store?_search=Mollie%20%26%20iDEAL'],
			['/index.php/apps/integriq/store'],
			['/index.php/apps/integriq/store'],
		])
	})
})
