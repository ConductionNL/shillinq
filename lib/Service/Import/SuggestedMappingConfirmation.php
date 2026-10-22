<?php

/**
 * Suggested Mapping Confirmation
 *
 * Confirms, in one server call, every mapping row of an import batch that
 * already has a target account.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Import;

use DomainException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Confirms the suggested mappings of a batch the caller may work on.
 *
 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
 */
class SuggestedMappingConfirmation {

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param SettingsService $settings Supplies the register slug.
	 * @param AdministrationContextService $administrationContext Membership of the batch's administration.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly AdministrationContextService $administrationContext,
	) {
	}//end __construct()

	/**
	 * Confirm every unconfirmed mapping row with a target account.
	 *
	 * @param string $batchId The import batch id.
	 *
	 * @return int How many rows were confirmed.
	 *
	 * @throws ImportBatchNotFoundException When the batch does not exist or the caller is no member of its administration.
	 * @throws DomainException When the batch is not in the mapping state.
	 *
	 * @spec openspec/changes/platform-administration-import/specs/administration-import-migration/spec.md
	 */
	public function confirm(string $batchId): int {
		$register = $this->settings->getRegisterSlug();
		$entity = null;
		try {
			$entity = $this->objectService->find($batchId, [], false, $register, 'ImportBatch');
		} catch (\Throwable $e) {
			$entity = null;
		}

		$batch = ObjectIdentifier::recordWithId(candidate: $entity);
		if ($batch === null
			|| $this->administrationContext->canAccess(administrationId: (string)($batch['administrationId'] ?? '')) === false
		) {
			throw new ImportBatchNotFoundException('Import batch not found.');
		}

		if (($batch['status'] ?? '') !== 'mapping') {
			throw new DomainException('Suggestions can only be confirmed while the batch is being mapped.');
		}

		$rows = $this->objectService->setRegister($register)->setSchema('ImportMapping')
			->findAll(['filters' => ['batchReference' => $batchId]]);

		$confirmed = 0;
		foreach ($rows as $row) {
			$mapping = ObjectIdentifier::recordWithId(candidate: $row);
			if ($mapping === null
				|| empty($mapping['targetAccount']) === true
				|| ($mapping['confirmed'] ?? false) === true
			) {
				continue;
			}

			$id = (string)($mapping['id'] ?? '');
			unset($mapping['@self'], $mapping['id']);
			$mapping['confirmed'] = true;
			$this->objectService->saveObject(object: $mapping, register: $register, schema: 'ImportMapping', uuid: $id);
			$confirmed++;
		}

		return $confirmed;
	}//end confirm()
}//end class
