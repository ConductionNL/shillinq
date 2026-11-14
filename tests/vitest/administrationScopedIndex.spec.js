/**
 * Lists show the active administration only (ledger-multi-administration 2.1,
 * REQ-LMA-002): the `index` page type adds the active administration to the
 * fixed filter of a page whose schema carries `administrationId`, leaves
 * every other page as the renderer passed it, and the scoped list is the one
 * the generator derives from the schemas.
 *
 * @spec openspec/changes/ledger-multi-administration/specs/bookkeeping-multi-administratie/spec.md#requirement-lists-show-the-active-administration-only-req-lma-002
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import fs from 'fs'
import { createRequire } from 'module'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosMock = { get: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))
vi.mock('@conduction/nextcloud-vue', () => ({ defaultPageTypes: { index: { name: 'LibraryIndexPage' } } }))

const ROOT = path.resolve(__dirname, '../..')
const require = createRequire(import.meta.url)
const { buildScopedPages, serialize } = require('../../scripts/generate-administration-scoped-pages.js')
const scopedPages = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/administrationScopedPages.json'), 'utf8'))
const ScopedIndex = await import('../../src/views/AdministrationScopedIndex.vue')
const { isScopedSchema, loadActiveAdministrationId, resetActiveAdministration, scopedProps } = ScopedIndex
const Component = ScopedIndex.default

const SCOPED = scopedPages.schemas[0]
const UNSCOPED = 'NotAScopedSchema'

/**
 * The component's state with its computeds read and methods bound, without mounting it.
 *
 * @param {object} attrs What the renderer passes.
 * @return {object} The instance.
 */
function page(attrs) {
	const vm = { $attrs: attrs }
	Object.assign(vm, Component.data.call(vm))
	for (const [name, fn] of Object.entries(Component.methods)) {
		vm[name] = fn.bind(vm)
	}
	for (const [name, fn] of Object.entries(Component.computed)) {
		Object.defineProperty(vm, name, { get: () => fn.call(vm) })
	}
	return vm
}

beforeEach(() => {
	axiosMock.get.mockReset()
	resetActiveAdministration()
})

describe('the generated list of scoped pages', () => {
	it('is what the generator derives from the schemas and the manifest', () => {
		const text = fs.readFileSync(path.join(ROOT, 'src/administrationScopedPages.json'), 'utf8')
		expect(text).toBe(serialize(buildScopedPages(ROOT)))
	})

	it('names only schemas that declare administrationId and index pages over them', () => {
		expect(scopedPages.schemas).toContain('AdministrationMembership')
		expect(scopedPages.schemas).not.toContain('GLTransaction')
		expect(scopedPages.pages.length).toBeGreaterThan(0)
		expect(isScopedSchema(SCOPED)).toBe(true)
		expect(isScopedSchema(UNSCOPED)).toBe(false)
		expect(isScopedSchema(undefined)).toBe(false)
	})
})

describe('scopedProps', () => {
	it('adds the administration to the fixed filter of a scoped page, keeping its own filter', () => {
		const attrs = { register: 'shillinq', schema: SCOPED, filter: { status: 'open' }, columns: ['a'] }

		expect(scopedProps(attrs, 'adm-2')).toEqual({
			register: 'shillinq',
			schema: SCOPED,
			filter: { status: 'open', administrationId: 'adm-2' },
			columns: ['a'],
		})
		expect(attrs.filter).toEqual({ status: 'open' })
	})

	it('overrides an administrationId the page config names itself', () => {
		expect(scopedProps({ schema: SCOPED, filter: { administrationId: 'adm-1' } }, 'adm-2').filter)
			.toEqual({ administrationId: 'adm-2' })
	})

	it('passes an unscoped page, and a scoped page without an active administration, unchanged', () => {
		const unscoped = { schema: UNSCOPED, filter: { status: 'open' } }
		const scoped = { schema: SCOPED }

		expect(scopedProps(unscoped, 'adm-2')).toBe(unscoped)
		expect(scopedProps(scoped, '')).toBe(scoped)
	})
})

describe('loadActiveAdministrationId', () => {
	it('reads the context endpoint once per page load', async () => {
		axiosMock.get.mockResolvedValue({ data: { activeAdministrationId: 'adm-2' } })

		expect(await loadActiveAdministrationId()).toBe('adm-2')
		expect(await loadActiveAdministrationId()).toBe('adm-2')
		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(axiosMock.get).toHaveBeenCalledWith('/index.php/apps/shillinq/api/administrations/context')
	})

	it('answers empty on a failed read and reads again next time', async () => {
		axiosMock.get.mockRejectedValueOnce(new Error('offline'))
		axiosMock.get.mockResolvedValueOnce({ data: { activeAdministrationId: 'adm-1' } })

		expect(await loadActiveAdministrationId()).toBe('')
		expect(await loadActiveAdministrationId()).toBe('adm-1')
	})
})

describe('AdministrationScopedIndex', () => {
	it('renders the library index page with the attributes it receives', () => {
		expect(Component.components.ScopedIndexPage).toEqual({ name: 'LibraryIndexPage' })
		expect(Component.inheritAttrs).toBe(false)
	})

	it('waits for the administration on a scoped page, then lists that administration only', async () => {
		axiosMock.get.mockResolvedValue({ data: { activeAdministrationId: 'adm-2' } })
		const vm = page({ schema: SCOPED })

		expect(vm.ready).toBe(false)
		await vm.loadScope()

		expect(vm.ready).toBe(true)
		expect(vm.pageProps.filter).toEqual({ administrationId: 'adm-2' })
	})

	it('renders an unscoped page at once, without reading the context', () => {
		const vm = page({ schema: UNSCOPED })
		Component.created.call(vm)

		expect(vm.ready).toBe(true)
		expect(vm.pageProps).toEqual({ schema: UNSCOPED })
		expect(axiosMock.get).not.toHaveBeenCalled()
	})
})
