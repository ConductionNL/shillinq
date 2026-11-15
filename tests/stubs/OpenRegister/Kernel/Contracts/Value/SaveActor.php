<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\SaveActor.
 *
 * Mirrors the constructor of the real value object on OpenRegister main
 * (lib/Kernel/Contracts/Value/SaveActor.php).
 *
 * @category Test
 * @package  OCA\OpenRegister
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

use OCP\IUser;

/**
 * On whose behalf one object save is written.
 */
final class SaveActor {

	/**
	 * Constructor.
	 *
	 * @param ?IUser  $currentUser Explicit acting user.
	 * @param ?string $owner       Owner to record.
	 * @param bool    $unowned     Store without an owner.
	 */
	public function __construct(
		public readonly ?IUser $currentUser=null,
		public readonly ?string $owner=null,
		public readonly bool $unowned=false,
	) {
	}//end __construct()
}//end class
