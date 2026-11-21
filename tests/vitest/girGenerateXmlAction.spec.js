/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * tax-cbcr-and-pillar2-returns 4.3 and 6.3 (REQ-CBC-009, REQ-CBC-011):
 * Generate XML on the minimum tax return page.
 *
 * The action is manifest configuration; it posts to the GIR file route that
 * appinfo/routes.php declares for CbcrFileController::generateGir, and shows
 * only on a draft return, as the service refuses any other state. Its
 * `visibleWhen` is evaluated through the INSTALLED library's own
 * `evaluateVisibleWhen`, the function CnActionButtons calls.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => null,
	getRequestToken: () => '',
	onRequestTokenUpdate: () => {},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const root = path.resolve(__dirname, '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(root, 'src/manifest.d/tax-cbcr-and-pillar2-returns.json'), 'utf8'))
const routes = fs.readFileSync(path.join(root, 'appinfo/routes.php'), 'utf8')
const controller = fs.readFileSync(path.join(root, 'lib/Controller/CbcrFileController.php'), 'utf8')

/**
 * The Generate XML action of the minimum tax return page.
 *
 * @return {object|undefined} The action.
 */
function generateAction() {
	const page = fragment.pages.find((p) => p.id === 'GlobeInformationReturnDetail')
	return (page?.config?.headerActions || []).find((a) => a.id === 'generate-xml')
}

describe('Generate XML on the minimum tax return (REQ-CBC-009)', () => {
	it('posts to the declared GIR file route', () => {
		const action = generateAction()
		expect(action).toBeTruthy()
		expect(action.type).toBe('api-call')
		expect(action.method).toBe('POST')
		expect(action.url).toBe('/apps/shillinq/api/cbcr/minimum-tax-returns/@objectId/xml')
		expect(routes).toContain("['name' => 'cbcrFile#generateGir', 'url' => '/api/cbcr/minimum-tax-returns/{returnId}/xml', 'verb' => 'POST']")
		expect(controller).toMatch(/public function generateGir\(string \$returnId\)/)
	})

	it('shows on a draft return only', async () => {
		const { evaluateVisibleWhen } = await import('../../node_modules/@conduction/nextcloud-vue/dist/esm/utils/visibleWhen.js')
		const action = generateAction()

		expect(await evaluateVisibleWhen(action.visibleWhen, { objectId: 'gir-1', object: { state: 'draft' } })).toBe(true)
		for (const state of ['approved', 'submitted', 'locked']) {
			expect(await evaluateVisibleWhen(action.visibleWhen, { objectId: 'gir-1', object: { state } })).toBe(false)
		}
	})

	it('lists the missing elements with their entity on the return', () => {
		const page = fragment.pages.find((p) => p.id === 'GlobeInformationReturnDetail')
		expect(page.config.fields.map((f) => f.key)).toContain('missingElements')

		const register = JSON.parse(fs.readFileSync(path.join(root, 'lib/Settings/register.d/tax-cbcr-and-pillar2-returns.json'), 'utf8'))
		const item = register.components.schemas.GlobeInformationReturn.properties.missingElements.items
		expect(Object.keys(item.properties)).toEqual(['jurisdiction', 'entity', 'element'])
	})
})
