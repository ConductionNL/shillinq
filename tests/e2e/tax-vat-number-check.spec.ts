/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A bookkeeper checks the VAT number of Kunstverlag Müller GmbH from the
 * customer page, sees VIES down on Softwarehuis BVBA's supplier page, and the
 * Belgian bill shows the seller's number with its check (tax-vat-number-check
 * REQ-TVNC-001 to 003).
 *
 * VIES is stubbed through page.route on shillinq's check route; the outcome
 * written onto the record is proven by VatNumberCheckTest and
 * SupplierInvoiceServiceTest.
 *
 * @spec openspec/changes/tax-vat-number-check/tasks.md#task-3.2
 */

import { expect, test } from '@playwright/test'

const mullerId = 'c0570000-0000-4000-8000-000000000001'
const softwarehuisId = 'b0570000-0000-4000-8000-000000000002'
const billId = '5b570000-0000-4000-8000-000000000003'

test.describe('tax-vat-number-check', () => {
	/**
	 * @e2e bookkeeping-icp-opgaaf::a-bookkeeper-checks-a-german-customer
	 */
	test('checking a German customer shows the VIES result', async ({ page }) => {
		await page.route(`**/api/objects/shillinq/CustomerMaster/${mullerId}**`, (route) =>
			route.fulfill({ json: { id: mullerId, customerId: 'DEB-0107', legalName: 'Kunstverlag Müller GmbH', vatId: 'DE000000000', vatIdValidationStatus: 'unchecked' } }),
		)
		await page.route(`**/apps/shillinq/api/vat-number-checks/customer/${mullerId}`, (route) =>
			route.fulfill({ json: { status: 'valid', vatId: 'DE000000000', checkedAt: '2026-10-02T09:00:00+00:00', lastValidAt: '2026-10-02T09:00:00+00:00', name: 'Kunstverlag Müller GmbH', address: 'Köln' } }),
		)

		await page.goto(`/index.php/apps/shillinq/bookkeeping/customers/${mullerId}`)
		await page.getByRole('button', { name: 'Check VAT number' }).click()

		await expect(page.getByText(/VAT number DE000000000 is valid/)).toBeVisible()
	})

	/**
	 * @e2e bookkeeping-icp-opgaaf::vies-is-down
	 */
	test('VIES down shows not reachable and the last valid date', async ({ page }) => {
		await page.route(`**/api/objects/shillinq/Payee/${softwarehuisId}**`, (route) =>
			route.fulfill({ json: { id: softwarehuisId, vendorNumber: 'CRED-0042', name: 'Softwarehuis BVBA', vatNumber: 'BE0000000000' } }),
		)
		await page.route(`**/apps/shillinq/api/vat-number-checks/supplier/${softwarehuisId}`, (route) =>
			route.fulfill({ json: { status: 'vies_outage', vatId: 'BE0000000000', checkedAt: '2026-10-02T09:00:00+00:00', lastValidAt: '2026-09-22T09:00:00+00:00', name: '', address: '' } }),
		)

		await page.goto(`/index.php/apps/shillinq/bookkeeping/payees/${softwarehuisId}`)
		await page.getByRole('button', { name: 'Check VAT number' }).click()

		await expect(page.getByText(/VIES cannot be reached\. VAT number BE0000000000 was last valid on/)).toBeVisible()
	})

	/**
	 * @e2e bookkeeping-icp-opgaaf::a-ubl-invoice-fills-the-sellers-number
	 * @e2e bookkeeping-icp-opgaaf::a-belgian-bill-arrives
	 */
	test('the Belgian bill shows the seller VAT number as valid', async ({ page }) => {
		await page.route(`**/api/objects/shillinq/SupplierInvoice/${billId}**`, (route) =>
			route.fulfill({ json: { id: billId, invoiceNumber: 'SH-2026-118', statusCode: 'received', currency: 'EUR', invoiceDate: '2026-09-28', totalInclVat: 1210, sellerVatId: 'BE0000000000', sellerVatIdValidationStatus: 'valid', sellerVatIdValidatedAt: '2026-10-02T09:00:00+00:00' } }),
		)

		await page.goto(`/index.php/apps/shillinq/inkoop/supplier-invoices/${billId}`)

		await expect(page.getByTestId('si-seller-vat')).toContainText('BE0000000000')
		await expect(page.getByTestId('si-seller-vat')).toContainText('valid, checked on')
	})
})
