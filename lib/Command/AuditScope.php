<?php

/**
 * Audit Scope
 *
 * The OpenRegister object service and register one audit command reads through.
 *
 * @category Command
 * @package  OCA\Shillinq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Command;

/**
 * The resolved ObjectService and the shillinq register slug an audit reads from.
 *
 * @spec openspec/changes/abstract-order-primitive/specs/order-primitive/spec.md
 */
final class AuditScope {
	/**
	 * Hold the scope.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $registerSlug  The shillinq register slug.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $registerSlug,
	) {
	}//end __construct()
}//end class
