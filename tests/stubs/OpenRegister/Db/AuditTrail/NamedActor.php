<?php

/**
 * NamedActor stub mirroring OCA\OpenRegister\Db\AuditTrail\NamedActor on openregister main.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\AuditTrail;

/**
 * Stub for OCA\OpenRegister\Db\AuditTrail\NamedActor used by shillinq tests.
 */
final class NamedActor {
	/**
	 * Constructor.
	 *
	 * @param string|null $id   Explicit actor id.
	 * @param string|null $name Explicit actor display name.
	 */
	public function __construct(
		public readonly ?string $id=null,
		public readonly ?string $name=null,
	) {
	}//end __construct()
}//end class
