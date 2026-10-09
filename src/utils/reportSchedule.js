/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * reporting-data-delivery REQ-RDD-001: the report schedule the report dialog
 * writes. nextRunAt is the browser twin of
 * lib/Reporting/Schedule/ReportScheduleCalendar::nextRunAfter, so a new
 * schedule shows its first run before the job has seen it; the same cases
 * run in both test suites.
 */

/**
 * The first run strictly after a moment, at 00:00 UTC.
 *
 * @param {string} frequency weekly, monthly or quarterly.
 * @param {number} runDay Day of the month (1 to 28) or ISO weekday (1 to 7).
 * @param {Date} after The moment the run must follow.
 * @return {string} The run as 2026-10-05T00:00:00+00:00.
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */
export function nextRunAt(frequency, runDay, after) {
	let candidate
	if (frequency === 'weekly') {
		const weekday = Math.max(1, Math.min(7, Number(runDay) || 1))
		const isoDay = after.getUTCDay() === 0 ? 7 : after.getUTCDay()
		candidate = new Date(
			Date.UTC(
				after.getUTCFullYear(),
				after.getUTCMonth(),
				after.getUTCDate() - (isoDay - weekday),
			),
		)
		while (candidate <= after) {
			candidate = new Date(
				Date.UTC(
					candidate.getUTCFullYear(),
					candidate.getUTCMonth(),
					candidate.getUTCDate() + 7,
				),
			)
		}
	} else {
		const day = Math.max(1, Math.min(28, Number(runDay) || 1))
		const step = frequency === 'quarterly' ? 3 : 1
		let month = after.getUTCMonth()
		if (frequency === 'quarterly') {
			month = Math.floor(month / 3) * 3
		}
		candidate = new Date(Date.UTC(after.getUTCFullYear(), month, day))
		while (candidate <= after) {
			candidate = new Date(
				Date.UTC(
					candidate.getUTCFullYear(),
					candidate.getUTCMonth() + step,
					day,
				),
			)
		}
	}
	return candidate.toISOString().slice(0, 19) + '+00:00'
}

/**
 * Turn the recipients field into user:<id> and group:<id> entries.
 *
 * A bare name is a user; group:<id> and user:<id> are kept as typed.
 *
 * @param {string} text Comma or space separated recipients.
 * @return {Array<string>} The entries, each once.
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */
export function parseRecipients(text) {
	const entries = String(text || '')
		.split(/[\s,;]+/)
		.map((token) => token.trim())
		.filter((token) => token !== '')
		.map((token) => (/^(user|group):.+/.test(token) ? token : `user:${token}`))
	return [...new Set(entries)]
}

/**
 * The ReportSchedule object the dialog saves.
 *
 * @param {object} report The catalogue report (id, label).
 * @param {object} form The dialog's fields.
 * @param {Date} now The moment of saving.
 * @return {object} The schedule.
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */
export function schedulePayload(report, form, now) {
	const runDay = Number(form.runDay) || 1
	return {
		administrationId: form.administrationId,
		name: report.label || report.id,
		reportType: report.id,
		format: form.format,
		frequency: form.frequency,
		runDay,
		periodRule: form.periodRule,
		recipients: parseRecipients(form.recipients),
		folderPath: form.folderPath || '/Shillinq/Reports',
		nextRunAt: nextRunAt(form.frequency, runDay, now),
		status: 'active',
	}
}
