<?php

/**
 * Contract of DocudeskExtractionClient
 *
 * The public surface of DocudeskExtractionClient that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of DocudeskExtractionClient.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DocudeskExtractionClientInterface {

	/**
	 * Request a (re-)extraction for a document (REQ-RXC-005).
	 *
	 * POSTs `{documentUri, docType, callbackEvent: true}` to docudesk's
	 * `POST /api/extraction/financial`. Always sets `callbackEvent: true` —
	 * shillinq only consumes the async event path (REQ-RXC-001), never the
	 * synchronous response body.
	 *
	 * Returns an outcome envelope and never throws. `extractionId` is the docudesk
	 * `financialExtraction` object id read from the synchronous response
	 * (REQ-GAC-001), or null when the request failed or the response body carried
	 * no usable id.
	 *
	 * @param string $documentUri The docudesk source document URI.
	 * @param string $docType `receipt` or `supplier-invoice`.
	 *
	 * @return array{success: bool, statusCode: int, error: string|null, extractionId: string|null}
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
	 */
	public function requestExtraction(string $documentUri, string $docType): array;
}//end interface
