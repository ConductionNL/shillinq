/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of the card sections each cluster landing page hands to
 * ClusterOverview. Translation is replaced by a marker, so the record pins
 * every section and card id, route, query and the source string each label
 * is translated from, compared with
 * tests/vitest/fixtures/clusterOverviewSections.golden.json.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/changes/nav-six-clusters/specs/nav-clusters/spec.md#req-navc-002
 */

import fs from 'fs'
import path from 'path'
import { afterAll, describe, expect, it, vi } from 'vitest'
import BankingCashflowOverview from '../../src/components/banking-cashflow/BankingCashflowOverview.vue'
import BookkeepingOverview from '../../src/components/bookkeeping/BookkeepingOverview.vue'
import PurchasingOverview from '../../src/components/purchasing/PurchasingOverview.vue'
import SalesOverview from '../../src/components/sales/SalesOverview.vue'
import TaxesOverview from '../../src/components/taxes/TaxesOverview.vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => `${app}:${text}`,
}))

const GOLDEN = path.resolve(
	__dirname,
	'fixtures/clusterOverviewSections.golden.json',
)
const RECORD = process.env.SHILLINQ_GOLDEN_RECORD === '1'
const golden = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
const recorded = {}

const PAGES = {
	BankingCashflowOverview,
	BookkeepingOverview,
	PurchasingOverview,
	SalesOverview,
	TaxesOverview,
}

describe('cluster overview sections', () => {
	it.each(Object.keys(PAGES))('%s', (name) => {
		const page = PAGES[name]
		const instance = { t: page.methods.t }
		const sections = page.computed.sections.call(instance)
		recorded[name] = sections
		if (!RECORD) {
			expect(sections).toStrictEqual(golden[name])
		}
	})
})

afterAll(() => {
	if (RECORD) {
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
})
