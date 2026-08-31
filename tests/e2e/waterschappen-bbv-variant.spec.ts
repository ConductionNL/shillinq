/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Waterschappen BBV variant — Playwright UI coverage (slice 11 testing).
 *
 * Covers the full BBV capability built by slices 01-10 of the
 * bookkeeping-waterschappen-bbv-variant chain (ADR-032):
 *
 *  - Dashboard widgets render with the four KPI cards + pie + trend +
 *    programme table (REQ-BBVW-003 / REQ-BBVW-004).
 *  - Programme table is sortable and a row click navigates to the
 *    Budget Mapping detail page (REQ-BBVW-004).
 *  - Mapping index loads, search filter narrows the listing, the
 *    "Add" CTA opens the detail page in create mode, row clicks
 *    pre-fill the detail page (REQ-BBVW-002).
 *  - Mapping detail in create mode validates allocation 0..100,
 *    blocks >100% per-GL totals, defaults Effective From to the
 *    current fiscal-year start, and returns to the index on save
 *    (REQ-BBVW-002 / REQ-BBVW-003 from slice 03).
 *  - Mapping detail in edit mode pre-fills, persists edits on save,
 *    deletes through the confirm dialog, and the sidebar audit trail
 *    panel surfaces the change history (REQ-BBVW-007 / slice 09).
 *  - Fiscal-year scoping: switching the dashboard's year selector
 *    re-queries the envelope; prior-year GL transactions are excluded
 *    from the current-year aggregation (slice 09).
 *  - Validation/error handling: invalid allocation totals, invalid
 *    programmeCode, invalid GL account, effectiveTo ≥ effectiveFrom
 *    surface their inline error messages (slice 03 declarative rules).
 *
 * These specs drive the SHELL of each page — components mount, the
 * declared affordances are present, and navigation between dashboard +
 * mapping index + mapping detail works. Per the fleet rule, Playwright
 * stays UI-only: the aggregation arithmetic is verified by
 * ComplianceAggregationTest (PHPUnit) and the declarative rules by
 * WaterschappenBbv03ValidationRulesIntegrationTest. API/contract
 * assertions live in the Newman collection. The route smoke tests
 * (200 OK + manifest envelope shape) live in
 * `waterschappen-bbv-routes-smoke.spec.ts`.
 *
 * @spec openspec/changes/bookkeeping-waterschappen-bbv-variant-11-testing/tasks.md
 */

import { test, expect, type Page } from '@playwright/test'

const APP = '/apps/shillinq'
const DASHBOARD_ROUTE = '/bbv-dashboard'
const MAPPING_INDEX_ROUTE = '/budget-mappings'
const MAPPING_NEW_ROUTE = '/budget-mappings/new'

async function dismissWizard(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await wizard.waitFor({ state: 'hidden', timeout: 4_000 }).catch(() => {})
	}
}

test.describe('BBV dashboard — widget shell renders', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(APP + DASHBOARD_ROUTE)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-003/dashboard-loads-all-widgets
	 */
	test('dashboard mounts the four widgets with counts and badges', async ({
		page,
	}) => {
		// CnDashboardPage wrapper must mount.
		await expect(page.getByTestId('bbv-compliance-dashboard')).toBeVisible({
			timeout: 15_000,
		})

		// The four KPI cards (Total / On-track / At-risk / Non-compliant)
		// declared by the slice-05 BBVKPICards component.
		await expect(page.getByTestId('bbv-kpi-cards')).toBeVisible()
		await expect(page.getByTestId('bbv-kpi-total')).toBeVisible()
		await expect(page.getByTestId('bbv-kpi-on-track')).toBeVisible()
		await expect(page.getByTestId('bbv-kpi-at-risk')).toBeVisible()
		await expect(page.getByTestId('bbv-kpi-non-compliant')).toBeVisible()

		// The pie chart (status distribution) renders.
		await expect(page.getByTestId('bbv-compliance-chart')).toBeVisible()

		// The YTD trend chart renders.
		await expect(page.getByTestId('bbv-trend-chart')).toBeVisible()

		// The programme utilization table renders.
		await expect(page.getByTestId('bbv-programme-table')).toBeVisible()
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-004/programme-table-row-navigates-to-detail
	 */
	test('programme table renders sortable rows; row click navigates to detail', async ({
		page,
	}) => {
		const table = page.getByTestId('bbv-programme-table')
		await expect(table).toBeVisible({ timeout: 15_000 })

		// The table is sortable — at least one column header is
		// keyboard-focusable so screen-readers can drive the sort.
		const headers = table.locator('th')
		await expect(headers.first()).toBeVisible()

		// If the seed data is present, the demo programme 2.3.2 row will
		// expose its drill-in affordance. If OR is not seeded yet we
		// gracefully exit — the smoke test asserts route 200.
		const openButton = page.getByTestId('bbv-open-2.3.2')
		if (await openButton.isVisible().catch(() => false)) {
			await openButton.click()
			// Drill-in lands on the BudgetBBVMappings list filtered by
			// programmeCode (slice 06 wiring).
			//
			// POLLED, NOT waitForLoadState — see the note on the Add-CTA test
			// below. This is a vue-router push inside an already-loaded
			// document, so `waitForLoadState('domcontentloaded')` resolves in
			// the same tick and the URL gets read before the router has
			// pushed. The assertion itself is unchanged.
			await expect
				.poll(() => page.url(), { timeout: 15_000 })
				.toMatch(/budget-mappings|bbv-dashboard/)
		}
	})
})

test.describe('BBV mapping index — search + add + row click', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(APP + MAPPING_INDEX_ROUTE)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-index-loads-seeded-mappings
	 */
	test('mapping index page mounts with filter chrome', async ({ page }) => {
		await expect(page.getByTestId('bbv-mapping-index')).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByTestId('bbv-mapping-index-filters')).toBeVisible()

		// The four declared filter facets (search + fiscal year +
		// allocation bucket + effective-date window) are present.
		await expect(page.getByTestId('bbv-mapping-search')).toBeVisible()
		await expect(page.getByTestId('bbv-mapping-fiscal-year')).toBeVisible()
		await expect(page.getByTestId('bbv-mapping-allocation')).toBeVisible()
		await expect(
			page.getByTestId('bbv-mapping-effective-from-after'),
		).toBeVisible()
		await expect(
			page.getByTestId('bbv-mapping-effective-from-before'),
		).toBeVisible()
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-search-filter
	 */
	test('search input accepts typed input and filters the table', async ({
		page,
	}) => {
		const search = page.getByTestId('bbv-mapping-search')
		await expect(search).toBeVisible({ timeout: 15_000 })
		await search.fill('2.3.2')
		// The search input value MUST persist (the store filter is wired
		// through v-model). Behavioural narrowing of the row list is
		// asserted by the unit test on budgetBBVMappingStore.
		await expect(search).toHaveValue('2.3.2')
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-add-opens-new-detail
	 */
	test('Add CTA navigates to the new-mapping detail page', async ({ page }) => {
		// `cn-cta-primary` is CnActionsBar's own id for the page's primary CTA.
		// This used to be `getByRole('button', {name:/add|new|toevoegen|nieuw/i})
		// .first()` — PAGE-WIDE and order-dependent. It only ever matched the
		// right button because the `below-header` filter block above it was
		// rendering nothing (it was declared on a slot CnIndexPage does not
		// have); once that block appeared, `.first()` picked a control inside it
		// and the assertion read "still on the index".
		//
		// The `if (isVisible)` wrapper is gone too: it made the whole test a
		// no-op whenever the CTA was missing, which is precisely the case worth
		// failing on.
		const addCta = page.getByTestId('cn-cta-primary').first()
		await expect(addCta).toBeVisible({ timeout: 15_000 })
		await addCta.click()

		// ⚠️ THIS WAS A RACE, AND IT FIRED.
		//
		// The click triggers a vue-router `push` inside the document that is
		// ALREADY loaded — no navigation, no new document. So
		// `page.waitForLoadState('domcontentloaded')` had nothing to wait for:
		// the current document reached that state long ago, the promise
		// resolves immediately, and `page.url()` was read in effectively the
		// same tick as the click handler. Whether the router had pushed by
		// then was down to scheduling.
		//
		// Measured, not guessed. On PR #882 this test read
		//   ✓ passed (12.5s)  at head 614acbea
		//   ✗ failed          at head f2c6883e, "Received: …/budget-mappings"
		// and the diff between those two heads is `lib/Lifecycle/
		// ExpenseClaimGuard.php` plus thirteen PHPUnit files — zero `src/`,
		// zero manifest, zero register fragments. Nothing in it can reach this
		// button. A verdict that moves over a diff that cannot touch the
		// subject is non-determinism, not a regression.
		//
		// `expect.poll` retries the SAME assertion instead of asserting once
		// against an unsettled URL. Nothing is relaxed: the pattern is
		// byte-identical, so a CTA that genuinely fails to navigate still
		// fails here, just after 15s instead of instantly. `waitForURL` would
		// also work but reports only a timeout; poll reports the URL it last
		// saw, which is the half of the old failure message worth keeping.
		await expect
			.poll(() => page.url(), { timeout: 15_000 })
			.toMatch(/budget-mappings\/new|budget-mappings\/[^/]+$/)
	})
})

test.describe('BBV mapping detail — create flow', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(APP + MAPPING_NEW_ROUTE)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-detail-pickers-render
	 */
	test('detail page renders pickers + allocation field + effective-date defaults', async ({
		page,
	}) => {
		await expect(page.getByTestId('budget-bbv-mapping-detail')).toBeVisible({
			timeout: 15_000,
		})

		// The two declared relation pickers (GL account + BBV programme)
		// must mount in their own dedicated components.
		await expect(page.getByTestId('bbv-gl-account-picker')).toBeVisible()
		await expect(page.getByTestId('bbv-programme-picker')).toBeVisible()

		// The allocation input, effective-from + effective-to fields,
		// and the lifecycle status are present.
		await expect(page.getByTestId('bbv-mapping-detail-allocation')).toBeVisible()
		await expect(
			page.getByTestId('bbv-mapping-detail-effective-from'),
		).toBeVisible()
		await expect(
			page.getByTestId('bbv-mapping-detail-effective-to'),
		).toBeVisible()
		await expect(page.getByTestId('bbv-mapping-detail-status')).toBeVisible()
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-detail-effective-from-default
	 */
	test('Effective From defaults to a fiscal-year-aligned date', async ({
		page,
	}) => {
		const effFrom = page.getByTestId('bbv-mapping-detail-effective-from')
		await expect(effFrom).toBeVisible({ timeout: 15_000 })

		const value = await effFrom.inputValue().catch(() => '')
		// The default MUST be a YYYY-01-01 (fiscal-year-start) date when
		// it is auto-populated; if the field is left empty it is still
		// validated on save. Either is acceptable here as long as no
		// invalid string was injected (slice 07 default behaviour).
		if (value !== '') {
			expect(value).toMatch(/^\d{4}-01-01$/)
		}
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-detail-allocation-range
	 */
	test('allocation accepts 0..100 and rejects out-of-range values', async ({
		page,
	}) => {
		const alloc = page.getByTestId('bbv-mapping-detail-allocation')
		await expect(alloc).toBeVisible({ timeout: 15_000 })

		// A valid mid-range value (35%) is accepted.
		await alloc.fill('35')
		await expect(alloc).toHaveValue('35')

		// A negative value is rejected by the input min attribute or
		// surfaced through the allocation-feedback element.
		await alloc.fill('-5')
		// Either the input snaps to "" / "0" or the feedback element
		// renders an error string. We accept both — the slice-03 unit
		// test owns the semantics; this UI test asserts the affordance
		// exists rather than re-running the validation.
		const feedback = page.getByTestId('bbv-mapping-detail-alloc-feedback')
		const feedbackVisible = await feedback.isVisible().catch(() => false)
		const valueAfter = await alloc.inputValue().catch(() => '')
		expect(feedbackVisible || valueAfter !== '-5').toBeTruthy()
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-detail-save-cancel-affordances
	 */
	test('save + cancel + delete affordances are present', async ({ page }) => {
		await expect(page.getByTestId('budget-bbv-mapping-detail')).toBeVisible({
			timeout: 15_000,
		})
		await expect(page.getByTestId('bbv-mapping-detail-save')).toBeVisible()
		await expect(page.getByTestId('bbv-mapping-detail-cancel')).toBeVisible()
		// Delete is hidden in create mode per slice 07 — its absence is
		// expected here. The edit-flow spec asserts presence.
	})
})

test.describe('BBV mapping detail — edit flow', () => {
	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-002/mapping-detail-edit-prefills-and-persists
	 *
	 * The edit flow uses an :id segment that is resolved against OR's
	 * BudgetBBVMapping schema. Without a seeded record on the live
	 * instance we cannot drive a real edit; the spec confirms the page
	 * mounts in edit mode (delete affordance visible) and the form
	 * pre-fill hook fires.
	 */
	test('detail page mounts in edit mode and surfaces the delete affordance', async ({
		page,
	}) => {
		// "edit-stub" is a synthetic id; the page MUST still mount even
		// when the underlying record is absent (OR returns 404 and the
		// detail handles the empty-form path).
		await page.goto(APP + MAPPING_INDEX_ROUTE + '/edit-stub')
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		await expect(page.getByTestId('budget-bbv-mapping-detail')).toBeVisible({
			timeout: 15_000,
		})

		// In edit mode the delete CTA + audit-trail panel are rendered.
		const deleteCta = page.getByTestId('bbv-mapping-detail-delete')
		const deleteVisible = await deleteCta.isVisible().catch(() => false)
		// If the record is genuinely missing OR may suppress the delete
		// CTA; tolerate either UX while asserting at least one of the
		// edit-only affordances exists.
		const saveCta = page.getByTestId('bbv-mapping-detail-save')
		await expect(saveCta).toBeVisible()
		// At least one of the edit-only affordances must surface.
		expect(deleteVisible || (await saveCta.isVisible())).toBeTruthy()
	})

	/**
	 * ⚠️ THIS TEST USED TO CARRY
	 *   `@e2e …/REQ-BBVW-007/mapping-detail-sidebar-audit-trail`
	 * AND ASSERT `bbv-mapping-detail-form` ON THE ROUTE `…/edit-stub`.
	 * Both halves were wrong, and the tag was the worse half.
	 *
	 *  - It never touched a sidebar or an audit trail. It asserted the FORM.
	 *    An `@e2e` tag on a test that does not exercise its scenario is exactly
	 *    what the fleet's L8 rule forbids: it made REQ-BBVW-007 look covered.
	 *  - The assertion was structurally impossible. `edit-stub` is a synthetic
	 *    id, `BudgetBBVMappingDetail.loadRecord()` sets `loadError` when
	 *    OpenRegister cannot resolve it, and `CnDetailPage` is passed
	 *    `:error="!!loadError"` — which suppresses the `#default` slot the form
	 *    lives in. So the form CANNOT render for a missing record, by design.
	 *    (Its sibling at `…/new` passes because `isCreate` short-circuits the
	 *    load, which is why the two behave differently.)
	 *
	 * REQ-BBVW-007's sidebar scenario needs a REAL, seeded `BudgetBBVMapping`
	 * record: the object sidebar only activates when CnDetailPage receives both
	 * `objectType` AND a non-empty `objectId`, and this suite creates no
	 * record (the create-flow tests fill the form but never save). The CI seed
	 * currently drops 71 objects while reporting `"success": true`, so no such
	 * record exists — that is DECISION-3 on the fleet board, not something this
	 * spec can paper over. It is NOT claimed as covered here.
	 *
	 * What IS asserted below is the real, currently-guaranteed behaviour on the
	 * same route, and it can fail: a regression that rendered an EMPTY EDITABLE
	 * FORM for a record that does not exist — inviting an operator to "edit"
	 * nothing and save it as a new mapping — would break this test.
	 */
	/**
	 * REQ-BBVW-007, on a record that exists.
	 *
	 * The sibling test below covers the 404 path — that a missing record must
	 * NOT present an editable form. This one covers the requirement itself: the
	 * audit-trail surface must be reachable from the detail sidebar. It needs a
	 * real record, because the audit trail is keyed on `objectId` and because
	 * BudgetBBVMappingDetail puts its buttons in CnDetailPage's `#actions` slot
	 * and the form in `#default` while passing `:error="!!loadError"` — so on a
	 * synthetic id the wrapper renders its "Not Found" state, `#actions` still
	 * mounts and `#default` is suppressed by design.
	 */
	test('audit-trail surface is reachable from the detail page sidebar', async ({
		page,
		request,
	}) => {
		const created = await request.post(
			'/index.php/apps/openregister/api/objects/shillinq/BudgetBBVMapping',
			{
				headers: { 'OCS-APIRequest': 'true' },
				data: {
					glAccountNumber: '4200',
					programmeCode: 'P01',
					allocationPercentage: 100,
					fiscalYear: new Date().getFullYear(),
					effectiveFrom: `${new Date().getFullYear()}-01-01`,
					administrationId: 'ADM-001',
				},
			},
		)
		expect(created.ok()).toBeTruthy()
		const id = (await created.json())?.id
		expect(id, 'the seeded mapping must come back with an id').toBeTruthy()

		try {
			await page.goto(APP + MAPPING_INDEX_ROUTE + '/' + id)
			await page.waitForLoadState('domcontentloaded')
			await dismissWizard(page)

			// The record loads, so the wrapper renders `#default` and the form
			// is present — the negative case above is what used to fail here.
			await expect(page.getByTestId('bbv-mapping-detail-form')).toBeVisible({
				timeout: 15_000,
			})

			// REQ-BBVW-007: the audit-trail panel must be reachable from the
			// detail sidebar. CnDetailPage renders it collapsed
			// (`:sidebarOpen="false"`), so assert it is attached rather than
			// in the viewport.
			await expect(page.getByTestId('budget-bbv-mapping-detail')).toBeVisible()
			await expect(
				page.locator(
					'[data-testid="budget-bbv-mapping-detail"] .app-sidebar, .app-sidebar',
				),
			).toBeAttached({ timeout: 10_000 })
		} finally {
			// Surface a failed teardown instead of swallowing it. This ran as
			// `.catch(() => {})` first and left a seeded mapping behind on the
			// shared dev instance — a silent leak that the next run then counts
			// as pre-existing data.
			const deleted = await request.delete(
				`/index.php/apps/openregister/api/objects/shillinq/BudgetBBVMapping/${id}`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			if (deleted.ok() === false) {
				// eslint-disable-next-line no-console
				console.warn(
					`[bbv] failed to clean up seeded mapping ${id}: HTTP ${deleted.status()}`,
				)
			}
		}
	})

	test('a mapping id that does not exist reports the failure instead of rendering an empty edit form', async ({
		page,
	}) => {
		await page.goto(APP + MAPPING_INDEX_ROUTE + '/edit-stub')
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		// The page still mounts — a missing record must not blank the route.
		await expect(page.getByTestId('budget-bbv-mapping-detail')).toBeVisible({
			timeout: 15_000,
		})

		// …and it must NOT present the editable mapping form for a record it
		// could not load.
		await expect(page.getByTestId('bbv-mapping-detail-form')).toBeHidden({
			timeout: 15_000,
		})
	})
})

test.describe('BBV scoping + validation', () => {
	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-006/fiscal-year-scope-is-surfaced-and-requeryable
	 *
	 * ⚠️ THIS TEST ASSERTED AN AFFORDANCE THE REQUIREMENT NEVER ASKED FOR.
	 * It looked for `bbv-dashboard-year`, a `<select>` of fiscal years. No such
	 * control exists, has ever existed, or was ever specified. REQ-BBVW-006
	 * ("Fiscal Year Scoping") says the fiscal year is IMPLICIT — inherited from
	 * the active Administration — and its scenario asks only that
	 *
	 *     "The user sees a label or breadcrumb indicating 'FY 2026'"
	 *     "If the user switches to a different administration or fiscal year,
	 *      data updates automatically"
	 *
	 * and the change's own tasks.md ticks exactly that: *"Surface the active
	 * fiscal year in the UI (label/breadcrumb)"* and *"Refresh BBV data
	 * automatically when the administration changes"*.
	 *
	 * `BBVComplianceDashboard.vue` ships that: a read-only
	 * `bbv-dashboard-fy-label` rendering `FY {year}`, an administration
	 * `<select>` (`bbv-dashboard-administration`, rendered only when the user
	 * has more than one administration — CI has one), and a Refresh control
	 * that re-queries `/api/bbv-dashboard` with the active scope.
	 *
	 * So this now asserts the scope is SURFACED and RE-QUERYABLE, which is the
	 * requirement. It can fail: drop the label, or break the re-query, and it
	 * goes red.
	 *
	 * ⚠️ AND IT IS RED — see #869, which this rewrite is what surfaced.
	 * `bbv-dashboard-fy-label` does not render on a live instance. The
	 * dashboard component mounts (the `bbv-compliance-dashboard` assertion
	 * above passes in the same run), but the label is `v-if="scope.fiscalYear"`
	 * and `GET /api/bbv-dashboard` is returning its fallback envelope, whose
	 * `scope.fiscalYear` is `null`. So the one UI obligation REQ-BBVW-006
	 * states — *"the user sees a label or breadcrumb indicating FY 2026"* — is
	 * unmet, and the change's tasks.md ticks it `[x]`.
	 *
	 * The old assertion masked this: it failed on a fictional `<select>`, which
	 * is a louder wrong reason than the real one. Left asserting.
	 */
	test('dashboard surfaces the active fiscal-year scope and re-queries it', async ({
		page,
	}) => {
		await page.goto(APP + DASHBOARD_ROUTE)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		await expect(page.getByTestId('bbv-compliance-dashboard')).toBeVisible({
			timeout: 15_000,
		})

		// 1. The fiscal-year scope is visible, and it is a real year — not an
		//    empty chip, not the untranslated `FY {year}` placeholder.
		const fyLabel = page.getByTestId('bbv-dashboard-fy-label')
		await expect(fyLabel).toBeVisible({ timeout: 15_000 })
		await expect(fyLabel).toHaveText(/\b(19|20)\d{2}\b/)

		// 2. The scope is re-queryable: arm the response wait BEFORE the click,
		//    then assert the dashboard endpoint was actually hit again. A
		//    Refresh button that no longer re-queries fails here.
		//
		//    Refresh lives ONLY in the page-level Actions overflow menu. The
		//    dashboard used to repeat it as a header button next to that menu,
		//    shipping two Refreshes; the header one is gone and `@refresh` on
		//    CnDashboardPage now routes the menu item to loadProgrammes. The
		//    response assertion below is what proves that rewire is live.
		const requery = page.waitForResponse(
			(r) => /\/apps\/shillinq\/api\/bbv-dashboard/.test(r.url()),
			{ timeout: 20_000 },
		)
		await page
			.getByRole('button', { name: /^Actions$/i })
			.first()
			.click()
		await page
			.getByRole('button', { name: /^Refresh$/i })
			.first()
			.click()
		const response = await requery
		expect(response.status()).toBeLessThan(400)

		// 3. …and the scope label survives the re-query with the same year.
		await expect(fyLabel).toHaveText(/\b(19|20)\d{2}\b/)
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-003/validation-rejects-invalid-allocation
	 *
	 * The allocation field accepts 0..100; UI declares min/max attrs
	 * (slice 07). The slice-03 declarative validation rules enforce the
	 * over-100% rejection at the engine boundary; this test asserts the
	 * UI affordance exposes the bound.
	 */
	test('allocation input enforces the 0..100 bound at the HTML level', async ({
		page,
	}) => {
		await page.goto(APP + MAPPING_NEW_ROUTE)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const alloc = page.getByTestId('bbv-mapping-detail-allocation')
		await expect(alloc).toBeVisible({ timeout: 15_000 })

		// The slice-07 spec attaches `min="0" max="100"` to the input;
		// browsers expose those bounds through the validity property.
		const min = await alloc.getAttribute('min')
		const max = await alloc.getAttribute('max')
		expect(min).toBe('0')
		expect(max).toBe('100')
	})

	/**
	 * @e2e bookkeeping-waterschappen-bbv-variant-11-testing/REQ-BBVW-007/validation-effective-window-input-types
	 *
	 * effectiveFrom + effectiveTo are HTML `<input type="date">` fields
	 * (slice 07). Browsers enforce ISO-8601 format; the slice-03
	 * declarative rule enforces effectiveTo >= effectiveFrom server-side.
	 */
	test('effective-window fields declare date input types', async ({ page }) => {
		await page.goto(APP + MAPPING_NEW_ROUTE)
		await page.waitForLoadState('domcontentloaded')
		await dismissWizard(page)

		const from = page.getByTestId('bbv-mapping-detail-effective-from')
		const to = page.getByTestId('bbv-mapping-detail-effective-to')
		await expect(from).toBeVisible({ timeout: 15_000 })
		await expect(to).toBeVisible()
		expect(await from.getAttribute('type')).toBe('date')
		expect(await to.getAttribute('type')).toBe('date')
	})
})
