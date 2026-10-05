// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// receivables-automatic-dunning 4.1 (REQ-RAD-008): the Next run preview, the
// last job report and switching reminders on. Shared by the Next run header
// action on Dunning runs, the Switch on reminders action on an administration
// and DunningNextRunModal.

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

/**
 * The preview and last run of the administrations the caller may see, or of
 * one administration.
 *
 * @param {string} administrationId The administration, or '' for all of them.
 * @return {Promise<Array<object>>} One entry per administration: name, enabled, lastRun, rows.
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 */
export async function fetchNextRun(administrationId) {
	const params = {}
	if (administrationId) {
		params.administrationId = administrationId
	}
	const response = await axios.get(
		generateUrl('/apps/shillinq/api/dunning/next-run'),
		{ params },
	)
	const administrations = response?.data?.administrations
	return Array.isArray(administrations) ? administrations : []
}

/**
 * Switch automatic reminders on for an administration.
 *
 * @param {string} id The Administration record id.
 * @return {Promise<object>} The saved administration.
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 */
export async function switchOnDunning(id) {
	const response = await axios.patch(
		generateUrl(
			`/apps/openregister/api/objects/${REGISTER_SLUG}/Administration/${encodeURIComponent(id)}`,
		),
		{ dunningEnabled: true },
	)
	return response?.data
}

/**
 * The name of a dunning channel.
 *
 * @param {string} channel The channel code.
 * @return {string} The translated name.
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 */
export function channelLabel(channel) {
	switch (channel) {
		case 'EMAIL':
			return t('shillinq', 'Email')
		case 'eMAILPostRegistration':
			return t('shillinq', 'Email and registered post')
		case 'REGISTERED_POST':
			return t('shillinq', 'Registered post')
		case 'COLLECTION_AGENCY_API':
			return t('shillinq', 'Collection agency')
		default:
			return String(channel || '')
	}
}

/**
 * The last run of an administration in one line.
 *
 * @param {object|null} report The stored run report, or null.
 * @return {string} The line.
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 */
export function reportLine(report) {
	if (!report) {
		return t(
			'shillinq',
			'The daily run has not run for this administration yet.',
		)
	}
	const day = String(report.ranAt || '').slice(0, 10)
	if (report.locked === true) {
		return t(
			'shillinq',
			'Last run {day}: skipped, another run was still busy.',
			{ day },
		)
	}
	return t(
		'shillinq',
		'Last run {day}: {overdue} marked overdue, {sent} sent, {manual} to send by hand, {failed} failed, {skipped} with nothing due.',
		{
			day,
			overdue: Number(report.markedOverdue || 0),
			sent: Number(report.sent || 0),
			manual: Number(report.manual || 0),
			failed: Number(report.failed || 0),
			skipped: Number(report.skipped || 0),
		},
	)
}

/**
 * The server's reason for a refusal, else the fallback.
 *
 * @param {unknown} error The axios error.
 * @param {string} fallback The text when the server gave none.
 * @return {string} The reason.
 * @spec openspec/changes/receivables-automatic-dunning/tasks.md#task-4.1
 */
export function dunningError(error, fallback) {
	const data = error?.response?.data
	return String(data?.message || data?.error || fallback)
}
