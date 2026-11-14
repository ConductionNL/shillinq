<?php

/**
 * Handover Scope
 *
 * The administration and reporting period one accountant handover pack is built for.
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
 * @spec openspec/specs/accountant-portal/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * An authorised administration and the period every pack report is generated for.
 *
 * @spec openspec/specs/accountant-portal/spec.md
 */
final class HandoverScope {
	/**
	 * Hold the scope.
	 *
	 * @param string $administrationId The administration the caller is a member of.
	 * @param string $period           The trimmed reporting period.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $period,
	) {
	}//end __construct()
}//end class
