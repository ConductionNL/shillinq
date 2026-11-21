/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * sales-invoice-document 4.1 (REQ-SID-001): the invoice layout pages. The
 * contrast check gives the WCAG reference ratios and draws the 4.5:1 line
 * where the server does; the Preview tab draws the saved colours, logo,
 * variant and footer and writes out each colour's verdict; the manifest
 * pages, the registry widget and the settings foldout are held together.
 *
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-4.1
 */

import fs from 'fs'
import path from 'path'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, vars = {}) =>
		'/index.php' + url.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)),
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/{(\w+)}/g, (m, k) => (k in vars ? vars[k] : m)),
}))

const { AA_NORMAL_TEXT, contrastVerdict, ratioAgainstWhite } = await import('../../src/utils/colourContrast.js')
const { default: InvoiceLayoutPreview } = await import('../../src/components/sales/InvoiceLayoutPreview.vue')

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/sales-invoice-document.json'), 'utf8'))
const registryJs = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')
const menuLayout = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.json'), 'utf8'))
const registerFragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib/Settings/register.d/zz-sales-invoice-document.json'), 'utf8'))

/**
 * Render the preview over a layout.
 *
 * @param {object|null} layout The InvoiceLayout.
 * @return {Promise<string>} The HTML.
 */
function preview(layout) {
	return renderToString(createSSRApp(InvoiceLayoutPreview, { objectData: layout }))
}

describe('the contrast check', () => {
	it('runs from 1:1 to 21:1', () => {
		expect(ratioAgainstWhite('#000000')).toBeCloseTo(21, 3)
		expect(ratioAgainstWhite('#ffffff')).toBeCloseTo(1, 3)
	})

	it('draws the 4.5:1 line between the two reference greys', () => {
		expect(AA_NORMAL_TEXT).toBe(4.5)
		expect(ratioAgainstWhite('#767676')).toBeGreaterThanOrEqual(4.5)
		expect(ratioAgainstWhite('#777777')).toBeLessThan(4.5)
	})

	it('passes the spec colour and both schema defaults', () => {
		const props = registerFragment.components.schemas.InvoiceLayout.properties
		for (const hex of ['#1B4F72', props.primaryColor.default, props.accentColor.default]) {
			expect(contrastVerdict('Primary colour', hex).passes).toBe(true)
		}
	})

	it('writes the verdict out, rounded down so a failing colour never reads 4.5', () => {
		expect(contrastVerdict('Primary colour', '#777777')).toMatchObject({
			passes: false,
			text: 'Primary colour: contrast 4.4:1 against white, below 4.5:1. Choose a darker colour.',
		})
		expect(contrastVerdict('Accent colour', '#000000').text).toBe('Accent colour: contrast 21.0:1 against white, readable.')
	})

	it('has no ratio for a value that is not a #RRGGBB colour', () => {
		expect(ratioAgainstWhite('#FFF')).toBeNull()
		expect(ratioAgainstWhite(undefined)).toBeNull()
		expect(contrastVerdict('Primary colour', 'navy')).toEqual({
			ratio: null,
			passes: false,
			text: 'Primary colour is not a #RRGGBB colour.',
		})
	})
})

describe('the Preview tab', () => {
	it('draws the layout in its colours, logo, variant and footer', async () => {
		const html = await preview({
			primaryColor: '#1B4F72',
			accentColor: '#B8461B',
			variant: 'modern',
			logoFileId: '812',
			footerText: 'KvK 12345678',
			showQr: true,
		})
		expect(html).toContain('--ilp-primary:#1B4F72')
		expect(html).toContain('--ilp-accent:#B8461B')
		expect(html).toContain('ilp__page--modern')
		expect(html).toContain('/index.php/core/preview?fileId=812&amp;x=240&amp;y=80&amp;a=1')
		expect(html).toContain('KvK 12345678')
		expect(html).toContain('data-testid="invoice-layout-preview-qr"')
		expect(html).toContain('Variant: Modern')
		expect(html).toContain('Primary colour: contrast 8.7:1 against white, readable.')
	})

	it('falls back to the schema defaults and says there is no logo', async () => {
		const html = await preview({ showQr: false })
		expect(html).toContain('--ilp-primary:#0F4C81')
		expect(html).toContain('ilp__page--classic')
		expect(html).toContain('No logo')
		expect(html).not.toContain('invoice-layout-preview-qr')
		expect(html).not.toContain('<footer')
	})

	it('flags a light colour as an alert, in words', async () => {
		const html = await preview({ primaryColor: '#FFD700' })
		const failing = html.slice(html.indexOf('data-testid="invoice-layout-contrast-primary"') - 80)
		expect(failing).toMatch(/role="alert"/)
		expect(html).toContain('Primary colour: contrast 1.4:1 against white, below 4.5:1. Choose a darker colour.')
	})
})

describe('the invoice layout pages', () => {
	it('lists the layouts and opens one with a Preview tab that renders a registered widget', () => {
		const index = fragment.pages.find((p) => p.id === 'InvoiceLayouts')
		const detail = fragment.pages.find((p) => p.id === 'InvoiceLayoutDetail')
		expect(index).toMatchObject({ type: 'index', config: { schema: 'InvoiceLayout', detailRoute: 'InvoiceLayoutDetail' } })
		expect(detail).toMatchObject({ type: 'detail', config: { schema: 'InvoiceLayout', indexRoute: 'InvoiceLayouts' } })
		const tab = detail.config.sidebarProps.tabs.find((x) => x.id === 'preview')
		expect(tab.widgets[0].type).toBe('InvoiceLayoutPreview')
		const entry = registryJs.slice(registryJs.indexOf('\tInvoiceLayoutPreview: {'))
		expect(entry.slice(0, entry.indexOf('\n\t},'))).toContain("kind: 'widget'")
	})

	it('edits every field of the schema', () => {
		const detail = fragment.pages.find((p) => p.id === 'InvoiceLayoutDetail')
		const keys = detail.config.fields.map((f) => f.key).sort()
		expect(keys).toEqual(Object.keys(registerFragment.components.schemas.InvoiceLayout.properties).sort())
	})

	it('sits in the settings foldout, not under /settings/user', () => {
		expect(fragment.menu.map((m) => m.id)).toContain('InvoiceLayouts')
		expect(menuLayout.settingsSection).toContain('InvoiceLayouts')
		for (const page of fragment.pages) {
			expect(page.route.startsWith('/settings')).toBe(false)
		}
	})
})
