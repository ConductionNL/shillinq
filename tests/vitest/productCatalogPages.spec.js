/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The product and attribute catalogs are endpoint index pages: they list
 * shillinq's own catalog endpoints, say which source answered, and show
 * money in the row's currency.
 */

import { describe, expect, it } from 'vitest'

const manifest = (await import('../../src/manifest.d/inventory-product-catalog.json')).default
const registry = (await import('node:fs')).readFileSync(new URL('../../src/registry.js', import.meta.url), 'utf8')
const page = (id) => manifest.pages.find((p) => p.id === id)

describe('product catalog pages', () => {
	it.each([
		['ProductCatalog', '/apps/shillinq/api/inventory/products', 'products'],
		['ProductAttributeCatalog', '/apps/shillinq/api/inventory/product-attributes', 'attributes'],
	])('%s lists its endpoint and shows which source answered', (id, url, rowsPath) => {
		const { type, config } = page(id)
		expect(type).toBe('index')
		expect(config.entitySource).toBe('endpoint')
		expect(config.sourceConfig).toMatchObject({ url, rowsPath, notePath: 'sourceNote' })
		expect(config.sourceConfig.refusedText).toContain('no administration')
		expect(config.showAdd).toBe(false)
	})

	it('shows prices and costs in the currency of the row', () => {
		const columns = page('ProductCatalog').config.columns
		for (const key of ['unitPrice', 'unitCost']) {
			expect(columns.find((c) => c.key === key)).toMatchObject({ formatter: 'currency', formatterOptions: { currencyField: 'currency' } })
		}
	})

	it('leaves no custom catalog component behind', () => {
		expect(registry).not.toMatch(/ProductCatalogIndex|ProductAttributeCatalogIndex/)
	})
})
