<?php

/**
 * Contract of SalesDispatchStockIssueService
 *
 * The public surface of SalesDispatchStockIssueService that other components call, so they depend
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
 * Contract of SalesDispatchStockIssueService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SalesDispatchStockIssueServiceInterface {

	/**
	 * Issue one posted StockMove per stock-tracked Delivery line
	 * (REQ-001, REQ-003, REQ-004).
	 *
	 * @param array<string,mixed> $delivery The confirmed Delivery.
	 *
	 * @return array<string,mixed> Result envelope: {issued: int, skipped: int, blocked: int, moves: array, blockedLines: array}.
	 *
	 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
	 * @spec openspec/specs/block-unsellable-stock-dispatch/spec.md
	 */
	public function issueForDelivery(array $delivery): array;

	/**
	 * Reverse every StockMove this Delivery issued via the existing
	 * StockMove.cancel transition (REQ-006). Fail-soft per line: a failure
	 * on one move is logged and does not block reversing the others.
	 *
	 * @param array<string,mixed> $delivery The cancelled Delivery.
	 *
	 * @return array<string,mixed> Result envelope: {reversed: int, failed: int}.
	 *
	 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
	 */
	public function reverseForDelivery(array $delivery): array;
}//end interface
