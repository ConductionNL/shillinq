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
		})
		const properties = fragment.components.schemas.ReportSchedule.properties
		for (const key of Object.keys(payload)) {
			expect(properties[key], key).toBeDefined()
		}
	})

	it('offers Schedule this report on the report dialog', () => {
		expect(dialog).toContain("t('shillinq', 'Schedule this report')")
		expect(dialog).toContain("from '../utils/reportSchedule.js'")
		expect(dialog).toContain("'/apps/shillinq/api/report-schedules'")
		expect(dialog).not.toContain('/api/objects/shillinq/ReportSchedule')
	})
})
