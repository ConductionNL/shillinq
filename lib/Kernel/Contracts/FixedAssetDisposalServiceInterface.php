<?php

/**
 * Contract of FixedAssetDisposalService
 *
 * The public surface of FixedAssetDisposalService that other components call, so they depend
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
 * Contract of FixedAssetDisposalService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FixedAssetDisposalServiceInterface {

	/**
	 * Materialise the disposal journal for a retired FixedAsset (REQ-GLTAX-001).
	 *
	 * Wired by {@see \OCA\Shillinq\Listener\FixedAssetDisposalListener} on
	 * the `FixedAsset` `active -> retired` transition.
	 *
	 * @param string $administrationId Tenant scope (server-resolved).
	 * @param array<string,mixed> $asset The retired FixedAsset record.
	 *
	 * @return array<string,mixed> Result envelope: {posted:bool, transaction:?array,
	 *                             lines:array, bookValue:float, gain:float, loss:float,
	 *                             amountCents:int, message:string}.
	 *
	 * @throws \RuntimeException When the administration is inaccessible or the
	 *                           emitted lines do not balance.
	 *
	 * @spec openspec/specs/revive-gl-tax-capabilities/spec.md
	 */
	public function postDisposalJournal(string $administrationId, array $asset): array;
}//end interface
