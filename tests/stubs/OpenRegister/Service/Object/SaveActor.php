<?php

/**
 * SaveActor stub mirroring OCA\OpenRegister\Service\Object\SaveActor (openregister #9123).
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
 * Stub for OCA\OpenRegister\Service\Object\SaveActor used by shillinq tests.
 */
final class SaveActor {
	/**
	 * Constructor.
	 *
	 * @param IUser|null  $currentUser Explicit acting user.
	 * @param string|null $owner       Explicit owner.
	 * @param bool        $unowned     Save without an owner.
	 */
	public function __construct(
		public readonly ?IUser $currentUser=null,
		public readonly ?string $owner=null,
		public readonly bool $unowned=false,
	) {
	}//end __construct()
}//end class
