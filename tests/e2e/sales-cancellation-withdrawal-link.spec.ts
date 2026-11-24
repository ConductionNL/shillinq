/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e: the withdrawal link on the booking widget's confirmation
 * step, booked from a page on another origin (sales-cancellation task 3.4,
 * REQ-SCX-004).
 *
 * The flow is the live check the task names:
 *
 *   1. the suite's administrator mints a widget key for the e2e business
 *      `e2e-withdrawal-link` under the first administration with a bookable
 *      service (rotating it when an earlier run left one), allows the partner
 *      origin `http://partner.e2e.test` on it, and sets the guest page of the
 *      withdraw action (`withdrawal_link_page_url`) when the instance has
 *      none;
 *   2. a partner page on that origin (served by `page.route`, so no second
 *      server is needed) loads `widget.js` from the instance and mounts the
 *      widget with the key, as a partner site does (REQ-WSW-004);
 *   3. the customer picks the service, the first open slot in the next two
 *      weeks, fills in name and email and confirms;
 *   4. the confirmation step says until which day the booking can be
 *      withdrawn without a fee and links "Withdraw from this booking" to the
 *      signed https guest page link.
 *
 * The test writes one appointment and one widget key for its own e2e
 * business, and restores the guest page setting it changed. It skips with a
 * stated reason when the instance has no administration with a bookable,
 * non-exempt service or no open slot in the next two weeks.
 *
 * Written, runs in nightly; not run here (it needs a live instance).
 *
 * @spec openspec/changes/sales-cancellation/specs/portal-contribution/spec.md
 * @spec openspec/changes/sales-cancellation/tasks.md#task-3.4
 */

import type { APIRequestContext } from '@playwright/test'

import { expect, test } from '@playwright/test'

const APP = '/index.php/apps/shillinq'
const ADMIN_KEYS = APP + '/api/widget/admin/keys'
const BUSINESS_ID = 'e2e-withdrawal-link'
const PARTNER_ORIGIN = 'http://partner.e2e.test'
const GUEST_PAGE = 'https://portal.e2e.test/withdraw'
const CONFIG_URL = '/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/shillinq/withdrawal_link_page_url'
const OCS = { 'OCS-APIRequest': 'true' }
const DAYS_AHEAD = 14

type Bookable = { administrationId: string, apiKey: string, serviceId: string, date: string }

/**
 * Mint (or rotate) the e2e business's widget key under one administration.
 *
 * @param request          The admin's request context.
 * @param administrationId The administration the key books into.
 * @return The plaintext key, or '' when the instance refused both.
 */
async function widgetKey(request: APIRequestContext, administrationId: string): Promise<string> {
	const created = await request.post(`${ADMIN_KEYS}/create`, {
		headers: OCS,
		data: { businessId: BUSINESS_ID, administrationId },
	})
	if (created.status() === 200) {
		return String((await created.json())?.apiKey ?? '')
	}

	const rotated = await request.post(`${ADMIN_KEYS}/rotate`, { headers: OCS, data: { businessId: BUSINESS_ID } })
	if (rotated.status() !== 200) {
		return ''
	}

	return String((await rotated.json())?.apiKey ?? '')
}

/**
 * The first administration with a widget service that has an open slot in the next two weeks.
 *
 * @param request The admin's request context.
 * @return The administration, key, service and date, or a reason why there is none.
 */
async function findBookable(request: APIRequestContext): Promise<Bookable | string> {
	const context = await request.get(`${APP}/api/administrations/context`, { headers: OCS })
	if (context.status() !== 200) {
		return `the administration context answered ${context.status()}`
	}

	const body = await context.json()
	const ids: string[] = (body?.administrations ?? [])
		.map((a: Record<string, unknown>) => String(a?.administrationId ?? a?.id ?? ''))
		.filter((id: string) => id !== '')
	if (ids.length === 0) {
		return 'the signed-in user has no administration'
	}

	for (const administrationId of ids) {
		const apiKey = await widgetKey(request, administrationId)
		if (apiKey === '') {
			continue
		}

		const auth = { Authorization: `Bearer ${apiKey}` }
		const services = await request.get(`${APP}/api/widget/services?businessId=${BUSINESS_ID}`, { headers: auth })
		if (services.status() !== 200) {
			continue
		}

		const list = ((await services.json())?.services ?? [])
			.filter((s: Record<string, unknown>) => s?.withdrawalExempt !== true)
		for (const service of list) {
			const serviceId = String(service?.serviceId ?? '')
			for (let d = 1; d <= DAYS_AHEAD && serviceId !== ''; d++) {
				const date = new Date(Date.now() + d * 86400000).toISOString().slice(0, 10)
				const slots = await request.get(
					`${APP}/api/widget/slots?businessId=${BUSINESS_ID}&serviceId=${encodeURIComponent(serviceId)}&resourceId=res-001&date=${date}`,
					{ headers: auth },
				)
				if (slots.status() === 200 && ((await slots.json())?.slots ?? []).length > 0) {
					return { administrationId, apiKey, serviceId, date }
				}
			}
		}
	}

	return `no administration has a bookable service with an open slot in the next ${DAYS_AHEAD} days`
}

test.describe('the withdrawal link on the widget confirmation step', () => {
	test('a booking made from another website shows the last withdrawal day and the signed link @spec REQ-SCX-004', async ({ page, request, baseURL }) => {
		const bookable = await findBookable(request)
		test.skip(typeof bookable === 'string', String(bookable))
		const { apiKey, serviceId, date } = bookable as Bookable

		const origins = await request.post(`${ADMIN_KEYS}/origins`, {
			headers: OCS,
			data: { businessId: BUSINESS_ID, allowedOrigins: [PARTNER_ORIGIN] },
		})
		expect(origins.status(), 'the partner origin is allowed on the e2e key').toBe(200)

		// The guest page: keep an https page an operator configured, set the e2e one otherwise.
		const before = await request.get(CONFIG_URL, { headers: OCS })
		const previous = before.status() === 200 ? String((await before.json())?.ocs?.data?.data ?? '') : ''
		const setPage = !/^https:\/\//i.test(previous)
		if (setPage) {
			const set = await request.post(CONFIG_URL, { headers: OCS, form: { value: GUEST_PAGE } })
			expect(set.status(), 'the guest page setting is stored').toBe(200)
		}

		try {
			const instance = new URL(baseURL ?? 'http://localhost:8080').origin
			await page.route(`${PARTNER_ORIGIN}/**`, (route) => route.fulfill({
				status: 200,
				contentType: 'text/html',
				body: `<!doctype html><html><head><meta charset="utf-8"><title>Partner</title></head><body>
<div id="booking-widget"></div>
<script src="${instance}/apps/shillinq/js/widget.js"></script>
<script>
BookingWidget.init({
	businessId: ${JSON.stringify(BUSINESS_ID)},
	apiBase: ${JSON.stringify(instance + APP)},
	apiKey: ${JSON.stringify(apiKey)},
	containerId: 'booking-widget',
	lang: 'en',
})
</script></body></html>`,
			}))
			await page.goto(`${PARTNER_ORIGIN}/book`)

			const widget = page.locator('#booking-widget')
			await widget.getByLabel('Select a service').selectOption(serviceId)
			await widget.getByRole('button', { name: 'Next' }).click()

			await widget.getByLabel('Select a date').fill(date)
			await widget.getByLabel('Select a date').dispatchEvent('change')
			await widget.getByRole('radio').first().click()
			await widget.getByRole('button', { name: 'Next' }).click()

			await widget.getByLabel('Your name').fill('E2E Withdrawal')
			await widget.getByLabel('Email address').fill('withdrawal.e2e@example.org')
			await widget.getByRole('button', { name: 'Next' }).click()
			await widget.getByRole('button', { name: 'Confirm booking' }).click()

			const status = widget.getByRole('status')
			await expect(status).toContainText('Booking confirmed')
			await expect(status).toContainText('You can withdraw from this booking without a fee until')
			await expect(status.locator('time')).toHaveAttribute('datetime', /^\d{4}-\d{2}-\d{2}$/)

			const link = status.getByRole('link', { name: 'Withdraw from this booking' })
			await expect(link).toHaveAttribute('href', /^https:\/\//)
			const href = String(await link.getAttribute('href'))
			const expectedPage = setPage ? GUEST_PAGE : previous
			expect(href.startsWith(expectedPage.replace(/\/$/, '')), 'the link opens the configured guest page').toBe(true)
		} finally {
			if (setPage) {
				await request.post(CONFIG_URL, { headers: OCS, form: { value: previous } })
			}
		}
	})
})
