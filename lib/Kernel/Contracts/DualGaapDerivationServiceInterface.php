<?php

/**
 * Dual GAAP derivation contract
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of DualGaapDerivationService.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DualGaapDerivationServiceInterface {

	/**
	 * Derive the other framework's entry of a posted transaction and write the bridge line.
	 *
	 * @param array<string, mixed> $transaction The posted GLTransaction, with its `id`.
	 *
	 * @return array{derived: bool, message: string, differenceCents?: int, dualTransaction?: array<string, mixed>, bridge?: array<string, mixed>}
	 *
	 * @spec openspec/changes/reporting-ifrs-calculations/tasks.md#task-1.1
	 */
	public function deriveOnPost(array $transaction): array;
}//end interface
