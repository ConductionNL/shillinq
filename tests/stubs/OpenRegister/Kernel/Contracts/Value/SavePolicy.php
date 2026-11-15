<?php

/**
 * Test stub for OCA\OpenRegister\Kernel\Contracts\Value\SavePolicy.
 *
 * Mirrors the constructor of the real value object on OpenRegister main
 * (lib/Kernel/Contracts/Value/SavePolicy.php).
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

/**
 * The save policy of one object save.
 */
final class SavePolicy {

	/**
	 * Constructor.
	 *
	 * @param bool $silent        Suppress events.
	 * @param bool $validation    Validate against the schema.
	 * @param bool $failIfExists  Fail when the object already exists.
	 * @param bool $dedupOverride Override deduplication.
	 */
	public function __construct(
		public readonly bool $silent=false,
		public readonly bool $validation=true,
		public readonly bool $failIfExists=false,
		public readonly bool $dedupOverride=false,
	) {
	}//end __construct()
}//end class
