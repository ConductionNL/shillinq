/*
 * SPDX-FileCopyrightText: 2026 Shillinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FINANCIAL-CORRECTNESS workflow — OSS / BTW destination-country VAT rate
 * resolution (REQ-OSS-001).
 *
 * DATA-DEPENDENT test: seeds an EuVatRate (TEDB) row with a KNOWN rate, then
 * asks the live resolver (GET /api/oss/rate) to resolve the rate for a B2C
 * sale into that country on a date inside the rate's validity window, and
 * asserts the EXACT resolved percentage. It also asserts the VAT and gross a
 * caller computes from that rate are correct (€100 net @ the resolved rate).
 *
 * Known input:
 *   country DE, category standard, validFrom 2024-01-01, ratePercentage 19.0
 *   invoice date 2026-03-15 (inside the window, no validUntil)
 *
 * Expected:
 *   appliedVatRate      = 19.0
 *   appliedRateCategory = "standard"
 *   net €100.00 @ 19%  => VAT €19.00, gross €119.00
 *
 * The classic NL domestic example (€100 @ 21% => €21 VAT / €121 gross) is the
 * same arithmetic; OSS deliberately excludes NL (domestic turnover never
 * enters the OSS pipeline), so the destination-country case (DE 19%) is used
 * here and the gross/VAT identity is asserted explicitly.
 *
 * The capability is spelled with the Dutch statutory term for the tax — `btw`,
 * not `vat` — so the canonical spec is `bookkeeping-btw-oss-eu`. The path this
 * tag used to name has never existed in any form.
 *
 * @spec openspec/specs/bookkeeping-btw-oss-eu/spec.md#REQ-OSS-001
 */

import { test, expect, request as pwRequest } from '@playwright/test'
import { UNIQUE_PREFIX, OrFixtures, money } from './_fixtures'

const APP = '/apps/shillinq'
const ADMIN_ID = `${UNIQUE_PREFIX}-adm`
const NEEDED = ['EuVatRate']

test.describe('shillinq finance — OSS/BTW VAT rate resolution (computed numbers)', () => {
	let fx: OrFixtures
	let api: import('@playwright/test').APIRequestContext

	test.beforeAll(async ({ baseURL }) => {
		api = await pwRequest.newContext({
			baseURL,
			storageState: 'tests/e2e/.auth/admin.json',
		})
		fx = new OrFixtures(api)
	})

	test.afterAll(async () => {
		await fx?.cleanup()
		await api?.dispose()
	})

	test('resolves the exact destination VAT rate and the derived VAT/gross are correct', async () => {
		// The shillinq register and its EuVatRate (TEDB) schema MUST be imported;
		// the prior import blocker is fixed, so a missing schema is now a real
		// regression.
		const missing = await fx.missingSchema(NEEDED)
		expect(
			missing,
			`shillinq register/schema not imported (missing: ${missing})`,
		).toBeNull()

		// Seed a known DE standard rate.
		await fx.create('EuVatRate', {
			administrationId: ADMIN_ID,
			countryCode: 'DE',
			rateCategory: 'standard',
			ratePercentage: 19.0,
			validFrom: '2024-01-01',
			validUntil: null,
			tedbSource: `${UNIQUE_PREFIX}-TEDB`,
		})

		const res = await api.get(
			`/index.php${APP}/api/oss/rate?country=DE&category=standard&date=2026-03-15`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(
			res.ok(),
			`oss rate endpoint HTTP ${res.status()}: ${await res.text()}`,
		).toBeTruthy()
		const body = await res.json()

		// Exact resolved rate.
		expect(money(body.appliedVatRate)).toBe(19.0)
		expect(body.appliedRateCategory).toBe('standard')

		// Derived VAT / gross from a €100.00 net B2C line.
		const net = 100.0
		const vat = money(net * (body.appliedVatRate / 100))
		const gross = money(net + vat)
		expect(vat).toBe(19.0)
		expect(gross).toBe(119.0)
	})
})
