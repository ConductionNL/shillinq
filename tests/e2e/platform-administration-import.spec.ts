/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An administrator moves an administration over from Snelstart with the
 * import wizard (platform-administration-import REQ-AIW-001, REQ-AIW-002).
 *
 * The server half (the staged auditfile, the refused second post, the posted
 * and reversed opening entry) is proven by ImportBatchStepsTest; these flows
 * prove the wizard reaches it. The fixture auditfile is put in the admin's
 * Files over WebDAV first, the way an administrator would have it there.
 *
 * @spec openspec/changes/platform-administration-import/tasks.md#task-3.2
 */

import { expect, test } from '@playwright/test'
import fs from 'fs'
import path from 'path'

const fixture = fs.readFileSync(
	path.join(__dirname, '../fixtures/import/sample-xaf-3.2.xml'),
	'utf8',
)

/**
 * Put an auditfile in the admin's Files.
 *
 * @param request The Playwright request context (authenticated).
 * @param name The file name under /Migrations.
 * @param body The file content.
 */
async function putAuditfile(page, name: string, body: string): Promise<void> {
	// WebDAV refuses a cookie-authenticated write without the page's request token.
	await page.goto('/index.php/apps/shillinq/')
	const requesttoken = await page.evaluate(() => (window as any).OC.requestToken)
	const headers = { requesttoken }
	const folder = await page.request.fetch('/remote.php/dav/files/admin/Migrations', {
		method: 'MKCOL',
		headers,
	})
	expect([201, 405], 'create the Migrations folder (405: it exists)').toContain(folder.status())
	const file = await page.request.put(`/remote.php/dav/files/admin/Migrations/${name}`, {
		headers,
		data: body,
	})
	expect([201, 204], `upload ${name}`).toContain(file.status())
}

/**
 * Choose a file in the Files picker.
 *
 * @param page The page.
 * @param name The file name under /Migrations.
 */
async function pickAuditfile(page, name: string): Promise<void> {
	await page.getByTestId('import-pick-file').click()
	const picker = page.getByRole('dialog')
	await picker.getByText('Migrations', { exact: true }).click()
	await picker.getByText(name, { exact: true }).click()
	await picker.getByRole('button', { name: /Choose/ }).click()
}

test.describe('platform-administration-import', () => {
	/**
	 * @e2e administration-import-migration::an-administrator-moves-over-from-snelstart
	 */
	test('a Snelstart auditfile is posted with its opening entry', async ({
		page,
	}) => {
		await putAuditfile(page, 'snelstart-2025.xaf', fixture)
		await page.goto('/index.php/apps/shillinq/import/wizard')

		await pickAuditfile(page, 'snelstart-2025.xaf')
		await expect(page.getByTestId('import-picked-file')).toContainText(
			'snelstart-2025.xaf',
		)
		await page.getByRole('button', { name: 'Next' }).click()

		await page.getByLabel('Package the auditfile comes from').click()
		await page.getByRole('option', { name: 'SnelStart' }).click()
		await page.getByLabel('Migration date').fill('2026-01-01')
		await page.getByTestId('import-start').click()

		await expect(page.getByTestId('import-step-mapping')).toBeVisible()
		await page.getByTestId('import-confirm-suggestions').click()
		await page.getByTestId('import-validate').click()

		await expect(page.getByTestId('import-step-validation')).toBeVisible()
		await page.getByTestId('import-dry-run').click()

		await expect(page.getByTestId('import-dry-run-debit')).toContainText(
			'30,000.00',
		)
		await expect(page.getByTestId('import-dry-run-credit')).toContainText(
			'30,000.00',
		)
		await page.getByTestId('import-post').click()

		await expect(page.getByTestId('import-posted')).toContainText(
			'posted with its opening entry',
		)
	})

	/**
	 * @e2e administration-import-migration::an-error-finding-blocks-posting
	 */
	test('an unbalanced auditfile stops at validation', async ({ page }) => {
		await putAuditfile(
			page,
			'unbalanced-2025.xaf',
			fixture.replace('<amnt>5800.00</amnt>', '<amnt>5900.00</amnt>'),
		)
		await page.goto('/index.php/apps/shillinq/import/wizard')

		await pickAuditfile(page, 'unbalanced-2025.xaf')
		await page.getByRole('button', { name: 'Next' }).click()
		await page.getByLabel('Migration date').fill('2026-01-01')
		await page.getByTestId('import-start').click()
		await page.getByTestId('import-confirm-suggestions').click()
		await page.getByTestId('import-validate').click()

		await expect(page.getByTestId('import-findings')).toContainText(
			'Opening balance is not balanced.',
		)
		await expect(page.getByTestId('import-validation-failed')).toBeVisible()
		await expect(page.getByTestId('import-dry-run')).toBeDisabled()
	})
})
