<?php

/**
 * ObjectAuditEvent stub mirroring OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent on openregister main.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Stub for OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent used by shillinq tests.
 */
final class ObjectAuditEvent {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity            $object  The object the entry relates to.
	 * @param string                  $action  The action.
	 * @param array<string, mixed>    $context Additional context data.
	 * @param ObjectAuditEventOptions $options The explicit actor and calling address.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly string $action,
		public readonly array $context=[],
		public readonly ObjectAuditEventOptions $options=new ObjectAuditEventOptions(),
	) {
	}//end __construct()
}//end class
