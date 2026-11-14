<?php

/**
 * VBAR flag
 *
 * One VBAR_GRENS_ONDERSCHREDEN risk flag to raise against a DBAOpdracht: the
 * assignment and the invoice that triggered the check, the administration, the
 * effective hourly rate and the threshold applied, and the register the flag
 * is kept in.
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
 * @spec openspec/specs/dba-compliance-marker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A VBAR hourly-rate threshold flag for one assignment and invoice.
 *
 * @spec openspec/specs/dba-compliance-marker/spec.md
 */
final class VbarFlag {
	/**
	 * Administration scoping.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * The computed effective rate.
	 *
	 * @var int
	 */
	public int $hourlyRateCents = 0;

	/**
	 * The threshold applied.
	 *
	 * @var int
	 */
	public int $vbarGrensCents = 0;

	/**
	 * The register slug.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * Start a flag for an assignment and invoice.
	 *
	 * @param string $assignmentId FK to DBAOpdracht.
	 * @param string $invoiceId    The factuur that triggered the check.
	 */
	public function __construct(
		public readonly string $assignmentId,
		public readonly string $invoiceId,
	) {
	}//end __construct()
}//end class
