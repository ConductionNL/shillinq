<?php

/**
 * ObjectDeletion stub mirroring OCA\OpenRegister\Service\Object\ObjectDeletion (openregister #9099).
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCP\IUser;

/**
 * Stub for OCA\OpenRegister\Service\Object\ObjectDeletion used by shillinq tests.
 */
final class ObjectDeletion {
	/**
	 * Constructor.
	 *
	 * @param string           $uuid        The object's uuid.
	 * @param ObjectFindTarget $target      The register and schema the caller named.
	 * @param IUser|null       $currentUser Explicit acting user, or null for the session user.
	 * @param bool             $permanent   Delete permanently instead of soft-deleting.
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?IUser $currentUser=null,
		public readonly bool $permanent=false,
	) {
	}//end __construct()

	/**
	 * Whether the caller named both a register and a schema.
	 *
	 * @return bool
	 */
	public function isScoped(): bool {
		return $this->target->register !== null && $this->target->schema !== null;
	}//end isScoped()
}//end class
