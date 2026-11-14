<?php

/**
 * ObjectAuditEventOptions stub mirroring OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions on openregister main.
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
 * Stub for OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions used by shillinq tests.
 */
final class ObjectAuditEventOptions {
	/**
	 * Constructor.
	 *
	 * @param NamedActor  $actor     The explicit actor, when given.
	 * @param string|null $ipAddress The calling address, when given.
	 */
	public function __construct(
		public readonly NamedActor $actor=new NamedActor(),
		public readonly ?string $ipAddress=null,
	) {
	}//end __construct()
}//end class
