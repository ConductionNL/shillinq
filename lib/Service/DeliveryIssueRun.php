<?php

/**
 * Delivery issue run
 *
 * The state of one SalesDispatchStockIssueService::issueForDelivery() call:
 * the delivery, its id and administration, and the running counts, moves and
 * blocked lines that make up the result envelope.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Progress of issuing the stock of one confirmed Delivery.
 *
 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
 */
final class DeliveryIssueRun {
	/**
	 * The Delivery id (top-level or @self).
	 *
	 * @var string
	 */
	public readonly string $deliveryId;

	/**
	 * The Delivery's administration (trimmed).
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * Lines issued.
	 *
	 * @var int
	 */
	public int $issued = 0;

	/**
	 * Lines skipped.
	 *
	 * @var int
	 */
	public int $skipped = 0;

	/**
	 * Lines blocked by unsellable lots.
	 *
	 * @var int
	 */
	public int $blocked = 0;

	/**
	 * The posted StockMoves.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $moves = [];

	/**
	 * The blocked lines with their offending lots.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $blockedLines = [];

	/**
	 * Start issuing a delivery.
	 *
	 * @param array<string,mixed> $delivery The confirmed Delivery.
	 */
	public function __construct(
		public readonly array $delivery,
	) {
		$this->deliveryId = (string)($delivery['id'] ?? ($delivery['@self']['id'] ?? ''));
		$this->administrationId = trim((string)($delivery['administrationId'] ?? ''));
	}//end __construct()

	/**
	 * Count a line that reached the save step: issued with its move, or
	 * skipped when no move came back.
	 *
	 * @param array<string,mixed>|null $move The posted StockMove, or null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
	 */
	public function countIssue(?array $move): void {
		if ($move === null) {
			$this->skipped++;
			return;
		}

		$this->issued++;
		$this->moves[] = $move;
	}//end countIssue()

	/**
	 * The result envelope.
	 *
	 * @return array<string,mixed> {issued, skipped, blocked, moves, blockedLines}.
	 *
	 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
	 */
	public function result(): array {
		return [
			'issued' => $this->issued,
			'skipped' => $this->skipped,
			'blocked' => $this->blocked,
			'moves' => $this->moves,
			'blockedLines' => $this->blockedLines,
		];
	}//end result()
}//end class
