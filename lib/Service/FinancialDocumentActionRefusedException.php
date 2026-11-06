<?php

/**
 * Financial Document Action Refused Exception
 *
 * An act on a financial document that was refused before anything was
 * written, with the HTTP status the controller answers with.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DomainException;

/**
 * A refused act and its status.
 *
 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
 */
class FinancialDocumentActionRefusedException extends DomainException {
	/**
	 * Constructor.
	 *
	 * @param string $message Why, in words the panel shows.
	 * @param int $status The HTTP status: 400, 403, 404 or 409.
	 */
	public function __construct(string $message, private readonly int $status) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The HTTP status the refusal answers with.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/financial-documents-on-any-object/specs/financial-documents-on-any-object/spec.md (REQ-FDO-003)
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
