<?php

/**
 * Relation Report Query
 *
 * What one both-sides relation read asks for: the administration, the sides
 * the caller may read, the invoice-date range, and whether the invoices are
 * listed or only totalled.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Relation;

/**
 * One both-sides relation read.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
final class RelationReportQuery {

	/**
	 * First invoice date, YYYY-MM-DD.
	 *
	 * @var string
	 */
	public string $from = '';

	/**
	 * Last invoice date, YYYY-MM-DD.
	 *
	 * @var string
	 */
	public string $to = '';

	/**
	 * Whether to list the invoices, not only total them.
	 *
	 * @var bool
	 */
	public bool $withRows = true;

	/**
	 * Constructor.
	 *
	 * @param string                            $administrationId The administration.
	 * @param array{sent: bool, received: bool} $sides            The sides the caller may read.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly array $sides,
	) {

	}//end __construct()

	/**
	 * Whether a date lies inside the range.
	 *
	 * @param mixed $date The date.
	 *
	 * @return bool True when inside.
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function inRange(mixed $date): bool {
		$day = substr((string)$date, 0, 10);

		return $day !== '' && $day >= $this->from && $day <= $this->to;

	}//end inRange()
}//end class
