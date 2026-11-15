<?php

/**
 * Stock Location Key
 *
 * The (administration, bin location, SKU) triple every StockLedgerService
 * read-out is keyed on, with the log context its fail-soft answers carry.
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
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * One SKU at one bin location of one administration.
 *
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
 */
final class StockLocationKey {

	/**
	 * Product SKU.
	 *
	 * @var string
	 */
	public string $sku = '';

	/**
	 * Constructor.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $locationId       Bin location id.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $locationId,
	) {

	}//end __construct()

	/**
	 * Whether any part of the key is blank (nothing can match it).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	public function isIncomplete(): bool {
		return ($this->administrationId === '' || $this->locationId === '' || $this->sku === '');

	}//end isIncomplete()

	/**
	 * The error-log context of a failed read-out.
	 *
	 * @param \Throwable $error The failure.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	public function failureContext(\Throwable $error): array {
		return [
			'administrationId' => $this->administrationId,
			'locationId' => $this->locationId,
			'sku' => $this->sku,
			'exception' => $error->getMessage(),
		];

	}//end failureContext()
}//end class
