<?php

/**
 * Public Sector Records
 *
 * Reads and writes the objects the reserve and interest services work on
 * (public-sector-reserves-and-interest): reserves, their mutations,
 * investments, interest runs and journal entries, all in shillinq's register.
 * Posting a journal entry runs its declared `postDirect` transition, so the
 * posting guards and the GL materialisation apply as they do on the ledger
 * page.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Object access for reserves, mutations, investments and interest runs.
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */
class PublicSectorRecords {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param ObjectTransitionRunner $transitions   Runs the declared transitions.
	 * @param SettingsService        $settings      Register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectTransitionRunner $transitions,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * One object by id or slug, with its id, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $id     The object's id or slug.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
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
	 * Records of a schema with their ids.
	 *
	 * @param string              $schema  The schema slug.
	 * @param array<string,mixed> $filters Property filters.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
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
	 * Save a new object and answer its id.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $object The object.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function create(string $schema, array $object): string {
		return ObjectIdentifier::resolve(saved: $this->scoped(schema: $schema)->saveObject($object));

	}//end create()

	/**
	 * Save a draft journal entry, post it through its declared transition and
	 * answer its id.
	 *
	 * @param array<string,mixed> $journal The JournalEntry payload in state draft.
	 *
	 * @return string The journal entry's id.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function postJournal(array $journal): string {
		$journalId = $this->create(schema: 'JournalEntry', object: $journal);
		$this->transitions->run(objectId: $journalId, action: 'postDirect');
		return $journalId;

	}//end postJournal()

	/**
	 * The object service scoped to shillinq's register and one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);

	}//end scoped()
}//end class
