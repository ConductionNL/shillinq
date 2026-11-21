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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\ReportGenerationServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use OCP\IL10N;
use RuntimeException;

/**
 * "Generate XML" on a country-by-country report: the file through the report
 * pipeline, stored and tagged like every other report, and linked on the return.
 *
 * The `cbc-report` generator refuses on a blocking check or a file that does
 * not pass the schema, and writes the validation on the return; this service
 * stores the file (ReportGenerationService) and puts the GeneratedReport id in
 * `cbcrXmlSubmission`, which the submit guard needs. A file that could not be
 * stored clears the link, so an older file is never taken for this one.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
 */
class CbcrFileService {

	/**
	 * Constructor.
	 *
	 * @param ReportGenerationServiceInterface $reports   Renders, stores and records the file.
	 * @param ObjectServiceInterface           $objects   OpenRegister's object service.
	 * @param IAppConfig                       $appConfig Names the register.
	 * @param IL10N                            $l10n      Translated refusals.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function __construct(
		private readonly ReportGenerationServiceInterface $reports,
		private readonly ObjectServiceInterface $objects,
		private readonly IAppConfig $appConfig,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Generate, store and link the return's CbC file.
	 *
	 * @param array<string,mixed> $return           The CbcrReturn.
	 * @param string              $administrationId The administration the file is filed under.
	 *
	 * @return array{report:array<string,mixed>,return:array<string,mixed>} The GeneratedReport and the updated return.
	 *
	 * @throws RuntimeException When the file is refused or not stored; the message says why.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.1
	 */
	public function generateCbc(array $return, string $administrationId): array {
		$returnId = (string)($return['id'] ?? '');
		if ($returnId === '') {
			throw new RuntimeException($this->l10n->t('The country-by-country report was not found.'));
		}

		if (in_array((string)($return['state'] ?? 'draft'), ['submitted', 'locked'], true) === true) {
			throw new RuntimeException($this->l10n->t('A filed report keeps its file. Create a correction to send changed figures.'));
		}

		$report = $this->reports->generate(
			reportType: 'cbc-report',
			period: (string)($return['period'] ?? ''),
			administrationId: $administrationId,
			format: 'xml',
			asUserId: null,
			subject: ['returnId' => $returnId]
		);

		$current = ($this->record(id: $returnId) ?? $return);
		$fileId  = '';
		if (isset($report['error']) === false) {
			$fileId = trim((string)($report['id'] ?? ''));
		}

		if ($fileId === '') {
			if (trim((string)($current['cbcrXmlSubmission'] ?? '')) !== '') {
				$this->save(return: $current, fields: ['cbcrXmlSubmission' => '']);
			}

			throw new RuntimeException((string)($report['message'] ?? $this->l10n->t('The country-by-country file could not be stored.')));
		}

		return ['report' => $report, 'return' => $this->save(return: $current, fields: ['cbcrXmlSubmission' => $fileId])];
	}//end generateCbc()

	/**
	 * Write fields onto the return in place.
	 *
	 * @param array<string,mixed> $return The CbcrReturn.
	 * @param array<string,mixed> $fields The fields.
	 *
	 * @return array<string,mixed> The stored return.
	 */
	private function save(array $return, array $fields): array {
		$object = $fields + $return;
		unset($object['@self']);
		$saved = $this->objects->setRegister($this->register())->setSchema('CbcrReturn')->saveObject(
			object: $object,
			options: new ObjectSaveOptions(uuid: (string)($return['id'] ?? ''))
		);

		return (ObjectIdentifier::recordWithId(candidate: $saved) ?? []) + $object;
	}//end save()

	/**
	 * The return as stored now.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string,mixed>|null
	 */
	private function record(string $id): ?array {
		return ObjectIdentifier::findOne(scoped: $this->objects->setRegister($this->register())->setSchema('CbcrReturn'), id: $id);
	}//end record()

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
