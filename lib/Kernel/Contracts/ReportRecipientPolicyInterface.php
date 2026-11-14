<?php

/**
 * Contract of ReportRecipientPolicy
 *
 * The public surface of ReportRecipientPolicy that other components call, so they depend
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

/**
 * Contract of ReportRecipientPolicy.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportRecipientPolicyInterface {

	/**
	 * The recipients that are not allowed.
	 *
	 * @param string             $ownerId          The schedule's owner.
	 * @param string             $administrationId The administration reported on.
	 * @param array<int, string> $recipients       `user:<id>` and `group:<id>` entries.
	 *
	 * @return array<int, string> The refused entries, empty when all are allowed.
	 *
	 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.8
	 */
	public function refused(string $ownerId, string $administrationId, array $recipients): array;
}//end interface
