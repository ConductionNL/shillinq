<?php

/**
 * SavePolicy stub mirroring OCA\OpenRegister\Service\Object\SavePolicy (openregister #9123).
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

/**
 * Stub for OCA\OpenRegister\Service\Object\SavePolicy used by shillinq tests.
 */
final class SavePolicy {
	/**
	 * Constructor.
	 *
	 * @param bool $silent        Suppress events.
	 * @param bool $validation    Validate against the schema.
	 * @param bool $failIfExists  Fail instead of updating an existing object.
	 * @param bool $dedupOverride Skip the duplicate check.
	 */
	public function __construct(
		public readonly bool $silent=false,
		public readonly bool $validation=true,
		public readonly bool $failIfExists=false,
		public readonly bool $dedupOverride=false,
	) {
	}//end __construct()
}//end class
