/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * reporting-data-delivery REQ-RDD-001: the report dialog schedules a report
 * with the chosen report and format, and the first run it shows is the one
 * the job will make (the same cases as ReportScheduleCalendarTest).
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	nextRunAt,
	parseRecipients,
	schedulePayload,
} from '../../src/utils/reportSchedule.js'

const dialog = fs.readFileSync(
	path.resolve(__dirname, '../../src/modals/GenerateReportDialog.vue'),
	'utf8',
)
const fragment = JSON.parse(
	fs.readFileSync(
		path.resolve(
			__dirname,
			'../../lib/Settings/register.d/reporting-data-delivery.json',
		),
		'utf8',
	),
)

describe('report schedules', () => {
	it('runs a monthly schedule made on 29 September first on 5 October', () => {
		expect(nextRunAt('monthly', 5, new Date('2026-09-29T14:00:00Z'))).toBe(
			'2026-10-05T00:00:00+00:00',
		)
		expect(nextRunAt('monthly', 5, new Date('2026-10-05T01:00:00Z'))).toBe(
			'2026-11-05T00:00:00+00:00',
		)
	})

	it('runs a quarterly schedule in the first month of a quarter', () => {
		expect(nextRunAt('quarterly', 10, new Date('2026-08-15T00:00:00Z'))).toBe(
			'2026-10-10T00:00:00+00:00',
		)
		expect(nextRunAt('quarterly', 10, new Date('2026-10-10T01:00:00Z'))).toBe(
			'2027-01-10T00:00:00+00:00',
		)
	})

	it('runs a weekly schedule on its weekday', () => {
		expect(nextRunAt('weekly', 1, new Date('2026-09-29T09:00:00Z'))).toBe(
			'2026-10-05T00:00:00+00:00',
		)
		expect(nextRunAt('weekly', 4, new Date('2026-09-29T09:00:00Z'))).toBe(
			'2026-10-01T00:00:00+00:00',
		)
	})

	it('reads users and groups from the recipients field', () => {
		expect(parseRecipients('group:controllers, anna  user:bert,anna')).toEqual([
			'group:controllers',
			'user:anna',
			'user:bert',
		])
		expect(parseRecipients('')).toEqual([])
	})

	it('prefills the schedule with the dialog report and format', () => {
		const payload = schedulePayload(
			{ id: 'budget-vs-actual', label: 'Budget versus realisatie' },
			{
				administrationId: 'adm-voorbeeld',
				format: 'pdf',
				frequency: 'monthly',
				runDay: '5',
				periodRule: 'previous-period',
				recipients: 'group:controllers',
				folderPath: '/Rapportages/Maand',
			},
			new Date('2026-09-29T14:00:00Z'),
		)
		expect(payload).toEqual({
			administrationId: 'adm-voorbeeld',
			name: 'Budget versus realisatie',
			reportType: 'budget-vs-actual',
			format: 'pdf',
			frequency: 'monthly',
			runDay: 5,
			periodRule: 'previous-period',
			recipients: ['group:controllers'],
			folderPath: '/Rapportages/Maand',
			nextRunAt: '2026-10-05T00:00:00+00:00',
			status: 'active',
		})
		const properties = fragment.components.schemas.ReportSchedule.properties
		for (const key of Object.keys(payload)) {
			expect(properties[key], key).toBeDefined()
		}
	})

	it('offers Schedule this report on the report dialog', () => {
		expect(dialog).toContain("t('shillinq', 'Schedule this report')")
		expect(dialog).toContain("from '../utils/reportSchedule.js'")
	})
})
