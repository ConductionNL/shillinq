/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The External Connections page, its formatters and its Add integration action
 * (adopt-connection-registry).
 *
 * The rows are integriq's `app_connection` objects. Shillinq ships the page in
 * its manifest and `lib/Settings/connections.json`, which integriq syncs.
 *
 * Everything asserted here fails SILENTLY in the browser. A menu entry without
 * its `query` lists every app's rows as though they were shillinq's; a header
 * action naming a handler nobody passes to CnAppRoot does nothing when clicked;
 * a formatter name nothing answers renders the raw enum; and a menu id
 * missing from `settingsSection` lands in the main nav. So the guard is here,
 * not in a reviewer's eye.
 *
 * The two formatters are nextcloud-vue built-ins since 3.2.0. CnAppRoot merges
 * `{ ...BUILT_IN_FORMATTERS, ...formatters }`, so an app formatter of the same
 * name would shadow the library's. Shillinq passes none.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	INTEGRIQ_CONNECTIONS_PATH,
	openIntegriqConnections,
} from '../../src/utils/integriqConnections.js'

// The built-ins translate through @nextcloud/l10n, which reads the browser
// session on import. The suite runs in node, so lend it the global scope.
globalThis.window ??= globalThis
const { BUILT_IN_FORMATTERS } =
	await import('@conduction/nextcloud-vue/src/utils/builtInFormatters.js')

const ROOT = path.resolve(__dirname, '..', '..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const readJson = (...parts) => JSON.parse(read(...parts))

const fragment = readJson('src', 'manifest.d', 'external-adapters-w8.json')
const menuLayout = readJson('src', 'menu-layout.json')
const declaration = readJson('lib', 'Settings', 'connections.json')
const iconsSource = read('src', 'icons.js')
const mainSource = read('src', 'main.js')
const appSource = read('src', 'App.vue')
const registrySource = read('src', 'registry.js')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

const page = fragment.pages.find((p) => p.id === 'ExternalAdaptersStatus')
const menuEntry = fragment.menu.find((m) => m.id === 'ExternalConnections')

describe('the External Connections page', () => {
	it("reads integriq's app_connection schema as an index page", () => {
		expect(page).toBeDefined()
		expect(page.type).toBe('index')
		expect(page.component).toBeUndefined()
		expect(page.config.register).toBe('integriq')
		expect(page.config.schema).toBe('app_connection')
	})

	it('keeps its route, so a bookmark still lands', () => {
		expect(page.route).toBe('/external-adapters')
	})

	// Without it, a deep link on an instance without integriq renders an empty
	// table, and "not installed" looks exactly like "no connections".
	it('names Integriq as the app it needs', () => {
		expect(page.requiresApp).toEqual({ id: 'integriq', name: 'Integriq' })
	})

	it('shows the columns the contract names, through the contract formatters', () => {
		const byKey = Object.fromEntries(page.config.columns.map((c) => [c.key, c]))
		expect(Object.keys(byKey)).toEqual([
			'title',
			'status',
			'statusMessage',
			'checkedAt',
			'settingsUrl',
		])
		expect(byKey.status.formatter).toBe('connectionStatus')
		expect(byKey.settingsUrl.formatter).toBe('connectionSettingsLabel')
		expect(byKey.settingsUrl.widget).toBe('link')
		expect(byKey.settingsUrl.widgetProps.href).toBe('{settingsUrl}')
	})

	it('sorts on the declared order and groups the sidebar on status', () => {
		expect(page.config.defaultSort).toEqual({ field: 'order', direction: 'asc' })
		expect(page.config.folderSidebar.source).toBe('field')
		expect(page.config.folderSidebar.field).toBe('status')
	})

	// A row nothing declared has nothing to check (connection-registry D9).
	it('offers no generic Add button and no row actions', () => {
		expect(page.config.showAdd).toBe(false)
		expect(page.config.actions ?? []).toEqual([])
	})

	it('sends Add integration to integriq through a handler CnAppRoot receives', () => {
		const add = (page.config.headerActions ?? []).find(
			(a) => a.id === 'add-integration',
		)
		expect(add).toBeDefined()
		expect(add.label).toBe('Add integration')
		expect(add.handler).toBe('openIntegriqConnections')
		expect(iconsSource).toMatch(new RegExp(`\\b${add.icon}\\b`))
		// CnIndexPage resolves a named handler against `customComponents`.
		expect(mainSource).toMatch(
			/customComponentsProp = \{[\s\S]*openIntegriqConnections,/,
		)
	})

	it('labels a switched-off connection through the nextcloud-vue built-in', () => {
		// The registry the way CnAppRoot builds it. Shillinq passes no
		// formatters of its own, so the built-ins answer every column. Both
		// files count: App.vue does not declare the prop, so a `formatters`
		// passed from main.js falls through onto CnAppRoot all the same.
		expect(appSource, 'App.vue passes its own formatters').not.toContain(
			':formatters=',
		)
		expect(mainSource, 'main.js passes its own formatters').not.toContain(
			'formatters:',
		)
		const registry = { ...BUILT_IN_FORMATTERS }
		for (const column of page.config.columns.filter((c) => c.formatter)) {
			expect(typeof registry[column.formatter], column.formatter).toBe(
				'function',
			)
		}
		expect(registry.connectionStatus('disabled')).toBe('Switched off')
	})

	it('no longer registers the roster component', () => {
		expect(registrySource).not.toContain('ExternalAdaptersStatus')
		expect(
			fs.existsSync(path.join(ROOT, 'src', 'views', 'external-adapters')),
		).toBe(false)
	})

	it('translates every new label into Dutch', () => {
		for (const key of [
			'Add integration',
			'All connections',
			'Status message',
			'Last checked',
		]) {
			expect(en[key], key).toBe(key)
			expect(nl[key], key).toBeTruthy()
			expect(nl[key], key).not.toBe(key)
		}
	})
})

describe('the External Connections menu entry', () => {
	// THE PRESET. integriq's schema holds every app's rows. The query is what
	// makes this shillinq's page, and a bare key is the spelling the objects
	// endpoint reads as a filter.
	it("presets the list to shillinq's own rows", () => {
		expect(menuEntry.query).toEqual({ app: 'shillinq' })
		expect(declaration.app).toBe('shillinq')
	})

	it('only renders when integriq is installed', () => {
		expect(menuEntry.visibleIf).toEqual({ appInstalled: 'integriq' })
	})

	it('keeps its place in the settings foldout', () => {
		expect(menuEntry.route).toBe(page.id)
		expect(menuLayout.settingsSection).toContain('ExternalConnections')
	})
})

describe('the Add integration handler', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it("opens integriq's overview filtered to shillinq with the link dialog", () => {
		const assign = vi.fn()
		vi.stubGlobal('window', { location: { assign } })

		openIntegriqConnections()

		expect(INTEGRIQ_CONNECTIONS_PATH).toBe(
			'/apps/integriq/connections?app=shillinq&link=1',
		)
		expect(assign).toHaveBeenCalledWith(
			'/index.php/apps/integriq/connections?app=shillinq&link=1',
		)
	})
})
