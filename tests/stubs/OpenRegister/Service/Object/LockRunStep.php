<?php

/**
 * LockRunStep stub mirroring OCA\OpenRegister\Service\Object\LockRunStep on openregister main.
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
 * Stub for OCA\OpenRegister\Service\Object\LockRunStep used by shillinq tests.
 */
final class LockRunStep {
	/**
	 * Constructor.
	 *
	 * @param string|null $runUuid The flow run holding the lock.
	 * @param string|null $nodeId  The flow node holding the lock.
	 */
	public function __construct(
		public readonly ?string $runUuid,
		public readonly ?string $nodeId,
	) {
	}//end __construct()
}//end class
