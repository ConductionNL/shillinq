// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// platform-administration-import: the requests and the pure logic behind
// the import wizard (src/views/import/ImportWizard.vue). Every step moves
// one ImportBatch through its declared lifecycle; the server runs the
// pipeline in the transition's action (ImportBatchAction).
//
// @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER_SLUG = 'shillinq'

export const WIZARD_STEPS = [
	'upload',
	'profile',
	'mapping',
	'validation',
	'dry-run',
	'post',
]

export const SOURCE_SYSTEMS = [
	{ id: 'xaf-generic', label: 'XAF auditfile (any package)' },
	{ id: 'snelstart', label: 'SnelStart' },
	{ id: 'exact-online', label: 'Exact Online' },
	{ id: 'e-boekhouden', label: 'e-Boekhouden' },
	{ id: 'moneybird', label: 'Moneybird' },
]

const STEP_BY_STATUS = {
	draft: 'profile',
	parsing: 'profile',
	staged: 'mapping',
	mapping: 'mapping',
	validated: 'validation',
	validation_failed: 'validation',
	dry_run_complete: 'dry-run',
	posting: 'post',
	posted: 'post',
	posting_failed: 'post',
	reversed: 'post',
}

/**
 * The wizard step a batch in this status belongs on.
 *
 * @param {object|null} batch The batch, or null before one exists.
 * @return {string} A step id from WIZARD_STEPS.
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export function stepForBatch(batch) {
	if (!batch) {
		return 'upload'
	}
	return STEP_BY_STATUS[batch.status] || 'upload'
}

/**
 * The error findings of a report.
 *
 * @param {object|null} report A validationReport or postingReport.
 * @return {Array<object>} The findings with severity error.
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export function errorFindings(report) {
	return (report?.findings || []).filter(
		(finding) => finding?.severity === 'error',
	)
}

/**
 * Whether the batch may go on to the dry run: validated, and no error finding.
 *
 * @param {object|null} batch The batch.
 * @return {boolean}
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export function canRunDryRun(batch) {
	return (
		batch?.status === 'validated'
		&& errorFindings(batch?.validationReport).length === 0
	)
}

/**
 * The mapping rows that still need the administrator: no target, or not confirmed.
 *
 * @param {Array<object>} mappings The batch's ImportMapping rows.
 * @return {Array<object>}
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export function openMappings(mappings) {
	return (mappings || []).filter(
		(row) => !row?.targetAccount || row?.confirmed !== true,
	)
}

/**
 * The debit and credit totals of the dry run's opening entry, in cents-safe euros.
 *
 * @param {object|null} report The dryRunReport.
 * @return {{debit: number, credit: number, lines: number}}
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export function openingTotals(report) {
	const lines = report?.openingJournal?.lines || []
	let debit = 0
	let credit = 0
	for (const line of lines) {
		debit += Math.round(Number(line?.debit || 0) * 100)
		credit += Math.round(Number(line?.credit || 0) * 100)
	}
	return { debit: debit / 100, credit: credit / 100, lines: lines.length }
}

/**
 * The new batch the upload and profile steps describe.
 *
 * @param {object} form path, sourceSystem, migrationDate, administrationId, scope.
 * @return {object} The ImportBatch payload in state draft.
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export function batchPayload(form) {
	return {
		administrationId: form.administrationId,
		sourceSystem: form.sourceSystem,
		sourceFiles: [{ path: form.path, kind: 'xaf' }],
		migrationDate: form.migrationDate,
		scope: {
			chartOfAccounts: true,
			openingBalance: form.scope?.openingBalance !== false,
			openItems: false,
			relations: form.scope?.relations !== false,
		},
		status: 'draft',
	}
}

/**
 * Whether the upload and profile steps hold what parse needs.
 *
 * @param {object} form The wizard form.
 * @return {boolean}
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export function formComplete(form) {
	return Boolean(
		form?.path
		&& form?.sourceSystem
		&& form?.migrationDate
		&& form?.administrationId,
	)
}

/**
 * Create the batch.
 *
 * @param {object} form The wizard form.
 * @return {Promise<object>} The saved batch.
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export async function createBatch(form) {
	const { data } = await axios.post(
		generateUrl(`/apps/openregister/api/objects/${REGISTER_SLUG}/ImportBatch`),
		batchPayload(form),
	)
	return data
}

/**
 * Read the batch.
 *
 * @param {string} id The batch id.
 * @return {Promise<object>}
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export async function loadBatch(id) {
	const { data } = await axios.get(
		generateUrl(
			`/apps/openregister/api/objects/${REGISTER_SLUG}/ImportBatch/${encodeURIComponent(id)}`,
		),
	)
	return data
}

/**
 * Run a transition on the batch and read it back.
 *
 * @param {string} id The batch id.
 * @param {string} action The transition: parse, startMapping, validate, dryRun, post or reverse.
 * @return {Promise<object>} The batch after the transition.
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export async function runTransition(id, action) {
	await axios.post(
		generateUrl(
			`/apps/openregister/api/objects/${encodeURIComponent(id)}/transition`,
		),
		{ action, data: {} },
	)
	return loadBatch(id)
}

/**
 * The batch's mapping rows.
 *
 * @param {string} id The batch id.
 * @return {Promise<Array<object>>}
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export async function loadMappings(id) {
	const response = await axios.get(
		generateUrl(`/apps/openregister/api/objects/${REGISTER_SLUG}/ImportMapping`),
		{ params: { batchReference: id, _limit: 1000 } },
	)
	const rows = response.data?.results ?? response.data ?? []
	return [...rows].sort((a, b) =>
		String(a.sourceCode || '').localeCompare(String(b.sourceCode || '')),
	)
}

/**
 * Confirm every mapping row that has a target account.
 *
 * @param {Array<object>} mappings The rows.
 * @return {Promise<number>} How many rows were confirmed.
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export async function confirmSuggestions(mappings) {
	let confirmed = 0
	for (const row of mappings || []) {
		if (!row?.targetAccount || row?.confirmed === true) {
			continue
		}
		await axios.patch(
			generateUrl(
				`/apps/openregister/api/objects/${REGISTER_SLUG}/ImportMapping/${encodeURIComponent(row.id)}`,
			),
			{ confirmed: true },
		)
		confirmed++
	}
	return confirmed
}

/**
 * The message a refused request carries, or a fallback.
 *
 * @param {Error} error The axios error.
 * @param {string} fallback The fallback message.
 * @return {string}
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export function refusalMessage(error, fallback) {
	const data = error?.response?.data
	return String(data?.message || data?.error || fallback)
}

/**
 * The wizard's address for one batch.
 *
 * @param {string} id The batch id.
 * @return {string} The URL of the wizard opened on that batch.
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export function wizardUrl(id) {
	return (
		generateUrl('/apps/shillinq/import/wizard')
		+ '?batch='
		+ encodeURIComponent(id)
	)
}

/**
 * Header action on an import batch: open the wizard on it, at the step its
 * status belongs on, so a batch left at the mapping review can go on to the
 * validation and the dry run (live pass S3).
 *
 * @param {{item?: object}} scope The page scope.
 * @return {boolean} Whether the wizard was opened.
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
export function openImportWizard(scope) {
	const id =
		scope?.item?.id || window.location.pathname.split('/').pop() || ''
	if (!id) {
		return false
	}
	window.location.assign(wizardUrl(id))
	return true
}
