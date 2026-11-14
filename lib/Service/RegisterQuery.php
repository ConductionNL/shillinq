<?php

/**
 * Register Query
 *
 * One property-filtered read against the shillinq register, answered as
 * plain arrays. A failure is logged and answered as an empty result set: a
 * missing schema must not stop a reader from computing whatever it can.
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
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use Throwable;

/**
 * Runs property-filtered reads against the shillinq register.
 *
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
 */
final class RegisterQuery {

	/**
	 * Hold the register and the failure log.
	 *
	 * @param ShillinqRegister $register   The register to read from.
	 * @param QueryFailureLog  $failureLog Where a failed read is reported.
	 */
	public function __construct(
		private readonly ShillinqRegister $register,
		private readonly QueryFailureLog $failureLog,
	) {
	}//end __construct()

	/**
	 * Run one property-filtered query.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Property filters (never `id`).
	 *
	 * @return list<array<string,mixed>> The matching records as plain arrays.
	 *
	 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-009
	 */
	public function rows(string $schema, array $filters): array {
		try {
			$rows = $this->register->schema(schema: $schema)->findAll(['filters' => $filters]);
		} catch (Throwable $e) {
			$this->failureLog->report(schema: $schema, error: $e);
			return [];
		}

		return self::plainRows(rows: $rows);
	}//end rows()

	/**
	 * Keep array rows, unwrap entity rows to their object payload, drop the rest.
	 *
	 * @param array<int|string,mixed> $rows The raw findAll() rows.
	 *
	 * @return list<array<string,mixed>> The rows as plain arrays.
	 */
	private static function plainRows(array $rows): array {
		$result = [];
		foreach ($rows as $row) {
			$plain = self::plainRow(row: $row);
			if ($plain !== null) {
				$result[] = $plain;
			}
		}

		return $result;
	}//end plainRows()

	/**
	 * One row as a plain array: an array as is, an entity's array payload, or
	 * null for anything else.
	 *
	 * @param mixed $row One raw findAll() row.
	 *
	 * @return array<string,mixed>|null The plain row, or null.
	 */
	private static function plainRow(mixed $row): array|null {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === false || method_exists($row, 'getObject') === false) {
			return null;
		}

		$payload = $row->getObject();
		if (is_array($payload) === true) {
			return $payload;
		}

		return null;
	}//end plainRow()
}//end class
