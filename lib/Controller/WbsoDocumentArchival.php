<?php

/**
 * WBSO document archival
 *
 * The document, reason and retention override of one archive request.
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
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * What to archive, why, and whether the 7-year retention boundary is overridden.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-26
 */
final class WbsoDocumentArchival {
	/**
	 * The document to archive.
	 *
	 * @var string
	 */
	public readonly string $documentId;

	/**
	 * The reason as sent.
	 *
	 * @var string
	 */
	public readonly string $reason;

	/**
	 * Whether the archive may precede the retention boundary.
	 *
	 * @var boolean
	 */
	public readonly bool $allowEarly;

	/**
	 * Read the archival from the request.
	 *
	 * @param IRequest $request The request.
	 * @param string $documentId The document from the route.
	 */
	public function __construct(IRequest $request, string $documentId) {
		$this->documentId = $documentId;
		$this->reason = (string)$request->getParam('reason', '');
		$this->allowEarly = (bool)$request->getParam('allowEarly', false);

	}//end __construct()
}//end class
