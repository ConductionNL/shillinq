<?php

/**
 * LockRequest stub mirroring OCA\OpenRegister\Service\Object\LockRequest (openregister #9095).
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
 * Stub for OCA\OpenRegister\Service\Object\LockRequest used by shillinq tests.
 */
final class LockRequest {
	/**
	 * The flow run holding the lock.
	 *
	 * @var string|null
	 */
	public readonly ?string $runUuid;

	/**
	 * The flow node holding the lock.
	 *
	 * @var string|null
	 */
	public readonly ?string $nodeId;

	/**
	 * Constructor.
	 *
	 * @param string      $identifier The object to lock.
	 * @param string|null $process    The process name.
	 * @param int|null    $duration   Lock duration in seconds.
	 * @param LockRunStep $runStep    The flow run and node holding the lock.
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly ?string $process=null,
		public readonly ?int $duration=null,
		LockRunStep $runStep=new LockRunStep(runUuid: null, nodeId: null),
	) {
		$this->runUuid = $runStep->runUuid;
		$this->nodeId  = $runStep->nodeId;
	}//end __construct()
}//end class
