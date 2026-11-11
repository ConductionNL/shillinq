<?php

/**
 * Stock Location Query
 *
 * The administration, location and SKU of one stock-ledger trace, as read
 * from the request.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
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

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * Which SKU at which location of which administration is traced.
 *
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
 */
final class StockLocationQuery {
	/**
	 * Allowed shape of each identifier.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\-]{1,64}$/';

	/**
	 * The administration, trimmed.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The location, trimmed.
	 *
	 * @var string
	 */
	public readonly string $locationId;

	/**
	 * The SKU, trimmed.
	 *
	 * @var string
	 */
	public readonly string $sku;

	/**
	 * Read the query from the request.
	 *
	 * @param IRequest $request The request.
	 */
	public function __construct(IRequest $request) {
		$this->administrationId = trim((string)$request->getParam('administration_id', ''));
		$this->locationId = trim((string)$request->getParam('location_id', ''));
		$this->sku = trim((string)$request->getParam('sku', ''));

	}//end __construct()

	/**
	 * Whether all three identifiers are given.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	public function isComplete(): bool {
		return $this->administrationId !== '' && $this->locationId !== '' && $this->sku !== '';

	}//end isComplete()

	/**
	 * Whether all three identifiers have the allowed shape.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	public function isWellFormed(): bool {
		return preg_match(self::ID_PATTERN, $this->administrationId) === 1
			&& preg_match(self::ID_PATTERN, $this->locationId) === 1
			&& preg_match(self::ID_PATTERN, $this->sku) === 1;

	}//end isWellFormed()

	/**
	 * The log context of a failure.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	public function logContext(): array {
		return [
			'administrationId' => $this->administrationId,
			'locationId' => $this->locationId,
			'sku' => $this->sku,
		];

	}//end logContext()
}//end class
