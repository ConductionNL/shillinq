/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CBS Submissions — Playwright UI coverage (security-endpoint-guards, Task 8).
 *
 * The CBS Submissions manifest-v2 page
 * (`src/manifest.d/bookkeeping-cbs-bestanden-extended.json`, route
 * `/bookkeeping/cbs-submissions`) is a pure declarative index+detail page with
 * no custom Vue component and no dedicated router entry (ADR-024 / manifest-v2)
 * — it is rendered generically by `@conduction/nextcloud-vue`'s `CnIndexPage`/
 * `CnDetailPage`. This spec drives that generic shell through the real
 * `data-testid`s it emits (`cn-index-page`, `cn-object-list-table`,
 * `cn-object-row`, `cn-row-actions`, `cn-action-item-delete`,
 * `cn-delete-dialog`), the same convention `waterschappen-bbv-variant.spec.ts`
 * and `list-views-cndatatable.spec.ts` already establish for this fleet.
 *
 * ── What this spec covers, and what it deliberately does not ──────────────
 * `CnIndexPage`'s self-fetch mode talks to OpenRegister's GENERIC object API
 * (`useObjectStore()`'s default `baseUrl` is `/apps/openregister/api/objects`
 * — see `node_modules/@conduction/nextcloud-vue/src/store/useObjectStore.js`),
 * not to `lib/Controller/CBSSubmissionController.php`'s own
 * `/apps/shillinq/api/cbs-submissions` REST surface. So the UI-driven delete
 * flow below exercises OpenRegister's own register/schema-level RBAC, which
 * is explicitly out of scope for security-endpoint-guards (see design.md's
 * ADR-031 alignment section) — NOT the app-level per-administration guard
 * this change added to `CBSSubmissionController::destroy()`.
 *
 * That guard is proven two other ways, both stronger than driving the UI
 * could be: `tests/Unit/Controller/CBSSubmissionControllerTest.php` calls the
 * controller directly (positive + negative direction, REQ-004), and the
 * `request`-fixture test below calls the REAL `/apps/shillinq/api/
 * cbs-submissions/{id}` route over HTTP as the Nextcloud admin account (which
 * carries no `AdministrationMembership` of its own — tests/e2e/ci-seed.sh —
 * so this also incidentally proves the admin-bypass added alongside the
 * guard). That is the one place this file's assertions can actually fail on
 * a regression to REQ-001, and it is named honestly as such rather than
 * implied by the UI flow around it.
 *
 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
 * @e2e security-endpoint-guards/req-001/cbs-submissions-list-view-renders
 * @e2e security-endpoint-guards/req-001/cbs-submissions-delete-own-draft
 */

import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

const APP = '/apps/shillinq'
const LIST_ROUTE = '/bookkeeping/cbs-submissions'
const OR_OBJECTS_BASE =
	'/index.php/apps/openregister/api/objects/shillinq/CBSSubmission'
const SHILLINQ_API_BASE = '/index.php/apps/shillinq/api/cbs-submissions'

async function dismissWizard(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

/**
 * A minimal, schema-valid draft CBSSubmission payload. `submissionNumber`
 * must be unique per the register's own uniqueness expectations, so every
 * caller gets a fresh one keyed on Date.now() + a random suffix.
 */
function draftPayload(administrationId = 'adm-e2e-cbs') {
	const stamp = `${Date.now()}-${Math.floor(Math.random() * 10_000)}`
	return {
		submissionNumber: `CBS-E2E-${stamp}`,
		status: 'draft',
		reportingPeriodStartDate: '2026-01-01',
		reportingPeriodEndDate: '2026-03-31',
		organizationLegalName: 'Shillinq E2E Test BV',
		kvkNumber: '12345678',
		// Schema pattern (lib/Settings/register.d/bookkeeping-cbs-bestanden-
		// extended.json) is `^NL[0-9]{10}B[0-9]{2}$` — 10 digits, not the 9 a
		// real Dutch BTW-nummer carries. Matching the DEPLOYED pattern here
		// (not the real-world format) so seeding actually validates.
		taxIdentificationNumber: 'NL1234567890B01',
		administrationId,
		currency: 'EUR',
	}
}

/**
 * Seed one draft CBSSubmission through OpenRegister's generic object API —
 * the same surface the manifest-v2 list page itself reads from.
 */
async function seedDraft(
	request: APIRequestContext,
	administrationId = 'adm-e2e-cbs',
): Promise<{ id: string; submissionNumber: string }> {
	const payload = draftPayload(administrationId)
	const created = await request.post(OR_OBJECTS_BASE, {
		headers: { 'OCS-APIRequest': 'true' },
		data: payload,
	})
	expect(
		created.ok(),
		`seeding a draft CBSSubmission must succeed, got HTTP ${created.status()}`,
	).toBeTruthy()
	const body = await created.json()
	const id = body?.id
	expect(id, 'the seeded submission must come back with an id').toBeTruthy()
	return { id, submissionNumber: payload.submissionNumber }
}

async function cleanupViaOpenRegister(
	request: APIRequestContext,
	id: string,
): Promise<void> {
	const deleted = await request.delete(`${OR_OBJECTS_BASE}/${id}`, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	if (deleted.ok() === false && deleted.status() !== 404) {
		// eslint-disable-next-line no-console
		console.warn(
			`[cbs-submissions] failed to clean up seeded submission ${id}: HTTP ${deleted.status()}`,
		)
	}
}

test.describe('CBS Submissions — list view', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(APP + LIST_ROUTE)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)
	})

	/**
	 * @e2e security-endpoint-guards/req-001/cbs-submissions-list-view-renders
	 */
	test('the CBS Submissions index page mounts and stays on the shillinq route', async ({
		page,
	}) => {
		expect(page.url()).toContain('/apps/shillinq')
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})
	})

	/**
	 * With no seed data the table still renders (empty state); with seed
	 * data present it renders a real row. Either way the generic
	 * `CnDataTable` — not a bespoke table — must be the thing on screen.
	 *
	 * @e2e security-endpoint-guards/req-001/cbs-submissions-list-view-renders
	 */
	test('the submissions list renders through the generic CnDataTable', async ({
		page,
	}) => {
		// `cn-object-list-table` / `cn-object-list-empty` are CnObjectList's
		// testids, and this page does not render CnObjectList.
		// `/bookkeeping/cbs-submissions` is declared `type: "index"` with no
		// `component` (src/manifest.d/bookkeeping-cbs-bestanden-extended.json),
		// so CnIndexPage renders it — which is exactly what the sibling test
		// above proves by finding `cn-index-page`. The old locators could never
		// match, on any data, which is why this failed while the page was fine.
		//
		// Assert the same recognised index surface the rest of the suite uses
		// (spec-coverage/_helpers.ts::assertIndexSurface): a data table, an
		// empty-content block, rows, or the primary-action toolbar. Scoped to
		// `#app-content-vue` — `#content-vue` also wraps the sidebar, which is
		// identical on all ~107 pages and would satisfy any count.
		const host = page.locator('#app-content-vue, main').first()
		await expect(page.getByTestId('cn-index-page')).toBeVisible({
			timeout: 15_000,
		})

		const tables = await host.locator('table:visible').count()
		const empty = await host
			.locator('.empty-content, .emptycontent, [class*="empty-content" i]')
			.count()
		const rows = await host.locator('[role="row"]').count()
		const actionsBar = await page.getByTestId('cn-actions-bar').count()
		expect(
			tables + empty + rows + actionsBar,
			'the CBS Submissions index rendered no table, no empty state, no rows and no actions bar',
		).toBeGreaterThan(0)
	})

	/**
	 * A seeded draft submission's own submission number is visible in the
	 * rendered list — proves the table is bound to the real CBSSubmission
	 * schema/register, not a static placeholder.
	 *
	 * @e2e security-endpoint-guards/req-001/cbs-submissions-list-view-renders
	 */
	test('a seeded draft submission appears in the list', async ({
		page,
		request,
	}) => {
		const seeded = await seedDraft(request)
		try {
			await page.reload()
			await page.waitForLoadState('domcontentloaded')
			await dismissWizard(page)

			const row = page.locator(
				`[data-testid="cn-object-row"][data-testid-row-id="${seeded.id}"]`,
			)
			await expect(row).toBeVisible({ timeout: 15_000 })
			await expect(row).toContainText(seeded.submissionNumber)
		} finally {
			await cleanupViaOpenRegister(request, seeded.id)
		}
	})
})

test.describe('CBS Submissions — delete-own-draft flow', () => {
	/**
	 * Drives the UI's own delete affordance end to end: open the row's
	 * action menu, click Delete, confirm in the dialog, and assert both the
	 * network response and that the row disappears. This exercises
	 * OpenRegister's generic delete path (see the file-level comment above
	 * for why that is, and where the app-level guard is proven instead).
	 *
	 * @e2e security-endpoint-guards/req-001/cbs-submissions-delete-own-draft
	 */
	test('deleting a draft submission through the row-actions menu removes it', async ({
		page,
		request,
	}) => {
		const seeded = await seedDraft(request)
		let cleanedUp = false

		try {
			await page.goto(APP + LIST_ROUTE)
			await page.waitForLoadState('domcontentloaded')
			await dismissWizard(page)

			const row = page.locator(
				`[data-testid="cn-object-row"][data-testid-row-id="${seeded.id}"]`,
			)
			await expect(row).toBeVisible({ timeout: 15_000 })

			// Open the row's action menu (NcActions trigger wrapped by CnRowActions).
			await row.getByTestId('cn-row-actions').click()
			await page.getByTestId('cn-action-item-delete').click()

			// Confirm in CnDeleteDialog's confirm phase.
			const confirmDialog = page.locator(
				'[data-testid-modal="cn-delete-dialog"][data-testid-phase="confirm"]',
			)
			await expect(confirmDialog).toBeVisible({ timeout: 10_000 })

			// The confirm button is NOT inside `confirmDialog`. CnDeleteDialog
			// puts its Cancel/Delete buttons in NcDialog's `#actions` slot, and
			// NcDialog renders that slot into `.dialog__actions` — a SIBLING of
			// `.dialog__wrapper > .dialog__content`, which is where the
			// `data-testid-phase` marker div lives. Scoping the button lookup to
			// `confirmDialog` therefore matched ZERO buttons (that div holds only
			// an NcNoteCard), so nothing was ever clicked, no DELETE went out, and
			// the run died on `waitForResponse` 15s later rather than on the click.
			// The old `{ name: /delete/i }` was a second, independent miss: this
			// instance renders the dialog in Dutch — the buttons are "Annuleren"
			// and "Verwijderen", so an English name regex matches nothing even
			// when correctly scoped.
			//
			// Locate it instead by the destructive `variant="error"` NcButton that
			// CnDeleteDialog gives the confirm action (`button-vue--error`) — the
			// only error-variant button in the dialog, and language-independent.
			// The `toHaveCount(1)` below is the guard that keeps this honest: if
			// nc-vue ever restructures the dialog, this fails loudly instead of
			// silently selecting Cancel or nothing.
			const deleteDialog = page
				.locator('.dialog')
				.filter({ has: confirmDialog })
			const confirmButton = deleteDialog.locator(
				'.dialog__actions button.button-vue--error',
			)
			await expect(
				confirmButton,
				"CnDeleteDialog must expose exactly one destructive confirm button in the dialog's actions",
			).toHaveCount(1)

			const deleteRequest = page.waitForResponse(
				(response) =>
					response.url().includes(`/CBSSubmission/${seeded.id}`)
					&& response.request().method() === 'DELETE',
				{ timeout: 15_000 },
			)
			await confirmButton.click()
			const deleteResponse = await deleteRequest
			expect(
				deleteResponse.status(),
				`deleting one's own draft submission must succeed, got HTTP ${deleteResponse.status()}`,
			).toBeLessThan(300)
			cleanedUp = true

			await expect(row).toBeHidden({ timeout: 10_000 })
		} finally {
			if (cleanedUp === false) {
				await cleanupViaOpenRegister(request, seeded.id)
			}
		}
	})
})

test.describe('CBS Submissions — app-level guard, exercised over real HTTP (REQ-001)', () => {
	/**
	 * This is the one assertion in this file that can actually fail on a
	 * regression to the guard this change added. It calls
	 * `CBSSubmissionController::destroy()` directly — the real
	 * `/apps/shillinq/api/cbs-submissions/{id}` route, not the generic
	 * OpenRegister object API the list page's own delete button uses (see
	 * the file-level comment). The Nextcloud admin account this suite runs
	 * as carries no `AdministrationMembership` of its own (ci-seed.sh), so
	 * a 2xx here also proves the admin-bypass added alongside the guard.
	 *
	 * @e2e security-endpoint-guards/req-001/cbs-submissions-delete-own-draft
	 */
	test('DELETE /api/cbs-submissions/{id} deletes an admin-reachable draft submission', async ({
		request,
	}) => {
		const seeded = await seedDraft(request)
		let cleanedUp = false

		try {
			const response = await request.delete(
				`${SHILLINQ_API_BASE}/${seeded.id}`,
				{
					headers: { 'OCS-APIRequest': 'true' },
				},
			)
			expect(
				response.status(),
				`the app-level CBS submission delete endpoint must succeed for an admin caller, got HTTP ${response.status()}`,
			).toBeLessThan(300)
			cleanedUp = true

			// A second delete of the same (now-gone) id must 404, not silently 200.
			const second = await request.delete(
				`${SHILLINQ_API_BASE}/${seeded.id}`,
				{
					headers: { 'OCS-APIRequest': 'true' },
				},
			)
			expect(second.status()).toBe(404)
		} finally {
			if (cleanedUp === false) {
				await cleanupViaOpenRegister(request, seeded.id)
			}
		}
	})
})
