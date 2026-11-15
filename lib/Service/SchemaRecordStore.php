<?php

/**
 * Schema record store
 *
 * Saves and reads plain-array records on one OpenRegister schema at a time for
 * the services that persist their records through it. A failing save is
 * logged and rethrown as "Failed to persist <schema>"; a failing query is
 * logged and reads as no rows; rows that are not arrays are dropped.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use RuntimeException;

/**
 * Plain-array persistence on the app's register.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class SchemaRecordStore {
	/**
	 * Build a store on a register binding, logging for its owner.
	 *
	 * @param RegisterBinding $binding The object service bound to the register.
	 * @param RecordStoreLog  $log     The owner's error log.
	 */
	public function __construct(
		private readonly RegisterBinding $binding,
		private readonly RecordStoreLog $log,
	) {
	}//end __construct()

	/**
	 * Persist a record and return the stored payload (with its id).
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $object The record to persist.
	 *
	 * @return array<string,mixed> The persisted record.
	 *
	 * @throws RuntimeException When the store refuses the record.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function saveObject(string $schema, array $object): array {
		try {
			$result = $this->binding->schema(schema: $schema)->saveObject($object);

			// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
			// stored payload (with the id/uuid the store generated) is read back
			// through jsonSerialize().
			return (array)$result->jsonSerialize();
		} catch (\Throwable $e) {
			$this->log->error(
				event: 'failed to persist object',
				context: ['schema' => $schema, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist ' . $schema);
		}

	}//end saveObject()

	/**
	 * The first record matching the filters, or null.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null The record, or null.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function findOne(string $schema, array $filters): ?array {
		$rows = $this->findAll(schema: $schema, filters: $filters);
		foreach ($rows as $row) {
			return $row;
		}

		return null;
	}//end findOne()

	/**
	 * Every record matching the filters; a failing query reads as none.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>> The records.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function findAll(string $schema, array $filters): array {
		try {
			$rows = $this->binding->schema(schema: $schema)->findAll(['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->log->error(
				event: 'failed to query OpenRegister',
				context: ['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()
}//end class
