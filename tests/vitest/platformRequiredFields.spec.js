/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * platform-required-fields: a refused save shows under its field, and the
 * settings pages reach the endpoint they read.
 *
 * FieldRequirementListener refuses with errors keyed by field plus a
 * `message`; OpenRegister answers 422 {error, errors}. The form dialog puts
 * `fields[key]` under the field and `message` above the form. These tests run
 * nextcloud-vue's own parseResponseError over that exact body, so a change on
 * either side shows here.
 *
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { parseResponseError } from '@conduction/nextcloud-vue/src/utils/errors.js'

const root = path.resolve(__dirname, '../..')

/**
 * The 422 OpenRegister sends for a hook refusal, with the listener's errors.
 *
 * @param {object} errors The listener's errors.
 * @return {Response} The response.
 */
function refusal(errors) {
	return new Response(JSON.stringify({ error: errors.message, errors }), { status: 422 })
}

describe('platform-required-fields', () => {
	it('shows the reason under the missing field and the summary above the form', async () => {
		const reason = 'Cost Center is required in this administration: Elke inkoopfactuur wordt op een kostenplaats verantwoord'
		const error = await parseResponseError(refusal({
			message: 'This administration requires: Cost Center.',
			costCenter: reason,
		}), 'SupplierInvoice')

		expect(error.isValidation).toBe(true)
		expect(error.fields.costCenter).toBe(reason)
		expect(error.message).toContain('This administration requires: Cost Center.')
		expect(error.message).toContain('Elke inkoopfactuur')
	})

	it('puts the settings page in the settings foldout and reads a routed endpoint', () => {
		const fragment = JSON.parse(fs.readFileSync(path.join(root, 'src/manifest.d/platform-required-fields.json'), 'utf8'))
		const layout = JSON.parse(fs.readFileSync(path.join(root, 'src/menu-layout.json'), 'utf8'))
		const routes = fs.readFileSync(path.join(root, 'appinfo/routes.php'), 'utf8')

		expect(fragment.menu.map((item) => item.id)).toContain('RequiredFields')
		expect(layout.settingsSection).toContain('RequiredFields')

		const detail = fragment.pages.find((page) => page.id === 'RequiredFieldDetail')
		const table = detail.config.widgets.find((widget) => widget.type === 'object-table')
		expect(table.content.endpointSource.url).toBe('/apps/shillinq/api/field-requirements/@objectId/fields')
		expect(routes).toContain("['name' => 'fieldRequirement#fields', 'url' => '/api/field-requirements/{id}/fields', 'verb' => 'GET']")
		expect(detail.config.layout.map((cell) => cell.widgetId).sort()).toEqual(detail.config.widgets.map((widget) => widget.id).sort())
	})
})
