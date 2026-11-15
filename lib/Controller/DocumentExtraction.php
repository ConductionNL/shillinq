<?php

/**
 * Document Extraction
 *
 * The document, document type and optional target draft of one
 * (re-)extraction request.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 * @spec openspec/specs/gl-account-suggestion-consume/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * What one extraction request asks for, trimmed from the request.
 *
 * @spec openspec/specs/receipt-extraction-consume/spec.md
 */
final class DocumentExtraction {
	/**
	 * The document types docudesk extracts.
	 *
	 * @var array<int, string>
	 */
	private const DOC_TYPES = ['receipt', 'supplier-invoice'];

	/**
	 * The document to extract.
	 *
	 * @var string
	 */
	public readonly string $documentUri;

	/**
	 * The document type.
	 *
	 * @var string
	 */
	public readonly string $docType;

	/**
	 * The existing draft to capture the extraction id on, '' for none.
	 *
	 * @var string
	 */
	public readonly string $draftId;

	/**
	 * Read the request parameters.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->documentUri = trim((string)$request->getParam('documentUri', ''));
		$this->docType = trim((string)$request->getParam('docType', ''));
		$this->draftId = trim((string)$request->getParam('id', ''));

	}//end __construct()

	/**
	 * Whether a document and a known document type are given.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/receipt-extraction-consume/spec.md
	 */
	public function isComplete(): bool {
		return $this->documentUri !== '' && in_array($this->docType, self::DOC_TYPES, true) === true;
	}//end isComplete()
}//end class
