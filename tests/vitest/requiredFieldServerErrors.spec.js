/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * platform-required-fields 2.2 (REQ-PRF-002): until nextcloud-vue takes the
 * administration's required fields as a runtime form input, the form shows
 * the server's refusal under each field.
 *
 * FieldRequirementListener vetoes the save with errors keyed by field plus a
 * `message`; OpenRegister answers that veto as 422 `{error, errors}`
 * (ObjectsController, HookStoppedException). This pins the INSTALLED
 * library's reading of that answer: `parseResponseError` marks it a
 * validation error with the per-field map, which CnIndexPage hands to the
 * form dialog's `setValidationErrors` so the form stays open with each
 * message under its field.
 *
 * @spec openspec/changes/platform-required-fields/tasks.md#task-2.2
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one, many, count) => (count === 1 ? one : many),
}))

/**
 * A fetch Response carrying the given status and JSON body.
 *
 * @param {number} status The HTTP status.
 * @param {object} body The JSON body.
 * @return {object} The response.
 */
function response(status, body) {
	return { status, ok: status < 400, json: async () => body }
}

/** The 422 OpenRegister answers when FieldRequirementListener refuses a supplier invoice. */
const refusal = {
	error: 'This administration requires: Cost centre.',
	errors: {
		message: 'This administration requires: Cost centre.',
		costCentre: 'Cost centre is required in this administration: every purchase is booked on a cost centre',
	},
}

describe('the server\'s required-field refusal in a form (REQ-PRF-002)', () => {
	it('reads as a validation error, so the form stays open', async () => {
		const { parseResponseError } = await import('../../node_modules/@conduction/nextcloud-vue/dist/esm/utils/errors.js')

		const error = await parseResponseError(response(422, refusal), 'object')

		expect(error.isValidation).toBe(true)
		expect(error.status).toBe(422)
	})

	it('carries the message for each missing field, keyed by the field', async () => {
		const { parseResponseError } = await import('../../node_modules/@conduction/nextcloud-vue/dist/esm/utils/errors.js')

		const error = await parseResponseError(response(422, refusal), 'object')

		expect(error.fields.costCentre).toBe(
			'Cost centre is required in this administration: every purchase is booked on a cost centre',
		)
	})

	it('names what is required above the fields', async () => {
		const { parseResponseError } = await import('../../node_modules/@conduction/nextcloud-vue/dist/esm/utils/errors.js')

		const error = await parseResponseError(response(422, refusal), 'object')

		expect(error.message).toContain('This administration requires: Cost centre.')
		expect(error.message).toContain('Cost centre is required in this administration')
	})

	it('keeps the form of an index page open with the per-field map', async () => {
		const { parseResponseError } = await import('../../node_modules/@conduction/nextcloud-vue/dist/esm/utils/errors.js')
		const { createSelfModeActions } = await import('../../node_modules/@conduction/nextcloud-vue/dist/esm/components/CnIndexPage/selfModeActions.js')
		const error = await parseResponseError(response(422, refusal), 'object')
		const store = {
			saveObject: vi.fn(async () => null),
			getError: vi.fn(() => error),
		}
		const form = vi.fn()
		const formValidation = vi.fn()
		const actions = createSelfModeActions({
			isSelfFetchMode: () => true,
			selfObjectStore: () => store,
			selfObjectType: () => 'shillinq-SupplierInvoice',
			editItem: () => null,
			setResults: { form, formValidation },
			emit: vi.fn(),
			list: () => null,
		})

		const handled = await actions.handleFormSave({ supplier: 'Bouwbedrijf Jansen' })

		expect(handled).toBe(true)
		expect(form).not.toHaveBeenCalled()
		expect(formValidation).toHaveBeenCalledTimes(1)
		const [fields, message] = formValidation.mock.calls[0]
		expect(fields.costCentre).toContain('Cost centre is required in this administration')
		expect(message).toContain('This administration requires: Cost centre.')
	})
})
