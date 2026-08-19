<?php

/**
 * Hermetic in-memory OpenRegister ObjectService stub for the
 * DunningRunService + CreditScoreService unit tests.
 *
 * Implements the canonical fluent API used by the production services
 * (setRegister + setSchema + findAll + saveObject) and keeps records in
 * a per-schema array so the tests can seed + assert without touching
 * an actual OR runtime.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

/**
 * Minimal stub mimicking OCA\OpenRegister\Service\ObjectService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class InMemoryObjectService {

	/**
	 * Records keyed by schema slug.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $records = [];

	/**
	 * Auto-incrementing id counter.
	 *
	 * @var integer
	 */
	private int $nextId = 1;

	/**
	 * Active schema (set via setSchema()).
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Fluent register setter (no-op — tests use a single register).
	 *
	 * @param string $register OR register slug (ignored — tests use one register).
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		return $this;
	}//end setRegister()

	/**
	 * Fluent schema setter.
	 *
	 * @param string $schema Active schema.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * Pre-populate the in-memory store.
	 *
	 * @param string $schema Schema slug.
	 * @param array<int,array<string,mixed>> $rows Records to seed.
	 *
	 * @return void
	 */
	public function seed(string $schema, array $rows): void {
		foreach ($rows as $row) {
			if (isset($row['id']) === false) {
				$row['id'] = 'auto-' . ($this->nextId++);
			}

			$this->records[$schema][] = $row;
		}

	}//end seed()

	/**
	 * Find all records on the active schema matching the filter map.
	 *
	 * 🔴 **THIS METHOD IS MORE PERMISSIVE THAN REAL OPENREGISTER, DELIBERATELY.**
	 *
	 * It matches every filter key against a plain PHP array key — `id` and
	 * `uuid` included. Real OpenRegister cannot do that: `filters` addresses
	 * the object's JSON PROPERTIES, while `id`/`uuid` are the ObjectEntity's
	 * own COLUMNS, merged into the serialised output only after the query has
	 * run. `findAll(['filters' => ['id' => $x]])` therefore matches ZERO rows
	 * against the real engine, for every value, uuids included, with no
	 * exception and nothing logged.
	 *
	 * So a production lookup written that way is dead on arrival and STILL
	 * PASSES against this double. That is precisely how
	 * `DunningRunService::transferToIncasso()` came to ship a sealing lookup
	 * that never found its run, under two green unit tests.
	 *
	 * 🔑 **Before trusting any test in this suite over an identifier lookup,
	 * check which double it uses.** {@see Support\OpenRegisterFaithfulObjectService}
	 * is this class with the real engine's identifier semantics restored; a
	 * test that must be able to SEE the defect has to use that one. The
	 * leniency is kept here only because the app still carries ~60 live
	 * `filters['id']` sites (shillinq#871) whose repair is a product decision,
	 * not a test-side one — flipping this default red-lines 27 tests across
	 * six services in one step.
	 *
	 * @param array<string,mixed> $args ['filters' => ...].
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function findAll(array $args = []): array {
		$rows = ($this->records[$this->schema] ?? []);
		$filters = (array)($args['filters'] ?? []);
		if ($filters === []) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $expected) {
						$actual = $row[$key] ?? null;
						if ((string)$actual !== (string)$expected) {
							return false;
						}
					}

					return true;
				}
			)
		);

	}//end findAll()

	/**
	 * Persist a record on the active schema. Upserts by id: when a row
	 * with the same `id` already exists on the active schema it is
	 * replaced in place (mirroring OpenRegister's real ObjectService
	 * update-by-id semantics); otherwise the row is appended as a new
	 * record. Without this, a multi-step lifecycle (create -> transition
	 * -> re-read) would see stale, duplicated rows for the same object.
	 *
	 * @param array<string,mixed> $data Record body.
	 *
	 * @return array<string,mixed> The persisted record (with id).
	 */
	public function saveObject(array $data): array {
		if (isset($data['id']) === false) {
			$data['id'] = 'auto-' . ($this->nextId++);
		}

		$rows = ($this->records[$this->schema] ?? []);
		$updated = false;
		foreach ($rows as $i => $row) {
			if (($row['id'] ?? null) === $data['id']) {
				$this->records[$this->schema][$i] = $data;
				$updated = true;
				break;
			}
		}

		if ($updated === false) {
			$this->records[$this->schema][] = $data;
		}

		return $data;
	}//end saveObject()

	/**
	 * Test helper: return every record stored on a schema regardless of filters.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dump(string $schema): array {
		return ($this->records[$schema] ?? []);
	}//end dump()
}//end class
