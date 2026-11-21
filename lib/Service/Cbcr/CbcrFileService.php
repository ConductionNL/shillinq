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
 * link, so an older file is never taken for this one. "Generate XML" on a
 * minimum tax return does the same with the `globe-information-return`
 * generator and `globeXmlSubmission`, for the ultimate parent's members.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
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
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
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

		return $this->generateAndLink(
			kind: ['schema' => 'CbcrReturn', 'reportType' => 'cbc-report', 'field' => 'cbcrXmlSubmission'],
			return: $return,
			administrationId: $administrationId,
			failure: $this->l10n->t('The country-by-country file could not be stored.')
		);
	}//end generateCbc()

	/**
	 * Generate, store and link the minimum tax return's GloBE information return file.
	 *
	 * @param string $returnId The GlobeInformationReturn id.
	 *
	 * @return array{report:array<string,mixed>,return:array<string,mixed>}|null The GeneratedReport and
	 *                                                                          the updated return; null when
	 *                                                                          the caller may not see the return.
	 *
	 * @throws RuntimeException When the file is refused or not stored; the message says why.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-6.3
	 */
	public function generateGir(string $returnId): ?array {
		$return = null;
		if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $returnId) === 1) {
			$return = $this->records->find(schema: 'GlobeInformationReturn', id: $returnId);
		}

		$upe              = $this->records->find(schema: 'GroupEntityRegistry', id: (string)($return['ultimateParent'] ?? ''));
		$administrationId = $this->accessibleAdministration(entity: $upe);
		if ($return === null || $administrationId === null) {
			return null;
		}

		if ((string)($return['state'] ?? 'draft') !== 'draft') {
			throw new RuntimeException($this->l10n->t('An approved or filed minimum tax return keeps its file.'));
		}

		return $this->generateAndLink(
			kind: ['schema' => 'GlobeInformationReturn', 'reportType' => 'globe-information-return', 'field' => 'globeXmlSubmission'],
			return: $return,
			administrationId: $administrationId,
			failure: $this->l10n->t('The GloBE information return could not be stored.')
		);
	}//end generateGir()

	/**
	 * Generate the file through the report pipeline and put its id on the return, or clear the link.
	 *
	 * @param array{schema:string,reportType:string,field:string} $kind             The return's schema, the report type and the link field.
	 * @param array<string,mixed>                                 $return           The return.
	 * @param string                                              $administrationId The group's administration.
	 * @param string                                              $failure          The refusal when nothing was stored and no reason was given.
	 *
	 * @return array{report:array<string,mixed>,return:array<string,mixed>}
	 *
	 * @throws RuntimeException When the file is refused or not stored.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	private function generateAndLink(array $kind, array $return, string $administrationId, string $failure): array {
		$report = $this->reports->generateForRecord(
			reportType: $kind['reportType'],
			period: (string)($return['period'] ?? ''),
			administrationId: $administrationId,
			subject: ['returnId' => (string)$return['id']]
		);

		$current = ($this->records->find(schema: $kind['schema'], id: (string)$return['id']) ?? $return);
		$fileId  = '';
		if (isset($report['error']) === false) {
			$fileId = trim((string)($report['id'] ?? ''));
		}

		if ($fileId === '') {
			if (trim((string)($current[$kind['field']] ?? '')) !== '') {
				$this->link(kind: $kind, return: $current, fileId: '');
			}

			throw new RuntimeException((string)($report['message'] ?? $failure));
		}

		return ['report' => $report, 'return' => $this->link(kind: $kind, return: $current, fileId: $fileId)];
	}//end generateAndLink()

	/**
	 * The administration of the report's ultimate parent, when the caller is a member of it.
	 *
	 * @param array<string,mixed>|null $return The CbcrReturn.
	 *
	 * @return string|null Null when there is none or the caller may not use it.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	private function groupAdministration(?array $return): ?string {
		$entity = $this->records->find(schema: 'GroupEntityRegistry', id: (string)($return['reportingEntity'] ?? ''));
		$upeId  = trim((string)($entity['ultimateParentEntity'] ?? ''));
		if ($entity !== null && $upeId !== '' && $upeId !== (string)($entity['id'] ?? '')) {
			$entity = $this->records->find(schema: 'GroupEntityRegistry', id: $upeId);
		}

		return $this->accessibleAdministration(entity: $entity);
	}//end groupAdministration()

	/**
	 * An entity's administration, when the caller is a member of it.
	 *
	 * @param array<string,mixed>|null $entity The GroupEntityRegistry row.
	 *
	 * @return string|null Null when there is none or the caller may not use it.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	private function accessibleAdministration(?array $entity): ?string {
		$administrationId = trim((string)($entity['administrationId'] ?? ''));
		if ($administrationId === '' || $this->context->canAccess(administrationId: $administrationId) !== true) {
			return null;
		}

		return $administrationId;
	}//end accessibleAdministration()

	/**
	 * Put the file's id on the return.
	 *
	 * @param array{schema:string,field:string} $kind   The return's schema and its link field.
	 * @param array<string,mixed>               $return The return as stored now.
	 * @param string                            $fileId The GeneratedReport id, '' to clear.
	 *
	 * @return array<string,mixed> The stored return.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	private function link(array $kind, array $return, string $fileId): array {
		$object = [$kind['field'] => $fileId] + $return;
		unset($object['@self']);

		return $this->records->save(schema: $kind['schema'], record: $object);
	}//end link()
}//end class
