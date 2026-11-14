<?php

/**
 * The scope and period of a RetainerPool being activated.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/retainer-billing-management/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * The activating pool's identity, client and project scope, and its
 * inclusive effective period (REQ-RETN-001).
 *
 * @spec openspec/specs/retainer-billing-management/spec.md
 */
final class PoolActivationWindow {

	/**
	 * Construct the window.
	 *
	 * @param string $selfId The activating pool's poolId.
	 * @param string $clientId The pool's client.
	 * @param string $projectId The pool's project ('' when none).
	 * @param string $start The trimmed periodStart.
	 * @param string $end The trimmed periodEnd.
	 */
	public function __construct(
		public readonly string $selfId,
		public readonly string $clientId,
		public readonly string $projectId,
		public readonly string $start,
		public readonly string $end,
	) {

	}//end __construct()
}//end class
