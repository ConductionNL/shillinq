<?php

/**
 * Inventory valuation store
 *
 * Reads and writes the active InventoryValuation snapshots of the valuation
 * services through OpenRegister's object service, and turns the rows it
 * returns into arrays (an unsupported row type is refused under the owning
 * service's name).
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
 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use RuntimeException;

/**
 * InventoryValuation persistence for one valuation service.
 *
 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
 */
final class InventoryValuationStore {
	/**
	 * Build the store for a service.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param string                 $source        The owning service name, e.g. FifoValuationService.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly string $source,
	) {
	}//end __construct()

	/**
	 * The active valuation of the key's product, warehouse and
	 * administration, or null when there is none.
	 *
	 * @param StockMoveValuationKey $key      The move's valuation key.
	 * @param string                $register The register slug.
	 *
	 * @return array<string,mixed>|null The snapshot, or null.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
	 */
	public function findActive(StockMoveValuationKey $key, string $register): ?array {
		$existing = $this->objectService
			->setRegister($register)
			->setSchema('InventoryValuation')
			->findAll(
				[
					'filters' => [
						'productId' => $key->productId,
						'warehouse' => $key->warehouse,
						'status' => 'active',
						'administrationId' => $key->administrationId,
					],
					'limit' => 1,
				]
			);

		if (count($existing) > 0) {
			return $this->toArray(row: $existing[0]);
		}

		return null;
	}//end findActive()

	/**
	 * Persist a valuation and return the stored snapshot.
	 *
	 * @param array<string,mixed> $data     The snapshot.
	 * @param string              $register The register slug.
	 *
	 * @return array<string,mixed> The stored snapshot.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
	 */
	public function save(array $data, string $register): array {
		$saved = $this->objectService
			->setRegister($register)
			->setSchema('InventoryValuation')
			->saveObject($data);

		// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
		// is_array() test was constant — toArray() is the only path that runs.
		return $this->toArray(row: $saved);
	}//end save()

	/**
	 * Normalise an OpenRegister row to an array.
	 *
	 * @param mixed $row Array, JsonSerializable entity or object with getObject().
	 *
	 * @return array<string,mixed> The row as an array.
	 *
	 * @throws RuntimeException When the row type is unsupported.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
	 */
	public function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return $this->arrayOrEmpty(value: $row->jsonSerialize());
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			return $this->arrayOrEmpty(value: $row->getObject());
		}

		throw new RuntimeException($this->source . ': unsupported row type from ObjectService');
	}//end toArray()

	/**
	 * A value when it is an array, otherwise an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string,mixed> The array.
	 */
	private function arrayOrEmpty(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayOrEmpty()
}//end class
