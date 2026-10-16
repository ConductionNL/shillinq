<?php

/**
 * Relation Records
 *
 * Reads the customer and supplier records of one administration for the
 * relation link and the both-sides view (reporting-relation-both-sides).
 * Every read is scoped to the administration and paged; a row comes back as
 * its payload with its id, whether OpenRegister returned an entity or an
 * array.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Relation;

use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\SettingsService;

/**
 * Administration-scoped reads and patches of the relation schemas.
 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
 */
class RelationRecords {

	/**
	 * Rows per read.
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The most rows one read returns.
	 */
	private const MAX_ROWS = 20000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param SettingsService        $settings      Resolves the register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
	) {

	}//end __construct()

	/**
	 * Every row of a schema in one administration that matches the filters.
	 *
	 * @param string               $schema           The schema slug.
	 * @param string               $administrationId The administration.
	 * @param array<string, mixed> $filters          Further equality filters.
	 *
	 * @return array<int, array<string, mixed>> Payloads, each with `id`.
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function rows(string $schema, string $administrationId, array $filters = []): array {
		$filters['administrationId'] = $administrationId;
		$rows   = [];
		$offset = 0;
		do {
			$page = $this->objectService
				->setRegister($this->settings->getRegisterSlug())
				->setSchema($schema)
				->findAll(['limit' => self::PAGE_SIZE, 'offset' => $offset, 'filters' => $filters]);
			foreach ($page as $row) {
				$rows[] = $this->payload(row: $row);
			}

			$offset  += self::PAGE_SIZE;
			$fullPage = (count($page) >= self::PAGE_SIZE && count($rows) < self::MAX_ROWS);
		} while ($fullPage === true);

		return $rows;

	}//end rows()

	/**
	 * One row of a schema, only when it belongs to the administration.
	 *
	 * @param string $schema           The schema slug.
	 * @param string $administrationId The administration.
	 * @param string $id               The object id.
	 *
	 * @return array<string, mixed>|null The payload with `id`, or null.
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function one(string $schema, string $administrationId, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$entity = $this->objectService
			->setRegister($this->settings->getRegisterSlug())
			->setSchema($schema)
			->find($id);
		if ($entity === null) {
			return null;
		}

		$row = $this->payload(row: $entity);
		if (($row['id'] ?? '') === '') {
			$row['id'] = $id;
		}

		if ((string)($row['administrationId'] ?? '') !== $administrationId) {
			return null;
		}

		return $row;

	}//end one()

	/**
	 * Write some fields of one object.
	 *
	 * @param string               $schema The schema slug.
	 * @param string               $id     The object id.
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function patch(string $schema, string $id, array $fields): void {
		$this->objectService
			->setRegister($this->settings->getRegisterSlug())
			->setSchema($schema)
			->patchObject($id, $fields);

	}//end patch()

	/**
	 * A row as a payload array with an `id`.
	 *
	 * @param mixed $row An entity or an array.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function payload(mixed $row): array {
		if ($row instanceof JsonSerializable) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return [];
		}

		$row['id'] = (string)($row['@self']['id'] ?? $row['id'] ?? $row['uuid'] ?? '');

		return $row;

	}//end payload()
}//end class
