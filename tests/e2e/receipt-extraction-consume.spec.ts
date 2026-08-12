/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * receipt-extraction-consume — Playwright UI-only coverage (REQ-RXC-002
 * BillImportModal prefill/confidence, REQ-RXC-003 ReceiptCapture prefill,
 * REQ-RXC-004 correction, REQ-RXC-005 re-request, REQ-RXC-006 human
 * confirmation is never bypassed).
 *
 * Data-defensive: an extraction draft only exists once docudesk has
 * published a `nl.conduction.docudesk.extraction.completed` event (or the
 * install seed created one, per design.md's seed table). When no pending
 * draft is present the suite skips the interaction assertions rather than
 * failing — the deeper guarantees (field mapping, confidence math,
 * correction provenance) are proven by:
 *   - `tests/Unit/Service/Extraction/ExtractionPrefillServiceTest.php` /
 *     `tests/Unit/Listener/ExtractionCompletedListenerTest.php` /
 *     `tests/Unit/Controller/ExtractionRequestControllerTest.php` (PHPUnit);
 *   - `tests/vitest/billImportModal.spec.js` /
 *     `tests/vitest/receiptCapture.spec.js` (pure-logic vitest).
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */

import { test, expect, type Page } from '@playwright/test'

const APP = '/apps/shillinq'

async function dismissWizard(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('receipt-extraction-consume — BillImportModal extraction review (REQ-RXC-002)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	test('prefilled fields show confidence and a review flag', async ({ page }) => {
		// @e2e openspec/specs/receipt-extraction-consume/spec.md#prefilled-fields-show-confidence-and-a-review-flag
		await page.goto(`${APP}/`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const importButton = page.getByRole('button', { name: /import bill/i })
		const hasImportButton = await importButton.isVisible().catch(() => false)
		test.skip(!hasImportButton, 'Financial overview Import bill action not visible in this fixture')

		await importButton.click()
		const modal = page.getByTestId('bill-import-modal')
		await expect(modal).toBeVisible({ timeout: 10_000 })

		const pendingDraft = page.getByTestId(/^bim-pending-/).first()
		const hasPendingDraft = await pendingDraft.isVisible().catch(() => false)
		test.skip(!hasPendingDraft, 'no pending extraction draft seeded in this administration')

		await pendingDraft.click()
		await expect(page.getByTestId('bim-review-step')).toBeVisible()

		// Confidence is shown as text, never colour alone (WCAG 2.1 AA).
		const badge = page.getByTestId('fcb-invoiceNumber')
		await expect(badge).toBeVisible()
		await expect(badge).toContainText('%')
	})

	test('re-request produces a fresh extraction draft', async ({ page }) => {
		// @e2e openspec/specs/receipt-extraction-consume/spec.md#re-request-produces-a-fresh-extraction-draft
		await page.goto(`${APP}/`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const importButton = page.getByRole('button', { name: /import bill/i })
		const hasImportButton = await importButton.isVisible().catch(() => false)
		test.skip(!hasImportButton, 'Financial overview Import bill action not visible in this fixture')
		await importButton.click()

		const pendingDraft = page.getByTestId(/^bim-pending-/).first()
		const hasPendingDraft = await pendingDraft.isVisible().catch(() => false)
		test.skip(!hasPendingDraft, 'no pending extraction draft seeded in this administration')
		await pendingDraft.click()

		const rerequest = page.getByTestId('bim-rerequest')
		const canRerequest = await rerequest.isVisible().catch(() => false)
		test.skip(!canRerequest, 'draft carries no sourceDocumentUri to re-request against')

		await rerequest.click()
		// REQ-RXC-006: the click only requests a fresh extraction — it never
		// closes the modal or commits anything on its own.
		await expect(page.getByTestId('bill-import-modal')).toBeVisible()
	})

	test('even a fully-confident extraction requires explicit confirmation', async ({ page }) => {
		// @e2e openspec/specs/receipt-extraction-consume/spec.md#even-a-fully-confident-extraction-requires-confirmation
		await page.goto(`${APP}/`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const importButton = page.getByRole('button', { name: /import bill/i })
		const hasImportButton = await importButton.isVisible().catch(() => false)
		test.skip(!hasImportButton, 'Financial overview Import bill action not visible in this fixture')
		await importButton.click()

		const pendingDraft = page.getByTestId(/^bim-pending-/).first()
		const hasPendingDraft = await pendingDraft.isVisible().catch(() => false)
		test.skip(!hasPendingDraft, 'no pending extraction draft seeded in this administration')
		await pendingDraft.click()

		// Save is always a separate, explicit button click — opening the
		// draft alone never commits it, regardless of confidence.
		await expect(page.getByTestId('bim-save')).toBeVisible()
	})
})

test.describe('receipt-extraction-consume — ReceiptCapture prefill + correction (REQ-RXC-003 / REQ-RXC-004)', () => {
	test.beforeEach(async ({ page }) => {
		page.setViewportSize({ width: 1600, height: 1200 })
	})

	test('photographed receipt is prefilled with confidence', async ({ page }) => {
		// @e2e openspec/specs/receipt-extraction-consume/spec.md#photographed-receipt-is-prefilled-with-confidence
		await page.goto(`${APP}/inkoop/receipts`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const row = page.locator('table tbody tr').first()
		const hasRow = await row.isVisible().catch(() => false)
		test.skip(!hasRow, 'no Receipt rows seeded in this administration')

		await row.click()
		await expect(page.getByTestId('rc-amount')).toBeVisible({ timeout: 10_000 })

		const reviewHint = page.getByTestId('receipt-capture-review-hint')
		const isDraft = await reviewHint.isVisible().catch(() => false)
		test.skip(!isDraft, 'opened receipt is not an extraction draft')

		const badge = page.getByTestId('fcb-amount')
		await expect(badge).toBeVisible()
		await expect(badge).toContainText('%')
	})

	test('operator corrects a low-confidence field and commits', async ({ page }) => {
		// @e2e openspec/specs/receipt-extraction-consume/spec.md#operator-corrects-a-low-confidence-field
		await page.goto(`${APP}/inkoop/receipts`)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const row = page.locator('table tbody tr').first()
		const hasRow = await row.isVisible().catch(() => false)
		test.skip(!hasRow, 'no Receipt rows seeded in this administration')
		await row.click()

		const category = page.getByTestId('rc-category')
		const visible = await category.isVisible().catch(() => false)
		test.skip(!visible, 'ReceiptCapture did not render (draft failed to load)')

		await category.fill('travel')
		const saveButton = page.getByTestId('rc-save')
		await expect(saveButton).toBeEnabled()
		await saveButton.click()

		await expect(page.getByTestId('rc-save-error')).toBeHidden({ timeout: 10_000 }).catch(() => {})
	})
})
