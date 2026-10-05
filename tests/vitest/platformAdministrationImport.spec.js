/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * platform-administration-import: the wizard walks its six steps against a
 * stubbed register. Each step sends the ImportBatch transition the lifecycle
 * declares and lands on the step the returned status belongs on.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
const pickMock = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@nextcloud/dialogs', () => ({
	getFilePickerBuilder: () => {
		const builder = {
			setMultiSelect: () => builder,
			allowDirectories: () => builder,
			addButton: () => builder,
			build: () => ({ pick: pickMock }),
		}
		return builder
	},
}))

const ROOT = path.resolve(__dirname, '../..')

const helpers = await import('../../src/utils/importWizard.js')
const ImportWizard = (await import('../../src/views/import/ImportWizard.vue'))
	.default

/**
 * A wizard instance: data, methods bound to it, computed as getters.
 *
 * @return {object}
 */
function wizard() {
	const vm = { ...ImportWizard.data() }
	for (const [name, method] of Object.entries(ImportWizard.methods)) {
		vm[name] = method.bind(vm)
	}
	for (const [name, computed] of Object.entries(ImportWizard.computed)) {
		const getter = typeof computed === 'function' ? computed : computed.get
		const setter = typeof computed === 'function' ? undefined : computed.set
		Object.defineProperty(vm, name, {
			get: () => getter.call(vm),
			set: setter ? (value) => setter.call(vm, value) : undefined,
		})
	}
	return vm
}

/**
 * A stubbed register holding one batch and its mapping rows.
 *
 * @return {object} The batch state the stubs read and write.
 */
function register() {
	const state = {
		batch: null,
		mappings: [
			{
				id: 'map-1',
				sourceCode: '0500',
				targetAccount: '0500',
				confirmed: true,
			},
			{
				id: 'map-2',
				sourceCode: '1300',
				targetAccount: '1300',
				confirmed: false,
			},
		],
		transitions: [],
		patched: [],
	}
	const next = {
		parse: (batch) => ({ ...batch, status: 'staged' }),
		startMapping: (batch) => ({ ...batch, status: 'mapping' }),
		validate: (batch) => ({
			...batch,
			status: 'validated',
			validationReport: { valid: true, findings: [] },
		}),
		dryRun: (batch) => ({
			...batch,
			status: 'dry_run_complete',
			dryRunReport: {
				openingJournal: {
					lines: [
						{ debit: 24200, credit: 0 },
						{ debit: 5800, credit: 0 },
						{ debit: 0, credit: 30000 },
					],
				},
				arOpenItems: [],
				apOpenItems: [],
				contacts: [{ name: 'Acme BV' }],
			},
		}),
		post: (batch) => ({
			...batch,
			status: 'posted',
			postingReport: { findings: [] },
		}),
	}
	axiosMock.post.mockImplementation(async (url, body) => {
		if (url.endsWith('/ImportBatch')) {
			state.batch = { ...body, id: 'batch-1' }
			return { data: state.batch }
		}
		state.transitions.push(body.action)
		state.batch = next[body.action](state.batch)
		return { data: state.batch }
	})
	axiosMock.get.mockImplementation(async (url) => {
		if (url.includes('/administrations/context')) {
			return { data: { activeAdministrationId: 'adm-new' } }
		}
		if (url.includes('/ImportMapping')) {
			return { data: { results: state.mappings } }
		}
		return { data: state.batch }
	})
	axiosMock.patch.mockImplementation(async (url, body) => {
		const id = url.split('/').pop()
		state.patched.push(id)
		state.mappings = state.mappings.map((row) =>
			row.id === id ? { ...row, ...body } : row,
		)
		return { data: {} }
	})
	return state
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('the import wizard', () => {
	it('walks from choosing the file to a posted import', async () => {
		const state = register()
		const vm = wizard()
		await vm.resolveAdministration()
		expect(vm.step).toBe('upload')

		pickMock.mockResolvedValue('/Migrations/auditfile-2025.xaf')
		await vm.pickFile()
		expect(vm.form.path).toBe('/Migrations/auditfile-2025.xaf')

		vm.step = 'profile'
		vm.sourceSystem = vm.sourceSystems.find(
			(option) => option.id === 'snelstart',
		)
		expect(vm.ready).toBe(false)
		vm.form.migrationDate = '2026-01-01'
		expect(vm.ready).toBe(true)

		await vm.start()
		expect(axiosMock.post.mock.calls[0][1]).toMatchObject({
			administrationId: 'adm-new',
			sourceSystem: 'snelstart',
			sourceFiles: [{ path: '/Migrations/auditfile-2025.xaf', kind: 'xaf' }],
			migrationDate: '2026-01-01',
			status: 'draft',
		})
		expect(state.transitions).toEqual(['parse', 'startMapping'])
		expect(vm.step).toBe('mapping')
		expect(vm.open).toHaveLength(1)

		await vm.confirmAll()
		expect(state.patched).toEqual(['map-2'])
		expect(vm.open).toHaveLength(0)

		await vm.transition('validate')
		expect(vm.step).toBe('validation')
		expect(vm.dryRunAllowed).toBe(true)

		await vm.transition('dryRun')
		expect(vm.step).toBe('dry-run')
		expect(vm.totals).toEqual({ debit: 30000, credit: 30000, lines: 3 })

		await vm.transition('post')
		expect(state.transitions).toEqual([
			'parse',
			'startMapping',
			'validate',
			'dryRun',
			'post',
		])
		expect(vm.step).toBe('post')
		expect(vm.batch.status).toBe('posted')
		expect(vm.error).toBe('')
	})

	it('holds the dry run back while validation has an error finding', () => {
		const vm = wizard()
		vm.batch = {
			status: 'validation_failed',
			validationReport: {
				findings: [
					{
						severity: 'error',
						code: 'opening-journal-unbalanced',
						message: 'Opening balance is not balanced.',
					},
				],
			},
		}

		expect(vm.dryRunAllowed).toBe(false)
		expect(helpers.stepForBatch(vm.batch)).toBe('validation')
	})

	it('shows the refusal the server sends, naming the first batch', async () => {
		register()
		const vm = wizard()
		vm.batch = { id: 'batch-2', status: 'dry_run_complete' }
		vm.step = 'dry-run'
		axiosMock.post.mockRejectedValueOnce({
			response: {
				data: {
					message:
						'These files were already imported by import batch batch-1.',
				},
			},
		})

		await vm.transition('post')

		expect(vm.error).toContain('batch-1')
		expect(vm.step).toBe('dry-run')
		expect(vm.busy).toBe(false)
	})
})

describe('coming back to a batch (live pass S3)', () => {
	it('opens the batch the route names, on the step its status belongs on', async () => {
		const state = register()
		state.batch = { id: 'batch-1', status: 'mapping' }
		const vm = wizard()
		vm.$route = { query: { batch: 'batch-1' } }

		await ImportWizard.mounted.call(vm)

		expect(vm.batch.id).toBe('batch-1')
		expect(vm.step).toBe('mapping')
		expect(vm.mappings.map((row) => row.id)).toEqual(['map-1', 'map-2'])
		expect(vm.open).toHaveLength(1)
		expect(vm.form.administrationId).toBe('adm-new')
	})

	it('goes on from the mapping review after the rows were mapped elsewhere', async () => {
		const state = register()
		state.batch = { id: 'batch-1', status: 'mapping' }
		state.mappings = state.mappings.map((row) => ({ ...row, confirmed: true }))
		const vm = wizard()
		vm.$route = { query: { batch: 'batch-1' } }
		await ImportWizard.mounted.call(vm)

		await vm.transition('validate')
		await vm.transition('dryRun')

		expect(state.transitions).toEqual(['validate', 'dryRun'])
		expect(vm.step).toBe('dry-run')
		expect(vm.totals).toEqual({ debit: 30000, credit: 30000, lines: 3 })
	})

	it('names a batch it cannot open and starts at the first step', async () => {
		register()
		axiosMock.get.mockImplementation(async (url) => {
			if (url.includes('/ImportBatch/')) {
				throw {
					response: { status: 404, data: { message: 'Object not found' } },
				}
			}
			return { data: { activeAdministrationId: 'adm-new' } }
		})
		const vm = wizard()
		vm.$route = { query: { batch: 'gone' } }

		await ImportWizard.mounted.call(vm)

		expect(vm.batch).toBeNull()
		expect(vm.step).toBe('upload')
		expect(vm.error).not.toBe('')
	})

	it('puts the new batch in the address, so leaving the page does not lose it', async () => {
		register()
		const vm = wizard()
		vm.$route = { query: {} }
		vm.$router = { replace: vi.fn() }
		await ImportWizard.mounted.call(vm)
		vm.form.path = '/Migrations/auditfile-2025.xaf'
		vm.form.migrationDate = '2026-01-01'

		await vm.start()

		expect(vm.$router.replace).toHaveBeenCalledWith({
			query: { batch: 'batch-1' },
		})

		await vm.restart()
		expect(vm.$router.replace).toHaveBeenLastCalledWith({ query: {} })
	})

	it('is offered on the batch page, through a registered handler', () => {
		const manifest = JSON.parse(
			fs.readFileSync(
				path.join(
					ROOT,
					'src/manifest.d/administration-import-migration.json',
				),
				'utf8',
			),
		)
		const detail = manifest.pages.find(
			(candidate) => candidate.id === 'ImportBatchDetail',
		)
		const action = (detail.config.headerActions || []).find(
			(candidate) => candidate.handler === 'openImportWizard',
		)
		expect(action).toBeTruthy()
		expect(action.type).toBe('handler')

		const handlers = fs.readFileSync(
			path.join(ROOT, 'src/manifestActions.js'),
			'utf8',
		)
		expect(handlers).toMatch(/^\s+openImportWizard,$/m)
		expect(typeof helpers.openImportWizard).toBe('function')
	})

	it('opens the wizard on the batch the page shows', () => {
		const assign = vi.fn()
		vi.stubGlobal('window', {
			location: { assign, pathname: '/apps/shillinq/import/batches/batch-9' },
		})
		try {
			expect(helpers.openImportWizard({ item: { id: 'batch-1' } })).toBe(true)
			expect(assign).toHaveBeenLastCalledWith(
				'/index.php/apps/shillinq/import/wizard?batch=batch-1',
			)
			helpers.openImportWizard({})
			expect(assign).toHaveBeenLastCalledWith(
				'/index.php/apps/shillinq/import/wizard?batch=batch-9',
			)
		} finally {
			vi.unstubAllGlobals()
		}
	})
})

describe('the wizard helpers', () => {
	it('puts each status on its step', () => {
		expect(helpers.stepForBatch(null)).toBe('upload')
		expect(helpers.stepForBatch({ status: 'mapping' })).toBe('mapping')
		expect(helpers.stepForBatch({ status: 'posting_failed' })).toBe('post')
		expect(helpers.stepForBatch({ status: 'reversed' })).toBe('post')
	})

	it('adds the opening balance in cents', () => {
		expect(
			helpers.openingTotals({
				openingJournal: {
					lines: [{ debit: 0.1 }, { debit: 0.2 }, { credit: 0.3 }],
				},
			}),
		).toEqual({ debit: 0.3, credit: 0.3, lines: 3 })
	})
})

describe('the wizard page', () => {
	it('is registered and its manifest page is no longer deferred', () => {
		const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
		expect(registry).toMatch(
			/ImportWizard: \{ kind: 'page', component: ImportWizard \}/,
		)

		const manifest = JSON.parse(
			fs.readFileSync(
				path.join(
					ROOT,
					'src/manifest.d/administration-import-migration.json',
				),
				'utf8',
			),
		)
		const page = manifest.pages.find(
			(candidate) => candidate.id === 'ImportWizard',
		)
		expect(page.component).toBe('ImportWizard')
		expect(page.config?.['x-deferred']).toBeUndefined()
	})
})
