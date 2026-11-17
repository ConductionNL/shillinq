<?php

/**
 * Shillinq CbC Correction Service
 *
 * "Create correction" on a submitted country-by-country report: a new draft
 * report for the same reporting entity and year that points at the report
 * it corrects (REQ-CBC-013). The correction is prepared from the books and
 * generated like a first report; its file then carries the correction
 * message type and refers to the records of the corrected report.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Cbcr;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;

/**
 * Creates the correction of a submitted CbC report.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.2
 */
class CbcrCorrectionService {

	/**
	 * The states of a report that was filed and so can be corrected.
	 */
	private const FILED_STATES = ['submitted', 'locked'];

	/**
	 * The states of a correction that was not filed yet: a second Create
	 * correction answers with it instead of starting another.
	 */
	private const OPEN_STATES = ['draft', 'reconciled'];

	/**
	 * What a correction takes over from the report it corrects. The figures,
	 * the reconciliation, the file and the Belastingdienst reference are its
	 * own and start empty.
	 */
	private const CARRIED_FIELDS = ['period', 'reportingEntity', 'reportingRole', 'submissionDeadline', 'mcaaPartnerJurisdictions'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig             $appConfig     Names the register.
	 * @param IL10N                  $l10n          Translated refusals.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Create the correction of a submitted CbC report, or answer with the
	 * correction already open for it.
	 *
	 * @param array<string,mixed> $return The CbcrReturn to correct.
	 *
	 * @return array{correction: array<string,mixed>, created: bool}
	 *
	 * @throws InvalidArgumentException When the report was not submitted.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.2
	 */
	public function createCorrection(array $return): array {
		$returnId = (string)($return['id'] ?? '');
		if ($returnId === '' || in_array((string)($return['state'] ?? ''), self::FILED_STATES, true) === false) {
			throw new InvalidArgumentException($this->l10n->t('Only a submitted country-by-country report can be corrected.'));
		}

		$open = $this->openCorrection(returnId: $returnId);
		if ($open !== null) {
			return ['correction' => $open, 'created' => false];
		}

		$correction = ['correctsReturn' => $returnId, 'state' => 'draft'];
		foreach (self::CARRIED_FIELDS as $field) {
			if (array_key_exists($field, $return) === true && $return[$field] !== null) {
				$correction[$field] = $return[$field];
			}
		}

		$saved = $this->objectService->setRegister($this->register())->setSchema('CbcrReturn')->saveObject(object: $correction);

		return ['correction' => ((ObjectIdentifier::recordWithId(candidate: $saved) ?? []) + $correction), 'created' => true];
	}//end createCorrection()

	/**
	 * The correction of this report that was not filed yet, or null.
	 *
	 * @param string $returnId The corrected CbcrReturn id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function openCorrection(string $returnId): ?array {
		$rows = $this->objectService->setRegister($this->register())->setSchema('CbcrReturn')->findAll(
			['filters' => ['correctsReturn' => $returnId], 'limit' => 100]
		);
		foreach ($rows as $row) {
			$record = ObjectIdentifier::recordWithId(candidate: $row);
			if ($record !== null && in_array((string)($record['state'] ?? ''), self::OPEN_STATES, true) === true) {
				return $record;
			}
		}

		return null;
	}//end openCorrection()

	/**
	 * The register slug.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
