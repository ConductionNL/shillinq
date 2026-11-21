/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An administrator sees the fields an administration requires, and a
 * bookkeeper's save without one is refused with the reason under the field
 * (platform-required-fields REQ-PRF-001, REQ-PRF-002).
 *
 * The register rows and the refusal are answered through page.route with the
 * exact shapes FieldRequirementListenerTest and FieldRequirementControllerTest
 * pin: the 422 body is OpenRegister's {error, errors} for a hook refusal, the
 * field list is FieldRequirementController::fields(). So this test proves the
 * screens; who is refused and who is not (another administration, a system
 * write) is proven by FieldRequirementListenerTest.
 *
 * @spec openspec/changes/platform-required-fields/specs/app-administration/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

const requirementId = '7b1c2d3e-4f50-4a61-9b72-8c93d0e1f2a3'
const reason = 'Elke inkoopfactuur wordt op een kostenplaats verantwoord'

const requirement = {
	id: requirementId,
	administrationId: 'adm-gov-1',
	schema: 'SupplierInvoice',
	field: 'costCenter',
	reason,
	lifecycleState: 'active',
}

const fields = [
	{
		field: 'invoiceNumber',
		title: 'Invoice Number',
		required: 'always',
		requiredLabel: 'Always',
	},
	{
		field: 'costCenter',
		title: 'Cost Center',
		required: 'administration',
		requiredLabel: 'In this administration',
	},
	{
		field: 'description',
		title: 'Description',
		required: 'no',
		requiredLabel: 'No',
	},
]

/**
 * Open the supplier invoice create form; false when the page offers none.
 *
 * @param page The page.
 * @return Whether the form is open.
 */
async function openSupplierInvoiceForm(page: Page): Promise<boolean> {
	await page.goto('/index.php/apps/shillinq/inkoop/supplier-invoices')
	const add = page.getByRole('button', { name: /^(add|new|create)/i }).first()
	if (!(await add.isVisible().catch(() => false))) {
		return false
	}
	await add.click()
	return page
		.getByRole('dialog')
		.isVisible()
		.catch(() => false)
}

/**
 * Submit the open create form.
 *
 * @param page The page.
 */
async function submitForm(page: Page): Promise<void> {
	await page
		.getByRole('dialog')
		.getByRole('button', { name: /^(save|create|add)/i })
		.last()
		.click()
}

test.describe('platform-required-fields', () => {
	/**
	 * @e2e app-administration::cost-centre-becomes-required-on-purchase-invoices
	 */
	test('the required fields page lists cost centre on supplier invoices with its reason', async ({
		page,
	}) => {
		await page.route('**/api/objects/shillinq/FieldRequirement**', (route) => {
			if (route.request().url().includes(requirementId)) {
				return route.fulfill({ json: requirement })
			}
			return route.fulfill({
				json: { results: [requirement], total: 1, page: 1, pages: 1 },
			})
		})
		await page.route(
			`**/apps/shillinq/api/field-requirements/${requirementId}/fields**`,
			(route) => route.fulfill({ json: { rows: fields } }),
		)

		await page.goto('/index.php/apps/shillinq/settings/required-fields')
		const row = page.getByRole('row', { name: /costCenter/ })
		await expect(row).toContainText('SupplierInvoice')
		await expect(row).toContainText(reason)

		await page.goto(
			`/index.php/apps/shillinq/settings/required-fields/${requirementId}`,
		)
		await expect(page.getByText('Fields of this record type')).toBeVisible()
		await expect(page.getByRole('row', { name: /Cost Center/ })).toContainText(
			'In this administration',
		)
		await expect(
			page.getByRole('row', { name: /Invoice Number/ }),
		).toContainText('Always')
	})

	/**
	 * @e2e app-administration::a-bookkeeper-saves-an-invoice-without-a-cost-centre
	 */
	test('a save without the cost centre is refused and the form shows the reason', async ({
		page,
	}) => {
		await page.route('**/api/objects/shillinq/SupplierInvoice**', (route) => {
			if (route.request().method() !== 'POST') {
				return route.continue()
			}
			return route.fulfill({
				status: 422,
				json: {
					error: 'This administration requires: Cost Center.',
					errors: {
						message: 'This administration requires: Cost Center.',
						costCenter: `Cost Center is required in this administration: ${reason}`,
					},
				},
			})
		})
		test.skip(
			!(await openSupplierInvoiceForm(page)),
			'Supplier invoice form not reachable',
		)

		await submitForm(page)

		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible()
		await expect(dialog).toContainText(reason)
	})

	/**
	 * @e2e app-administration::another-administration-is-not-affected
	 */
	test('a save the server accepts closes the form without a refusal', async ({
		page,
	}) => {
		await page.route('**/api/objects/shillinq/SupplierInvoice**', (route) => {
			if (route.request().method() !== 'POST') {
				return route.continue()
			}
			return route.fulfill({
				status: 201,
				json: { id: 'si-van-dijk', administrationId: 'adm-consultancy-nl' },
			})
		})
		test.skip(
			!(await openSupplierInvoiceForm(page)),
			'Supplier invoice form not reachable',
		)

		await submitForm(page)

		await expect(page.getByText(reason)).toHaveCount(0)
	})
})
