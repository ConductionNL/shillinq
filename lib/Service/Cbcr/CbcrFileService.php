<?php

/**
 * Shillinq country-by-country file service
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Cbcr;

use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ReportGenerationServiceInterface;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCP\IL10N;
use RuntimeException;

/**
 * "Generate XML" on a country-by-country report: the file through the report
 * pipeline, stored and tagged like every other report, and linked on the return.
 *
 * Only a member of the administration of the report's ultimate parent may make
 * the file, the same rule as the report's checks; for anyone else the report
 * does not exist. The `cbc-report` generator refuses on a blocking check or a
 * file that does not pass the schema, and writes the validation on the return;
 * this service puts the stored GeneratedReport's id in `cbcrXmlSubmission`,
 * which the submit guard needs. A file that could not be stored clears the
 * link, so an older file is never taken for this one.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
 */
class CbcrFileService {

	/**
	 * Constructor.
	 *
	 * @param ReportGenerationServiceInterface      $reports Renders, stores and records the file.
	 * @param SbrRecords                            $records The register's records.
	 * @param AdministrationContextServiceInterface $context Which administrations the caller may use.
	 * @param IL10N                                 $l10n    Translated refusals.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function __construct(
		private readonly ReportGenerationServiceInterface $reports,
		private readonly SbrRecords $records,
		private readonly AdministrationContextServiceInterface $context,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Generate, store and link the return's CbC file.
	 *
	 * @param string $returnId The CbcrReturn id.
	 *
	 * @return array{report:array<string,mixed>,return:array<string,mixed>}|null The GeneratedReport and
	 *                                                                          the updated return; null when
	 *                                                                          the caller may not see the report.
	 *
	 * @throws RuntimeException When the file is refused or not stored; the message says why.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function generateCbc(string $returnId): ?array {
		$return = null;
		if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $returnId) === 1) {
			$return = $this->records->find(schema: 'CbcrReturn', id: $returnId);
		}

		$administrationId = $this->groupAdministration(return: $return);
		if ($return === null || $administrationId === null) {
			return null;
		}

		if (in_array((string)($return['state'] ?? 'draft'), ['submitted', 'locked'], true) === true) {
			throw new RuntimeException($this->l10n->t('A filed report keeps its file. Create a correction to send changed figures.'));
		}

		$report = $this->reports->generateForRecord(
			reportType: 'cbc-report',
			period: (string)($return['period'] ?? ''),
			administrationId: $administrationId,
			subject: ['returnId' => (string)$return['id']]
		);

		$current = ($this->records->find(schema: 'CbcrReturn', id: (string)$return['id']) ?? $return);
		$fileId  = '';
		if (isset($report['error']) === false) {
			$fileId = trim((string)($report['id'] ?? ''));
		}

		if ($fileId === '') {
			if (trim((string)($current['cbcrXmlSubmission'] ?? '')) !== '') {
				$this->link(return: $current, fileId: '');
			}

			throw new RuntimeException((string)($report['message'] ?? $this->l10n->t('The country-by-country file could not be stored.')));
		}

		return ['report' => $report, 'return' => $this->link(return: $current, fileId: $fileId)];
	}//end generateCbc()

	/**
	 * The administration of the report's ultimate parent, when the caller is a member of it.
	 *
	 * @param array<string,mixed>|null $return The CbcrReturn.
	 *
	 * @return string|null Null when there is none or the caller may not use it.
	 */
	private function groupAdministration(?array $return): ?string {
		$entity = $this->records->find(schema: 'GroupEntityRegistry', id: (string)($return['reportingEntity'] ?? ''));
		$upeId  = trim((string)($entity['ultimateParentEntity'] ?? ''));
		if ($entity !== null && $upeId !== '' && $upeId !== (string)($entity['id'] ?? '')) {
			$entity = $this->records->find(schema: 'GroupEntityRegistry', id: $upeId);
		}

		$administrationId = trim((string)($entity['administrationId'] ?? ''));
		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) !== true) {
			return null;
		}

		return $administrationId;
	}//end groupAdministration()

	/**
	 * Put the file's id on the return.
	 *
	 * @param array<string,mixed> $return The CbcrReturn as stored now.
	 * @param string              $fileId The GeneratedReport id, '' to clear.
	 *
	 * @return array<string,mixed> The stored return.
	 */
	private function link(array $return, string $fileId): array {
		$object = ['cbcrXmlSubmission' => $fileId] + $return;
		unset($object['@self']);

		return $this->records->save(schema: 'CbcrReturn', record: $object);
	}//end link()
}//end class
