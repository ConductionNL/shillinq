<?php

/**
 * Contract of ReceiptCostLayerBackfill
 *
 * The public surface of ReceiptCostLayerBackfill that other components call, so they depend
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
 * Contract of ReceiptCostLayerBackfill.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReceiptCostLayerBackfillInterface {

	/**
	 * Replay every product at every location and write its snapshot.
	 *
	 * @return array{locations: int, receipts: int, undercosted: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
	 */
	public function run(): array;
}//end interface
