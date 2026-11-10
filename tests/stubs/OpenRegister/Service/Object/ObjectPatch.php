<?php

/**
 * ObjectPatch stub mirroring OCA\OpenRegister\Service\Object\ObjectPatch (openregister #9096).
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
 * Stub for OCA\OpenRegister\Service\Object\ObjectPatch used by shillinq tests.
 */
final class ObjectPatch {
	/**
	 * Constructor.
	 *
	 * @param string               $objectId    The object's id or uuid.
	 * @param array<string, mixed> $data        The fields to merge onto the stored object.
	 * @param ObjectFindTarget     $target      The register and schema the caller named.
	 * @param IUser|null           $currentUser Explicit acting user, or null for the session user.
	 */
	public function __construct(
		public readonly string $objectId,
		public readonly array $data,
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?IUser $currentUser=null,
	) {
	}//end __construct()
}//end class
