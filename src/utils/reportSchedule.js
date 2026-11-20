/*
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * reporting-data-delivery REQ-RDD-001: the report schedule the report dialog
 * sends to /api/report-schedules. The server sets the owner, the status and
 * the next run, and refuses recipients the owner may not share with.
 */

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
 * The schedule fields the dialog sends.
 *
 * @param {object} report The catalogue report (id, label).
 * @param {object} form The dialog's fields.
 * @return {object} The schedule fields.
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */
export function schedulePayload(report, form) {
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
	}
}
