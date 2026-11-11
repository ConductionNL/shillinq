<?php

/**
 * Member Grant
 *
 * The administration, the user to add and the owner granting it, for one
 * new administration membership.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * One authorised membership grant.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 */
final class MemberGrant {
	/**
	 * The administration.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The existing user to add.
	 *
	 * @var string
	 */
	public readonly string $userId;

	/**
	 * The owner granting the membership.
	 *
	 * @var string
	 */
	public readonly string $grantedBy;

	/**
	 * Name one grant.
	 *
	 * @param string $administrationId The administration.
	 * @param string $userId           The existing user to add.
	 * @param string $grantedBy        The owner granting the membership.
	 */
	public function __construct(string $administrationId, string $userId, string $grantedBy) {
		$this->administrationId = $administrationId;
		$this->userId = $userId;
		$this->grantedBy = $grantedBy;

	}//end __construct()
}//end class
