<?php

/**
 * Administration Schema
 *
 * One schema read within one administration: every lookup the audit export
 * makes is scoped to the administration the package is for.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A schema scoped to one administration.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
 */
final class AdministrationSchema {

	/**
	 * Hold the administration and the schema.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $schema           The schema slug.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
