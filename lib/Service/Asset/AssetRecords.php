<?php

/**
 * Asset Records
 *
 * Reads and writes the fixed-asset records (FixedAsset, DepreciationSchedule,
 * ReinvestmentReserve) in shillinq's register, and posts a journal entry
 * through the JournalEntry `postDirect` transition, the way every posting in
 * shillinq reaches the ledger (assets-method-change-and-reserve).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Asset
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Asset;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Record access for the depreciation run, the revision and the reserve.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class AssetRecords {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param ObjectTransitionRunner $transitions   Runs declared transitions.
	 * @param SettingsService        $settings      Supplies the register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectTransitionRunner $transitions,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * One record by id, null when absent.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The id.
	 *
	 * @return array<string,mixed>|null The record with its id.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function find(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$record = ObjectIdentifier::findOne(scoped: $this->scoped(schema: $schema), id: $id);
		if ($record === null) {
			return null;
		}

		if (isset($record['id']) === false || (string)$record['id'] === '') {
			$record['id'] = $id;
		}

		return $record;

	}//end find()

	/**
	 * The records matching exact filters.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Exact-match filters.
	 *
	 * @return list<array<string,mixed>> The records, each with its id.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function records(string $schema, array $filters): array {
		$records = [];
		foreach ($this->scoped(schema: $schema)->findAll(['filters' => $filters, 'limit' => 10000]) as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null) {
				$records[] = $record;
			}
		}

		return $records;

	}//end records()

	/**
	 * Save a record, new or existing, and return it with its id.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $object The record.
	 *
	 * @return array<string,mixed> The saved record.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function save(string $schema, array $object): array {
		$saved = ObjectIdentifier::recordWithId(candidate: $this->scoped(schema: $schema)->saveObject($object));
		if ($saved === null) {
			return $object;
		}

		return $saved;

	}//end save()

	/**
	 * Merge fields into an existing record.
	 *
	 * @param string              $schema The schema slug.
	 * @param string              $id     The record's id.
	 * @param array<string,mixed> $fields The fields to change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function patch(string $schema, string $id, array $fields): void {
		$this->objectService->patchObject(objectId: $id, data: $fields, register: $this->settings->getRegisterSlug(), schema: $schema);

	}//end patch()

	/**
	 * Delete a record.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The record's id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function delete(string $schema, string $id): void {
		$this->objectService->deleteObject(uuid: $id, register: $this->settings->getRegisterSlug(), schema: $schema);

	}//end delete()

	/**
	 * Create a draft journal entry and post it without approval.
	 *
	 * @param array<string,mixed> $journal The JournalEntry payload in state draft.
	 *
	 * @return string The journal entry's id.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function postJournal(array $journal): string {
		$journalId = ObjectIdentifier::resolve(saved: $this->scoped(schema: 'JournalEntry')->saveObject($journal));
		$this->transitions->run(objectId: $journalId, action: 'postDirect');
		return $journalId;

	}//end postJournal()

	/**
	 * The object service pointed at shillinq's register and a schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface The scoped service.
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
