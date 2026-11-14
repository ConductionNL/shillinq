<?php

/**
 * Shillinq SBR filing records
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sbr
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

namespace OCA\Shillinq\Service\Sbr;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use RuntimeException;

/**
 * Reads and writes the records an SBR filing is made of, in shillinq's register.
 *
 * @spec openspec/changes/tax-digipoort-filing/specs/bookkeeping-sbr-xbrl-reporting/spec.md
 */
class SbrRecords {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface    $objectService OpenRegister's object service.
	 * @param RegisterLocationInterface $location      Where the register lives.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterLocationInterface $location,
	) {
	}//end __construct()

	/**
	 * One record by its uuid, or null.
	 *
	 * @param string      $schema   The schema slug.
	 * @param string      $id       The uuid, or the fallback property's value.
	 * @param string|null $fallback A property to match when the id is not a uuid.
	 *
	 * @return array<string,mixed>|null The record.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public function find(string $schema, string $id, ?string $fallback=null): ?array {
		if (trim($id) === '') {
			return null;
		}

		return ObjectIdentifier::findOne(scoped: $this->scoped(schema: $schema), id: trim($id), fallbackProperty: $fallback);
	}//end find()

	/**
	 * The records matching the filters.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string,mixed>  $filters Property filters.
	 *
	 * @return list<array<string,mixed>> The records.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public function findAll(string $schema, array $filters): array {
		$records = [];
		foreach ((array) $this->scoped(schema: $schema)->findAll(['filters' => $filters]) as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;
	}//end findAll()

	/**
	 * Save a record and return it with its uuid.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $record The record.
	 *
	 * @return array<string,mixed> The saved record.
	 *
	 * @throws RuntimeException When the save returns no uuid.
	 *
	 * @spec openspec/changes/tax-digipoort-filing/tasks.md#task-1.2
	 */
	public function save(string $schema, array $record): array {
		$saved = $this->scoped(schema: $schema)->saveObject(object: $record);
		$id    = ObjectIdentifier::resolve(saved: $saved);
		if ($id === '') {
			throw new RuntimeException(sprintf('The %s record could not be saved.', $schema));
		}

		$record['id'] = $id;
		return $record;
	}//end save()

	/**
	 * The object service scoped to the register and a schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface The scoped service.
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->location->getRegisterSlug())->setSchema($schema);
	}//end scoped()
}//end class
