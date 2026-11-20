<?php

/**
 * Inventory Valuation Query
 *
 * The input of GET /api/inventory/valuation as read from the request.
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

use OCP\IRequest;

/**
 * The administration, date, optional item and warehouse, and whether ageing
 * is asked for, of one valuation report.
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
final class InventoryValuationQuery {
	/**
	 * Allowed shape of an administration, sku or warehouse id.
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9_.\\- ]{1,64}$/';

	/**
	 * The administration, trimmed.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The as-of date, trimmed.
	 *
	 * @var string
	 */
	public readonly string $asOf;

	/**
	 * The item, '' for all.
	 *
	 * @var string
	 */
	public readonly string $sku;

	/**
	 * The warehouse, '' for all.
	 *
	 * @var string
	 */
	public readonly string $warehouse;

	/**
	 * Whether `ageing=1` was sent.
	 *
	 * @var boolean
	 */
	public readonly bool $ageingAsked;

	/**
	 * Read the query from the request.
	 *
	 * @param IRequest $request                The request.
	 * @param string   $activeAdministrationId The caller's active administration, used when the request names none.
	 *
	 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-the-valuation-report-has-a-page-req-ircl-004
	 */
	public function __construct(IRequest $request, string $activeAdministrationId = '') {
		$administrationId = trim((string)$request->getParam('administration_id', ''));
		if ($administrationId === '') {
			$administrationId = trim($activeAdministrationId);
		}

		$this->administrationId = $administrationId;
		$this->asOf = trim((string)$request->getParam('as_of', ''));
		$this->sku = trim((string)$request->getParam('sku', ''));
		$this->warehouse = trim((string)$request->getParam('warehouse', ''));
		$this->ageingAsked = ((string)$request->getParam('ageing', '') === '1');

	}//end __construct()

	/**
	 * Whether the administration and the date are given.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function isComplete(): bool {
		return $this->administrationId !== '' && $this->asOf !== '';

	}//end isComplete()

	/**
	 * Whether every given value has its allowed shape.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function isWellFormed(): bool {
		return preg_match(self::ID_PATTERN, $this->administrationId) === 1
			&& preg_match('/^\d{4}-\d{2}-\d{2}([T ].*)?$/', $this->asOf) === 1
			&& self::isOptionalId(value: $this->sku) === true
			&& self::isOptionalId(value: $this->warehouse) === true;

	}//end isWellFormed()

	/**
	 * The item filter, null for all items.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function skuFilter(): ?string {
		return self::nullWhenEmpty(value: $this->sku);

	}//end skuFilter()

	/**
	 * The warehouse filter, null for all warehouses.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function warehouseFilter(): ?string {
		return self::nullWhenEmpty(value: $this->warehouse);

	}//end warehouseFilter()

	/**
	 * Whether ageing is asked for one item in one warehouse.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function wantsAgeing(): bool {
		return $this->ageingAsked === true && $this->sku !== '' && $this->warehouse !== '';

	}//end wantsAgeing()

	/**
	 * Whether an optional id is empty or has the allowed shape.
	 *
	 * @param string $value The id.
	 *
	 * @return bool
	 */
	private static function isOptionalId(string $value): bool {
		return $value === '' || preg_match(self::ID_PATTERN, $value) === 1;

	}//end isOptionalId()

	/**
	 * The value, null when it is ''.
	 *
	 * @param string $value The value.
	 *
	 * @return string|null
	 */
	private static function nullWhenEmpty(string $value): ?string {
		if ($value === '') {
			return null;
		}

		return $value;

	}//end nullWhenEmpty()
}//end class
