// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// public-sector-council-and-auditor-statements 3.2 (REQ-ENSIA-012): the
// header actions on an ENSIA cycle that download its submission XML and its
// college statement from EnsiaCycleController.

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * The file name the server gave, else the fallback.
 *
 * @param {object} headers The response headers.
 * @param {string} fallback The fallback name.
 * @return {string}
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
export function ensiaFileName(headers, fallback) {
	const match = String(headers?.['content-disposition'] || '').match(/filename="?([^";]+)"?/)
	return match ? match[1] : fallback
}

/**
 * Save a blob under a name.
 *
 * @param {Blob} blob The file.
 * @param {string} fileName Its name.
 */
function saveBlob(blob, fileName) {
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = fileName
	document.body.appendChild(link)
	link.click()
	link.remove()
	URL.revokeObjectURL(url)
}

/**
 * Download one stored file of the cycle on the page.
 *
 * @param {{item?: object}} scope The page scope.
 * @param {{path: string, fallback: string, missing: string, failed: string}} file What to download.
 * @return {Promise<string|null>} The file name, or null when it failed.
 */
async function downloadCycleFile(scope, file) {
	const id = String(scope?.item?.id || window.location.pathname.split('/').pop() || '')
	if (!id) {
		return null
	}
	try {
		const response = await axios.get(
			generateUrl('/apps/shillinq/api/v1/public-sector/ensia/{id}/' + file.path, { id }),
			{ responseType: 'blob' },
		)
		const fileName = ensiaFileName(response.headers, file.fallback)
		saveBlob(response.data instanceof Blob ? response.data : new Blob([response.data]), fileName)
		return fileName
	} catch (error) {
		showError(error?.response?.status === 409 ? file.missing : file.failed)
		return null
	}
}

/**
 * Header action on a submitted cycle: download its ENSIA XML.
 *
 * @param {{item?: object}} scope The page scope.
 * @return {Promise<string|null>} The file name, or null when it failed.
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
export function downloadEnsiaXml(scope) {
	return downloadCycleFile(scope, {
		path: 'xml',
		fallback: 'ensia.xml',
		missing: t('shillinq', 'Submit the cycle before downloading its XML.'),
		failed: t('shillinq', 'The ENSIA XML could not be downloaded.'),
	})
}

/**
 * Header action on an approved cycle: download its college statement.
 *
 * @param {{item?: object}} scope The page scope.
 * @return {Promise<string|null>} The file name, or null when it failed.
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
export function downloadEnsiaStatement(scope) {
	return downloadCycleFile(scope, {
		path: 'statement',
		fallback: 'college-verklaring-ensia.docx',
		missing: t('shillinq', 'Move the cycle to college approval before downloading its statement.'),
		failed: t('shillinq', 'The college statement could not be downloaded.'),
	})
}
