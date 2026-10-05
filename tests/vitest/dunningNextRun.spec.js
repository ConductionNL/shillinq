/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * receivables-automatic-dunning 4.1 (REQ-RAD-008): the Next run preview and
 * the job report on Dunning runs, the stage texts on a dunning ladder, and
 * the preview shown before dunning is switched on for an administration.
 *
 * The header actions are resolved through the INSTALLED library: the index
 * page's own `mergedHeaderActions` with the handler map main.js hands it
 * (`customComponents`, spread from manifestActions), and the detail page's
 * CnPageRenderer `cnDispatchAction`, which reads `manifest.actions`. A
 * handler missing from either map makes the button do nothing.
 *
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 */

import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

// The dispatcher reads the current user for @-tokens; nobody is signed in.
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => null,
	getRequestToken: () => '',
	onRequestTokenUpdate: () => {},
}))
const axiosMock = { get: vi.fn(), patch: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showInfo: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
	getFilePickerBuilder: vi.fn(),
}))
const spawnDialog = vi.fn()
vi.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: (...args) => spawnDialog(...args),
}))

// The renderer's child components; the dispatch lookup never renders them.
vi.mock(
	'../../node_modules/@conduction/nextcloud-vue/dist/esm/dialogs/CnPageConfigModal.vue.js',
	() => ({ default: {} }),
)
vi.mock(
	'../../node_modules/@conduction/nextcloud-vue/dist/esm/components/CnBuildiqEditButton/CnBuildiqEditButton.vue.js',
	() => ({ default: {} }),
)
vi.mock(
	'../../node_modules/@conduction/nextcloud-vue/dist/esm/components/CnDependencyMissing/CnDependencyMissing.vue.js',
	() => ({ default: {} }),
)
vi.mock(
	'../../node_modules/@conduction/nextcloud-vue/dist/esm/components/CnWidgetGrid/CnWidgetGrid.vue.js',
	() => ({ default: {} }),
)
vi.mock(
	'../../node_modules/@conduction/nextcloud-vue/dist/esm/components/CnMassExportDialog/CnMassExportDialog.vue.js',
	() => ({ default: {} }),
)
vi.mock(
	'../../node_modules/@conduction/nextcloud-vue/dist/esm/components/CnPageRenderer/pageTypes.js',
	() => ({ defaultPageTypes: {} }),
)
vi.mock(
	'../../node_modules/@conduction/nextcloud-vue/dist/esm/store/useObjectStore.js',
	() => ({ useObjectStore: () => ({}) }),
)
vi.mock(
	'../../node_modules/@conduction/nextcloud-vue/dist/esm/composables/useObjectSubscription.js',
	() => ({ useObjectSubscription: () => ({}) }),
)

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8')

const { attachManifestActions, manifestActions } =
	await import('../../src/manifestActions.js')
const CnPageRenderer = (
	await import('@conduction/nextcloud-vue/dist/esm/components/CnPageRenderer/CnPageRenderer.vue2.js')
).default
const { resolveRegisteredHandler } =
	await import('@conduction/nextcloud-vue/dist/esm/utils/actionsDispatcher.js')
const { evaluateVisibleWhenLocal, isLocallyDecidableVisibleWhen } =
	await import('@conduction/nextcloud-vue/dist/esm/utils/visibleWhen.js')

/**
 * Every page of the bundled manifest and its fragments.
 *
 * @return {Array<object>} The pages.
 */
function allPages() {
	const files = ['src/manifest.json'].concat(
		fs
			.readdirSync(path.join(ROOT, 'src/manifest.d'))
			.filter((name) => name.endsWith('.json'))
			.map((name) => 'src/manifest.d/' + name),
	)
	return files.flatMap((file) => JSON.parse(read(file)).pages ?? [])
}

/**
 * The one page with this id.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	const found = allPages().filter((candidate) => candidate.id === id)
	expect(found, id).toHaveLength(1)
	return found[0]
}

/**
 * Resolve an index page header action the way the installed CnIndexPage
 * does (`resolveHeaderHandler`, which `mergedHeaderActions` runs on every
 * entry), with the handler map the app hands it.
 *
 * @param {object} entry The declared header action.
 * @return {object} The action with its handler resolved, or stripped.
 */
function resolvedHeaderAction(entry) {
	const handler = resolveRegisteredHandler(
		entry.handler,
		{},
		{
			...manifestActions,
		},
	)
	expect(typeof handler, entry.handler).toBe('function')
	return { ...entry, handler: () => handler({ actionId: entry.id }) }
}

/**
 * Dispatch a detail page header action through the installed
 * CnPageRenderer's `cnDispatchAction`.
 *
 * @param {object} manifest The manifest the renderer shows.
 * @param {object} action The header action.
 * @return {unknown} The dispatch result.
 */
function dispatchAsRenderer(manifest, action) {
	const renderer = {
		manifest,
		cnManifestSource: null,
		cnManifest: null,
		$router: null,
		cnRegistry: {},
		_cnOpenModal: () => {},
	}
	for (const [name, getter] of Object.entries(CnPageRenderer.computed)) {
		Object.defineProperty(renderer, name, {
			get: () => getter.call(renderer),
		})
	}
	return CnPageRenderer.provide.call(renderer).cnDispatchAction(action)
}

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.patch.mockReset()
	spawnDialog.mockReset()
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('Next run on Dunning runs', () => {
	it('is a header action whose handler the index page finds', () => {
		const action = page('DunningRuns').config.headerActions.find(
			(candidate) => candidate.id === 'next-run',
		)
		expect(action.label).toBe('Next run')
		expect(action.handler).toBe('openDunningNextRun')

		resolvedHeaderAction(action).handler()

		expect(spawnDialog).toHaveBeenCalledTimes(1)
		const [component, props] = spawnDialog.mock.calls[0]
		expect(component.name).toBe('DunningNextRunModal')
		expect(props).toEqual({ administrationId: '', switchOn: false })
	})

	it('reads the preview from a route the controller serves', async () => {
		const { fetchNextRun } = await import('../../src/utils/dunningApi.js')
		const routes = read('appinfo/routes.php')
		expect(routes).toContain(
			"['name' => 'dunningPreview#nextRun', 'url' => '/api/dunning/next-run', 'verb' => 'GET']",
		)
		const controller = read('lib/Controller/DunningPreviewController.php')
		expect(controller).toContain("getParam('administrationId'")

		axiosMock.get.mockResolvedValue({
			data: { administrations: [{ administrationId: 'ADM-KADE', rows: [] }] },
		})
		const administrations = await fetchNextRun('ADM-KADE')

		expect(axiosMock.get.mock.calls[0][0]).toBe(
			'/index.php/apps/shillinq/api/dunning/next-run',
		)
		expect(axiosMock.get.mock.calls[0][1]).toEqual({
			params: { administrationId: 'ADM-KADE' },
		})
		expect(administrations).toEqual([{ administrationId: 'ADM-KADE', rows: [] }])
	})

	it('names every channel a ladder can use', async () => {
		const { channelLabel } = await import('../../src/utils/dunningApi.js')
		const register = JSON.parse(
			read('lib/Settings/register.d/bookkeeping-credit-control-dunning.json'),
		)
		const channels =
			register.components.schemas.DunningRun.properties.channel.enum
		expect(channels.length).toBeGreaterThanOrEqual(4)
		for (const channel of channels) {
			expect(channelLabel(channel), channel).not.toBe(channel)
		}
	})

	it('sums the last run up in one line per administration', async () => {
		const { reportLine } = await import('../../src/utils/dunningApi.js')
		expect(reportLine(null)).toBe(
			'The daily run has not run for this administration yet.',
		)
		expect(
			reportLine({
				ranAt: '2026-10-22T06:00:00+00:00',
				markedOverdue: 2,
				sent: 1,
				manual: 1,
				failed: 0,
				skipped: 3,
				errors: 0,
				locked: false,
			}),
		).toBe(
			'Last run 2026-10-22: 2 marked overdue, 1 sent, 1 to send by hand, 0 failed, 3 with nothing due.',
		)
		expect(
			reportLine({ ranAt: '2026-10-22T06:00:00+00:00', locked: true }),
		).toBe('Last run 2026-10-22: skipped, another run was still busy.')
	})
})

describe('Switch on reminders on an administration', () => {
	it('shows the preview first, through the detail page renderer', () => {
		const detail = page('AdministratieDetail')
		const action = detail.config.headerActions.find(
			(candidate) => candidate.id === 'switch-on-reminders',
		)
		expect(action.label).toBe('Switch on reminders')
		expect(action.type).toBe('handler')
		expect(isLocallyDecidableVisibleWhen(action.visibleWhen)).toBe(true)
		expect(
			evaluateVisibleWhenLocal(action.visibleWhen, { dunningEnabled: false }),
		).not.toBe(false)
		expect(
			evaluateVisibleWhenLocal(action.visibleWhen, { dunningEnabled: true }),
		).toBe(false)
		expect(detail.config.fields.map((field) => field.key)).toContain(
			'dunningEnabled',
		)

		vi.stubGlobal('window', {
			location: {
				pathname: '/apps/shillinq/bookkeeping/administrations/uuid-adm-kade',
			},
		})
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		dispatchAsRenderer(attachManifestActions({ pages: [detail] }), action)

		expect(warn).not.toHaveBeenCalled()
		expect(spawnDialog).toHaveBeenCalledTimes(1)
		const [component, props] = spawnDialog.mock.calls[0]
		expect(component.name).toBe('DunningNextRunModal')
		expect(props).toEqual({ administrationId: 'uuid-adm-kade', switchOn: true })
	})

	it('switches it on by the property the job reads', async () => {
		const { switchOnDunning } = await import('../../src/utils/dunningApi.js')
		const runner = read('lib/Service/Dunning/DunningTickRunner.php')
		expect(runner).toContain("ENABLED_PROPERTY = 'dunningEnabled'")

		axiosMock.patch.mockResolvedValue({
			data: { id: 'uuid-adm-kade', dunningEnabled: true },
		})
		await switchOnDunning('uuid-adm-kade')

		expect(axiosMock.patch).toHaveBeenCalledWith(
			'/index.php/apps/openregister/api/objects/shillinq/Administration/uuid-adm-kade',
			{ dunningEnabled: true },
		)
	})
})

describe('Stage texts on a dunning ladder', () => {
	it('shows each stage with its subject and body', () => {
		const config = page('DunningLadderDetail').config
		const widget = config.widgets.find(
			(candidate) => candidate.id === 'ladder-stages',
		)
		expect(widget.type).toBe('object-table')
		expect(widget.content.endpointSource).toEqual({
			url: '/apps/shillinq/api/dunning/ladders/@objectId/stages',
			responsePath: 'rows',
		})
		expect(widget.content.columns.map((column) => column.key)).toEqual([
			'nr',
			'days',
			'channelLabel',
			'subject',
			'body',
		])
		expect(config.layout.map((cell) => cell.widgetId)).toContain('ladder-stages')
		expect(read('appinfo/routes.php')).toContain(
			"['name' => 'dunningPreview#ladderStages', 'url' => '/api/dunning/ladders/{id}/stages', 'verb' => 'GET']",
		)
	})
})
