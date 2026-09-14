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
 * a formatter CnAppRoot never receives renders the raw enum; and a menu id
 * missing from `settingsSection` lands in the main nav. So the guard is here,
 * not in a reviewer's eye.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import formatters, {
	connectionSettingsLabel,
	connectionStatus,
} from '../../src/utils/connectionFormatters.js'
import {
	INTEGRIQ_CONNECTIONS_PATH,
	openIntegriqConnections,
} from '../../src/utils/integriqConnections.js'

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
	it('reads integriq\'s app_connection schema as an index page', () => {
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
		const add = (page.config.headerActions ?? []).find((a) => a.id === 'add-integration')
		expect(add).toBeDefined()
		expect(add.label).toBe('Add integration')
		expect(add.handler).toBe('openIntegriqConnections')
		expect(iconsSource).toMatch(new RegExp(`\\b${add.icon}\\b`))
		// CnIndexPage resolves a named handler against `customComponents`.
		expect(mainSource).toMatch(/customComponentsProp = \{[\s\S]*openIntegriqConnections,/)
	})

	it('passes the formatters to CnAppRoot', () => {
		expect(mainSource).toContain('formatters: connectionFormatters')
		expect(appSource).toContain(':formatters="formatters"')
	})

	it('no longer registers the roster component', () => {
		expect(registrySource).not.toContain('ExternalAdaptersStatus')
		expect(fs.existsSync(path.join(ROOT, 'src', 'views', 'external-adapters'))).toBe(false)
	})

	it('translates every new label into Dutch', () => {
		for (const key of ['Add integration', 'All connections', 'Status message', 'Last checked', 'Open settings', 'Configured', 'Limited', 'Not configured', 'Simulated', 'Not available']) {
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
	it('presets the list to shillinq\'s own rows', () => {
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

describe('the connection formatters', () => {
	it('name each of the six states', () => {
		expect(connectionStatus('configured')).toBe('Configured')
		expect(connectionStatus('limited')).toBe('Limited')
		expect(connectionStatus('unconfigured')).toBe('Not configured')
		expect(connectionStatus('simulated')).toBe('Simulated')
		expect(connectionStatus('unavailable')).toBe('Not available')
		expect(connectionStatus('error')).toBe('Error')
	})

	// A log-only adapter WORKS and delivers nothing. Rendering it as
	// Configured or Not available is the claim this page exists to stop.
	it('do not let a log-only adapter read as configured or unavailable', () => {
		expect(connectionStatus('simulated')).not.toBe(connectionStatus('configured'))
		expect(connectionStatus('simulated')).not.toBe(connectionStatus('unavailable'))
	})

	// Limited came with hydra#673. A connection that works in part is neither
	// working nor broken, so it must not borrow either label.
	it('keep a connection that works in part apart from working and broken', () => {
		expect(connectionStatus('limited')).not.toBe(connectionStatus('configured'))
		expect(connectionStatus('limited')).not.toBe(connectionStatus('unavailable'))
		expect(connectionStatus('limited')).not.toBe(connectionStatus('error'))
	})

	it('render an unknown value as itself and a missing one as empty', () => {
		expect(connectionStatus('degraded')).toBe('degraded')
		expect(connectionStatus(undefined)).toBe('')
		expect(connectionStatus(null)).toBe('')
	})

	it('label a settings link only when there is somewhere to go', () => {
		expect(connectionSettingsLabel('/apps/shillinq/bookkeeping/multi-currency/fx-rates/admin')).toBe('Open settings')
		expect(connectionSettingsLabel('')).toBe('')
		expect(connectionSettingsLabel(undefined)).toBe('')
	})

	it('are exported under the names the manifest uses', () => {
		expect(formatters.connectionStatus).toBe(connectionStatus)
		expect(formatters.connectionSettingsLabel).toBe(connectionSettingsLabel)
	})
})

describe('the Add integration handler', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('opens integriq\'s overview filtered to shillinq with the link dialog', () => {
		const assign = vi.fn()
		vi.stubGlobal('window', { location: { assign } })

		openIntegriqConnections()

		expect(INTEGRIQ_CONNECTIONS_PATH).toBe('/apps/integriq/connections?app=shillinq&link=1')
		expect(assign).toHaveBeenCalledWith('/index.php/apps/integriq/connections?app=shillinq&link=1')
	})
})
