<?php

/**
 * Contract of GrantSettlement
 *
 * The public surface of GrantSettlement that other components call, so they
 * depend on this contract instead of the implementation.
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

/**
 * Contract of GrantSettlement.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GrantSettlementInterface {

	/**
	 * Settle a grant being determined: the reclaimed amount and, when more was
	 * paid out than determined, the draft reclaim invoice or payable.
	 *
	 * @param array<string,mixed> $grant The Subsidie as the determine transition leaves it.
	 *
	 * @return array<string,mixed> The grant with reclaimedAmount and the reclaim document id set.
	 *
	 * @throws DomainException When the grant has no determined amount, or a reclaim has no counterparty.
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md#requirement-determining-a-grant-settles-the-difference-req-subv-011
	 */
	public function settle(array $grant): array;

	/**
	 * Store the accountability report of a grant, and the pending auditor
	 * statement when the grant's amount requires one.
	 *
	 * @param array<string,mixed> $grant The Subsidie, with its id.
	 *
	 * @return array{verantwoordingId: string, auditorStatementRequired: bool, auditorStatementId: string|null}
	 *
	 * @spec openspec/changes/archive/2026-11-24-public-sector-grant-accountability/specs/bookkeeping-subsidie-verantwoording/spec.md#requirement-the-accountability-report-is-generated-from-the-grant-req-subv-012
	 */
	public function report(array $grant): array;
}//end interface
