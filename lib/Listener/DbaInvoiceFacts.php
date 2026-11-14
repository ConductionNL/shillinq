<?php

/**
 * The DBA-relevant facts of one invoice event.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

/**
 * The opdracht, administration, invoice id, amount and hours the VBAR
 * monitor assesses.
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
final class DbaInvoiceFacts {

	/**
	 * Construct the facts.
	 *
	 * @param string $assignmentId The DBA opdracht id.
	 * @param string $administrationId The administration.
	 * @param string $invoiceId The invoice id ('' when unknown).
	 * @param int $amountCents The invoiced amount in cents.
	 * @param float $hours The hours billed.
	 */
	public function __construct(
		public readonly string $assignmentId,
		public readonly string $administrationId,
		public readonly string $invoiceId,
		public readonly int $amountCents,
		public readonly float $hours,
	) {

	}//end __construct()
}//end class
