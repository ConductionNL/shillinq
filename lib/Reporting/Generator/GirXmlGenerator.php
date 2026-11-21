<?php

/**
 * Shillinq GloBE information return XML report generator
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
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

namespace OCA\Shillinq\Reporting\Generator;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use OCA\Shillinq\Service\Cbcr\CbcrCheckRunner;
use OCA\Shillinq\Service\Cbcr\GirXmlDocument;
use OCA\Shillinq\Service\Sbr\SbrRecords;
use OCP\IL10N;
use RuntimeException;

/**
 * Answers the `globe-information-return` report: the GIR file of one GlobeInformationReturn.
 *
 * The context names the return (`returnId`). First the generator lists every
 * mandatory element the records cannot fill and writes that list to the
 * return's `missingElements`, so the checks tab shows it (design D5). Then it
 * refuses while a blocking check fails, naming it, and while the list is not
 * empty (REQ-CBC-009, REQ-CBC-012). Otherwise the file is rendered and
 * validated against the bundled GIR set before anything is stored: a file that
 * fails validation is not returned and its errors go to `lastValidation`. A
 * valid file writes `lastValidation` with the records' fingerprint, which the
 * approve guard compares, and a `DocRefId` per block in `docRefIds`.
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
 */
final class GirXmlGenerator implements ReportGeneratorInterface {

	/**
	 * Constructor.
	 *
	 * @param SbrRecords      $records  The register's records.
	 * @param CbcrCheckRunner $checks   The checks and the records they read.
	 * @param GirXmlDocument  $document Lists the gaps, renders and validates the file.
	 * @param IL10N           $l10n     Translated refusals.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public function __construct(
		private readonly SbrRecords $records,
		private readonly CbcrCheckRunner $checks,
		private readonly GirXmlDocument $document,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public static function reportType(): string {
		return 'globe-information-return';
	}//end reportType()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 */
	public static function supportedFormats(): array {
		return ['xml'];
	}//end supportedFormats()

	/**
	 * List the gaps, then render, validate and return the return's GIR file.
	 *
	 * @param array<string, mixed> $context `{ returnId, period, administrationId }`.
	 * @param string               $format  Must be 'xml'.
	 *
	 * @return GeneratedFile
	 *
	 * @throws RuntimeException When the return is not found, a blocking check fails, an element is missing or the file is not valid.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-4.3
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	public function generate(array $context, string $format): GeneratedFile {
		if ($format !== 'xml') {
			throw new RuntimeException($this->l10n->t('The GloBE information return is XML only.'));
		}

		$return = $this->records->find(schema: 'GlobeInformationReturn', id: (string)($context['returnId'] ?? ''));
		if ($return === null) {
			throw new RuntimeException($this->l10n->t('Generate the GloBE information return from its return page.'));
		}

		$records = $this->checks->girRecords(return: $return);
		$missing = $this->document->missingElements(records: $records);
		$return  = $this->update(return: $return, fields: ['missingElements' => $missing]);

		$this->refuseOnBlockingChecks(return: $return);
		if ($missing !== []) {
			throw new RuntimeException($this->missingMessage(missing: $missing));
		}

		$file       = $this->fileParts(return: $return, records: $records);
		$xml        = $this->document->render(file: $file);
		$validation = $this->document->validate(xml: $xml) + ['validatedAt' => self::now()->format('Y-m-d\TH:i:s\Z')];
		if ($validation['valid'] === false) {
			$this->update(return: $return, fields: ['lastValidation' => $validation]);
			throw new RuntimeException($this->invalidMessage(errors: $validation['errors']));
		}

		$validation['fingerprint']  = $this->checks->girFingerprint(return: $return);
		$validation['messageRefId'] = $file['messageRefId'];
		$this->update(return: $return, fields: ['lastValidation' => $validation, 'docRefIds' => $file['docRefIds']]);

		return new GeneratedFile(
			fileName: sprintf('globe-information-return-%s-%s.xml', self::yearOf(return: $return), $file['messageRefId']),
			mimeType: 'application/xml',
			format: 'xml',
			content: $xml,
		);
	}//end generate()

	/**
	 * Refuse while a mandatory check fails, naming every failing check.
	 *
	 * @param array<string,mixed> $return The GlobeInformationReturn, with its missing elements.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a blocking check fails.
	 *
	 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/tasks.md#task-5.1
	 */
	private function refuseOnBlockingChecks(array $return): void {
		$failing = [];
		foreach ($this->checks->runGir(return: $return) as $result) {
			if ($result['passed'] === false && $result['severity'] === 'mandatory') {
				$failing[] = $result['id'] . ': ' . $this->l10n->t($result['statement']);
			}
		}

		if ($failing !== []) {
			throw new RuntimeException(
				$this->l10n->t('The GloBE information return is not made while a blocking check fails. %s', [implode(' ', $failing)])
			);
		}
	}//end refuseOnBlockingChecks()

	/**
	 * The parts the document renders, with a DocRefId per block.
	 *
	 * @param array<string,mixed> $return  The GlobeInformationReturn.
	 * @param array<string,mixed> $records The records of CbcrCheckRunner::girRecords().
	 *
	 * @return array<string,mixed>
	 */
	private function fileParts(array $return, array $records): array {
		$messageRefId = $this->messageRefId(return: $return, upe: (array)($records['upe'] ?? []));

		$docRefIds = [];
		foreach ($this->document->docRefKeys(returnId: (string)($return['id'] ?? ''), jurisdictions: $records['jurisdictions']) as $index => $key) {
			$docRefIds[$key] = $messageRefId . '-' . ($index + 1);
		}

		return $records + [
			'return' => $return,
			'messageRefId' => $messageRefId,
			'timestamp' => self::now()->format('Y-m-d\TH:i:s'),
			'docRefIds' => $docRefIds,
			'language' => 'EN',
			'currency' => strtoupper((string)($return['currency'] ?? 'EUR')),
		];
	}//end fileParts()

	/**
	 * The refusal for a return with missing elements: the count and the first three.
	 *
	 * @param array<int,array{jurisdiction:string,entity:string,element:string}> $missing The missing elements.
	 *
	 * @return string
	 */
	private function missingMessage(array $missing): string {
		$lines = [];
		foreach (array_slice($missing, 0, 3) as $gap) {
			$lines[] = trim($gap['jurisdiction'] . ' ' . $gap['entity']) . ': ' . $gap['element'];
		}

		return $this->l10n->t(
			'The GloBE information return is not made: %1$s elements the schema requires are not in the records. %2$s',
			[(string)count($missing), implode('; ', $lines)]
		);
	}//end missingMessage()

	/**
	 * The refusal for a file that does not pass the schema: its first three errors.
	 *
	 * @param array<int,array{line:int,element:string,message:string}> $errors The errors.
	 *
	 * @return string
	 */
	private function invalidMessage(array $errors): string {
		$lines = [];
		foreach (array_slice($errors, 0, 3) as $error) {
			$line = $error['message'];
			if ($error['line'] > 0) {
				$line = 'Line ' . $error['line'] . ': ' . $line;
			}

			$lines[] = $line;
		}

		return $this->l10n->t('The GloBE information return does not pass the OECD schema, so it was not stored. %s', [implode(' ', $lines)]);
	}//end invalidMessage()

	/**
	 * Write fields onto the return in place.
	 *
	 * @param array<string,mixed> $return The GlobeInformationReturn.
	 * @param array<string,mixed> $fields The fields.
	 *
	 * @return array<string,mixed> The return with the fields.
	 */
	private function update(array $return, array $fields): array {
		$object = $fields + $return;
		unset($object['@self']);
		$this->records->save(schema: 'GlobeInformationReturn', record: $object);

		return $object;
	}//end update()

	/**
	 * A unique message reference: NL, the year, the ultimate parent's TIN and a random part.
	 *
	 * @param array<string,mixed> $return The GlobeInformationReturn.
	 * @param array<string,mixed> $upe    The ultimate parent.
	 *
	 * @return string
	 */
	private function messageRefId(array $return, array $upe): string {
		$tin = (string)preg_replace('/[^A-Za-z0-9]/', '', $this->document->tinOf(entity: $upe)['value']);

		return sprintf('NL%sGIR%s-%s', self::yearOf(return: $return), $tin, bin2hex(random_bytes(6)));
	}//end messageRefId()

	/**
	 * Now, in UTC.
	 *
	 * @return DateTimeImmutable
	 */
	private static function now(): DateTimeImmutable {
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}//end now()

	/**
	 * The fiscal year of the return.
	 *
	 * @param array<string,mixed> $return The GlobeInformationReturn.
	 *
	 * @return string
	 */
	private static function yearOf(array $return): string {
		if (preg_match('/(\d{4})/', (string)($return['period'] ?? ''), $match) === 1) {
			return $match[1];
		}

		return '0000';
	}//end yearOf()
}//end class
