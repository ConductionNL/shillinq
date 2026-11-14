/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * Golden record of four small helpers: the CSV line splitter of the usage
 * readings import, the VAT return error sentence, the GL account suggestion
 * summary and the commitment drilldown filters.
 *
 * SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
 *
 * @spec openspec/changes/sales-usage-billing/tasks.md#task-2.1
 */

import { describe, it, vi } from 'vitest'
import { golden, outcome, seeded } from './goldenHarness.js'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
}))

const check = golden('smallHelpers')

describe('small helpers golden', () => {
	it('splitCsvLine matches the record', async () => {
		const { splitCsvLine } = await import('../../src/utils/usageBilling.js')
		const r = seeded(3)
		const alphabet = ['a', ' ', '"', ',', ';', 'b', '""', 'x y']
		for (let i = 0; i < 300; i++) {
			const line = Array.from({ length: r.int(12) }, () =>
				r.pick(alphabet),
			).join('')
			for (const separator of [',', ';', '"']) {
				check(
					`csv ${i} ${separator}`,
					outcome(() => splitCsvLine(line, separator)),
				)
			}
		}
	})

	it('prepareErrorText matches the record', async () => {
		const { prepareErrorText } = await import('../../src/utils/vatReturnApi.js')
		const errors = [null, undefined, {}, { response: null }]
		for (const status of [400, 403, 404, 409, 500, '400', undefined]) {
			for (const error of [
				'period_in_future',
				'future',
				'invalid',
				'',
				null,
				7,
			]) {
				errors.push({ response: { status, data: { error } } })
			}
			errors.push({ response: { status } })
		}
		errors.forEach((error, i) =>
			check(
				`vat ${i}`,
				outcome(() => prepareErrorText(error)),
			),
		)
	})

	it('glAccountSuggestionSummary matches the record', async () => {
		const { glAccountSuggestionSummary } =
			await import('../../src/utils/extractionConfidence.js')
		const r = seeded(9)
		for (let i = 0; i < 200; i++) {
			const suggestion =
				r.next() < 0.15
					? r.pick([null, undefined, 'x', 0, [], true])
					: {
							code: r.pick([
								'4000',
								' 4100 ',
								'',
								'  ',
								4200,
								null,
								undefined,
							]),
							label: r.pick(['Rent', '', null, undefined, 5]),
							confidence: r.pick([0.9, 0, '0.5', null, undefined]),
							rationale: r.pick(['matched', null, undefined]),
							source: r.pick(['history', '', null, undefined]),
						}
			const response =
				r.next() < 0.1 ? r.pick([null, undefined, {}]) : { suggestion }
			check(
				`gl ${i}`,
				outcome(() => glAccountSuggestionSummary(response)),
			)
		}
	})

	it('drilldownFilters matches the record', async () => {
		const { drilldownFilters } =
			await import('../../src/views/budgetLineCommitmentsHelpers.js')
		const r = seeded(13)
		const values = ['5.1', '', 0, null, undefined, 2026, 'FAC']
		for (let i = 0; i < 200; i++) {
			const row = {
				programme: r.pick(values),
				cost_centre: r.pick(values),
				financial_year: r.pick(values),
				general_ledger_account: r.pick(values),
			}
			check(
				`drill ${i}`,
				outcome(() => drilldownFilters(row)),
			)
		}
		check(
			'drill empty',
			outcome(() => drilldownFilters({})),
		)
	})
})
