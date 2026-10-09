/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * A detail page's header action with `"type": "handler"` reaches its function
 * (live pass S4). The batch page's "Continue in the wizard" did nothing
 * because the handler was registered only in `customComponents`, which an
 * index page reads, while a detail page dispatches through CnPageRenderer's
 * `cnDispatchAction`, which reads `manifest.actions`. These tests
 * dispatch through the INSTALLED CnPageRenderer's own `cnDispatchAction`
 * (its `provide()`), not through the handler, so they see the lookup the page
 * performs. The renderer's child components (grid, modals, editor button)
 * are stubbed: they pull codemirror, colour pickers and draggable lists that
 * need a real DOM, and the lookup does not touch them.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, describe, expect, it, vi } from 'vitest'

// The dispatcher reads the current user for @-tokens; nobody is signed in here.
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => null,
	getRequestToken: () => '',
	onRequestTokenUpdate: () => {},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
	showInfo: vi.fn(),
	showSuccess: vi.fn(),
	showWarning: vi.fn(),
	getFilePickerBuilder: vi.fn(),
}))
vi.mock('@nextcloud/vue/functions/dialog', () => ({ spawnDialog: vi.fn() }))

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

const { attachManifestActions, manifestActions } =
	await import('../../src/manifestActions.js')
const CnPageRenderer = (
	await import('@conduction/nextcloud-vue/dist/esm/components/CnPageRenderer/CnPageRenderer.vue2.js')
).default

/**
 * Every page of the bundled manifest and its fragments.
 *
 * @return {Array<object>} The pages.
 */
function allPages() {
	const files = [path.join(ROOT, 'src/manifest.json')].concat(
		fs
			.readdirSync(path.join(ROOT, 'src/manifest.d'))
			.filter((name) => name.endsWith('.json'))
			.map((name) => path.join(ROOT, 'src/manifest.d', name)),
	)
	return files.flatMap((file) => {
		const pages = JSON.parse(fs.readFileSync(file, 'utf8')).pages
		return Array.isArray(pages) ? pages : []
	})
}

/**
 * Dispatch an action through the installed CnPageRenderer showing this
 * manifest: the `cnDispatchAction` its `provide()` hands every header action
 * and widget, with the renderer's own handler lookup.
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

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('a detail page header action with a handler', () => {
	it('opens the wizard from the import batch page through the renderer', () => {
		const detail = allPages().find((page) => page.id === 'ImportBatchDetail')
		const action = detail.config.headerActions.find(
			(candidate) => candidate.id === 'continue-in-wizard',
		)
		const assign = vi.fn()
		vi.stubGlobal('window', {
			location: {
				assign,
				pathname: '/apps/shillinq/import/batches/batch-7',
			},
		})
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

		const manifest = attachManifestActions({ pages: [detail] })
		dispatchAsRenderer(manifest, action)

		expect(warn).not.toHaveBeenCalled()
		expect(assign).toHaveBeenCalledWith(
			'/index.php/apps/shillinq/import/wizard?batch=batch-7',
		)
	})

	it('resolves every handler a detail or dashboard page names', () => {
		const named = allPages()
			.filter((page) => page.type === 'detail' || page.type === 'dashboard')
			.flatMap((page) => (page.config && page.config.headerActions) || [])
			.filter((action) => action.type === 'handler')
			.map((action) => action.handler)
		expect(named).toContain('openImportWizard')

		const manifest = attachManifestActions({ pages: [] })
		for (const name of named) {
			expect(typeof manifest.actions[name], name).toBe('function')
		}
	})

	it('keeps the handlers out of the serialised manifest', () => {
		const manifest = attachManifestActions({ pages: [] })
		expect(manifest.actions).toBe(manifestActions)
		expect(JSON.parse(JSON.stringify(manifest))).toEqual({ pages: [] })
		expect(Object.keys(manifest)).toEqual(['pages'])
	})

	it('is attached to the manifest the app renders', () => {
		const main = fs.readFileSync(path.join(ROOT, 'src/main.js'), 'utf8')
		expect(main).toMatch(
			/const mergedManifest = reactive\(\s*attachManifestActions\(\s*buildManifest\(/,
		)
		expect(main).toMatch(/^\s+\.\.\.manifestActions,$/m)
	})
})

describe('the import batch page lifecycle buttons', () => {
	/**
	 * The ImportBatch lifecycle the register declares.
	 *
	 * @return {object} The x-openregister-lifecycle block.
	 */
	function registerLifecycle() {
		const register = JSON.parse(
			fs.readFileSync(
				path.join(
					ROOT,
					'lib/Settings/register.d/administration-import-migration.json',
				),
				'utf8',
			),
		)
		const schemas = register.components.schemas
		return schemas.ImportBatch['x-openregister-lifecycle']
	}

	/**
	 * What OpenRegister's available-actions endpoint answers for a batch in this
	 * state: the register's static transitions whose `from` holds the state, in
	 * the shape TransitionEngine::availableActions builds (no `label`).
	 *
	 * @param {string} status The batch status.
	 * @return {Array<object>} The server's actions.
	 */
	function serverAnswer(status) {
		return Object.entries(registerLifecycle().transitions)
			.filter(([, spec]) => [].concat(spec.from ?? []).includes(status))
			.map(([action, spec]) => ({
				action,
				to: spec.to ?? '',
				requires: spec.requires ?? null,
				description: spec.description ?? null,
				inputs: [],
			}))
	}

	/**
	 * The buttons the installed CnLifecycleActions draws for a batch in this
	 * state, after the server answered as OpenRegister does.
	 *
	 * @param {object|boolean} config The page's lifecycleActions config.
	 * @param {string} status The batch status.
	 * @return {Array<object>} The visible transitions.
	 */
	async function buttonsFor(config, status) {
		const CnLifecycleActions = (
			await import('@conduction/nextcloud-vue/dist/esm/components/CnLifecycleActions/CnLifecycleActions.vue2.js')
		).default
		const instance = {
			config,
			object: { status },
			serverActions: serverAnswer(status),
		}
		for (const [name, getter] of Object.entries(CnLifecycleActions.computed)) {
			Object.defineProperty(instance, name, {
				get: () => getter.call(instance),
			})
		}
		Object.assign(instance, CnLifecycleActions.methods)
		return instance.visibleTransitions
	}

	const detail = allPages().find((page) => page.id === 'ImportBatchDetail')
	const config = detail.config.lifecycleActions

	it('labels the mapping step Validate, not with its description, and offers no outcome', async () => {
		const buttons = await buttonsFor(config, 'mapping')
		expect(buttons.map((button) => button.label)).toEqual(['Validate'])
		expect(buttons[0].action).toBe('validate')
	})

	it('declares only transitions the register has, from the same state to the same state', () => {
		const transitions = registerLifecycle().transitions
		const outcomes = ['staged', 'validationFailed', 'posted', 'postingFailed']
		for (const declared of config.transitions) {
			const own = transitions[declared.action]
			expect(own, declared.action).toBeTruthy()
			expect(declared.from).toBe(own.from)
			expect(declared.to).toBe(own.to)
			expect(outcomes).not.toContain(declared.action)
			expect(declared.label.length).toBeLessThan(25)
		}
		expect(config.field).toBe(registerLifecycle().field)
	})
})
