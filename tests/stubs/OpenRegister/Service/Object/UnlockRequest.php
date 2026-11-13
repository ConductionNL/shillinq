<?php

/**
 * UnlockRequest stub mirroring OCA\OpenRegister\Service\Object\UnlockRequest (openregister #9095).
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
 * Stub for OCA\OpenRegister\Service\Object\UnlockRequest used by shillinq tests.
 */
final class UnlockRequest {
	/**
	 * Constructor.
	 *
	 * @param string $identifier The object to unlock.
	 * @param bool   $advisory   Whether the lock is advisory.
	 * @param string|null $runUuid The flow run releasing the lock.
	 * @param bool   $break      Whether to break another holder's lock.
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly bool $advisory=false,
		public readonly ?string $runUuid=null,
		public readonly bool $break=false,
	) {
	}//end __construct()
}//end class
