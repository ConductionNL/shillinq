<?php

/**
 * Three-Way Audit Scope
 *
 * The administration and supplier invoice an audit-trail request is about.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * A checked administration and the supplier invoice within it.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 */
final class ThreeWayAuditScope {
	/**
	 * Construct the scope.
	 *
	 * @param string $administrationId The administration the caller may access.
	 * @param string $invoiceId The supplier invoice.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $invoiceId,
	) {
	}//end __construct()

	/**
	 * Filters selecting each id within the administration.
	 *
	 * @param array<int,string> $ids The ids.
	 *
	 * @return array<int,array{id: string, administrationId: string}>
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	public function filtersFor(array $ids): array {
		$filters = [];
		foreach ($ids as $id) {
			$filters[] = [
				'id' => $id,
				'administrationId' => $this->administrationId,
			];
		}

		return $filters;

	}//end filtersFor()
}//end class
