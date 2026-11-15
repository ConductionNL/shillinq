<?php

/**
 * Scan Actor
 *
 * The user applying a batch of inventory scan operations and the administration they apply them in.
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
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * The acting user and the authorised administration of one scan batch.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
final class ScanActor {
	/**
	 * Hold the actor.
	 *
	 * @param string $userId           The acting user.
	 * @param string $administrationId The administration the caller is a member of.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $administrationId,
	) {
	}//end __construct()
}//end class
