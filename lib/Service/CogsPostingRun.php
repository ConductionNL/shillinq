<?php

/**
 * COGS posting run
 *
 * The state of one CogsPosterService::postCogs() call: the outbound StockMove
 * and its COGS amount, the driving InventoryValuation snapshot and the two
 * accounts the posting debits and credits.
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
 * @spec openspec/specs/inventory-cogs-posting/spec.md#req-cg-002-the-system-shall-auto-post-a-cogs-inventory-asset-gltransaction-on-every-saledispatch-event
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Progress of posting the COGS of one outbound StockMove.
 *
 * @spec openspec/specs/inventory-cogs-posting/spec.md#req-cg-002-the-system-shall-auto-post-a-cogs-inventory-asset-gltransaction-on-every-saledispatch-event
 */
final class CogsPostingRun {
	/**
	 * The driving InventoryValuation snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public array $valuation = [];

	/**
	 * The COGS (debit) account.
	 *
	 * @var string
	 */
	public string $cogsAccount = '';

	/**
	 * The inventory (credit) account.
	 *
	 * @var string
	 */
	public string $inventoryAccount = '';

	/**
	 * Start posting a move's COGS.
	 *
	 * @param array<string,mixed> $move      The outbound StockMove (movementType=issue).
	 * @param int                 $cogsCents Total COGS amount in integer cents.
	 */
	public function __construct(
		public readonly array $move,
		public readonly int $cogsCents,
	) {
	}//end __construct()
}//end class
