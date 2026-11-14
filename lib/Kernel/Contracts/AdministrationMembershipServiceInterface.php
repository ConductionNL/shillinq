<?php

/**
 * Contract of AdministrationMembershipService
 *
 * The public surface of AdministrationMembershipService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use DomainException;
use InvalidArgumentException;

/**
 * Contract of AdministrationMembershipService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdministrationMembershipServiceInterface {

	/**
	 * Add a member to an administration.
	 *
	 * @param string $administrationId The administration.
	 * @param string $userId The Nextcloud user to add.
	 * @param string $role One of {@see self::ROLES}.
	 * @param string $grantedBy The user who adds the member.
	 * @param array<string,mixed> $options Optional `validFrom`, `validUntil` (Y-m-d),
	 *                                     `mayPostJournalEntries`, `mayCloseFiscalYear` (bool).
	 *
	 * @return array<string,mixed> The saved membership.
	 *
	 * @throws InvalidArgumentException When the role or an option is invalid.
	 * @throws DomainException When the user already holds a membership of the administration.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
	 */
	public function addMember(string $administrationId, string $userId, string $role, string $grantedBy, array $options=[]): array;
}//end interface
