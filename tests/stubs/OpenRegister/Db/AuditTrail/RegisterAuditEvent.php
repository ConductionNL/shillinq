<?php

/**
 * RegisterAuditEvent stub mirroring OCA\OpenRegister\Db\AuditTrail\RegisterAuditEvent from openregister #9714.
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

use InvalidArgumentException;

/**
 * Stub for OCA\OpenRegister\Db\AuditTrail\RegisterAuditEvent used by shillinq tests.
 */
final class RegisterAuditEvent {
	/**
	 * Constructor.
	 *
	 * @param int                  $register The register id the event is about.
	 * @param string               $action   The namespaced action, `<area>.<verb>`.
	 * @param array<string, mixed> $details  What the event records.
	 * @param NamedActor           $actor    The acting principal.
	 *
	 * @throws InvalidArgumentException When the register id is not positive or the action is not namespaced.
	 */
	public function __construct(
		public readonly int $register,
		public readonly string $action,
		public readonly array $details=[],
		public readonly NamedActor $actor=new NamedActor(),
	) {
		if ($register < 1) {
			throw new InvalidArgumentException('A register event names a register id, got '.$register.'.');
		}

		if (preg_match('/^[a-z][a-z0-9_-]*(\.[a-z0-9_-]+)+$/', $action) !== 1) {
			throw new InvalidArgumentException('A register event action is namespaced as <area>.<verb>, got "'.$action.'".');
		}
	}//end __construct()
}//end class
