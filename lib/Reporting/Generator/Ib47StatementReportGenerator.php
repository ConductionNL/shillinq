<?php

/**
 * Ib47StatementReportGenerator
 *
 * Writes the prepared IB47 batch of a year as the statement file.
 *
 * @category  Reporting
 * @package   OCA\Shillinq\Reporting\Generator
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/changes/people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Generator;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Reporting\ReportGeneratorInterface;
use OCA\Shillinq\Support\RegisterSlug;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use Override;

/**
 * The IB47 statement: one line per recipient of the year's prepared batch.
 *
 * It writes what the batch holds and computes nothing: the sums are made
 * once, by Ib47BatchService, so the file and the batch page cannot disagree.
 * Dry-run records are left out, since they are checks and not the filing.
 *
 * The records are read under the caller's own access, so the BSN column is
 * only filled for a reader the register lets read it (the payroll officer).
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Generator
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/changes/people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md
 */
final class Ib47StatementReportGenerator implements ReportGeneratorInterface {

	/**
	 * The statement's column headings.
	 */
	public const HEADER = ['Belastingjaar', 'Naam ontvanger', 'BSN', 'Adres', 'Soort betaling', 'Totaal betalingen (EUR)'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083 rule 1).
	 * @param IAppConfig             $appConfig     App config, for the register slug.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The catalogue id this generator produces.
	 *
	 * @return string
	 *
	 * @spec exclude Structural: the catalogue contract every generator answers.
	 */
	#[Override]
	public static function reportType(): string {
		return 'ib47';
	}//end reportType()

	/**
	 * The formats this generator emits.
	 *
	 * @return array<int, string>
	 *
	 * @spec exclude Structural: the catalogue contract every generator answers.
	 */
	#[Override]
	public static function supportedFormats(): array {
		return ['csv'];
	}//end supportedFormats()

	/**
	 * Write the statement of the year the period names.
	 *
	 * @param array<string, mixed> $context `period` (a year, or a period starting with one) and `administrationId`.
	 * @param string               $format  Always csv.
	 *
	 * @return GeneratedFile
	 *
	 * @throws InvalidArgumentException When the period names no year or no administration is given.
	 *
	 * @spec openspec/changes/people-ib47-statement/specs/bookkeeping-detachering-payroll-administratie/spec.md#requirement-the-annual-ib47-batch-is-prepared-from-the-payments-to-third-parties-req-dpa-021
	 */
	#[Override]
	public function generate(array $context, string $format): GeneratedFile {
		$period           = trim((string)($context['period'] ?? ''));
		$administrationId = trim((string)($context['administrationId'] ?? ''));
		if (preg_match('/^([0-9]{4})/', $period, $match) !== 1) {
			throw new InvalidArgumentException('The IB47 statement needs a tax year as its period.');
		}

		if ($administrationId === '') {
			throw new InvalidArgumentException('The IB47 statement needs the administration that made the payments.');
		}

		$taxYear = (int)$match[1];
		$handle  = fopen('php://temp', 'r+');
		fputcsv($handle, self::HEADER);
		foreach ($this->records(administrationId: $administrationId, taxYear: $taxYear) as $record) {
			fputcsv(
				$handle,
				[
					(string)$taxYear,
					(string)($record['recipientName'] ?? ''),
					(string)($record['recipientBSN'] ?? ''),
					(string)($record['recipientAddress'] ?? ''),
					(string)($record['paymentTypeCode'] ?? ''),
					number_format((float)($record['paymentsTotal'] ?? 0), 2, '.', ''),
				]
			);
		}

		rewind($handle);
		$content = (string)stream_get_contents($handle);
		fclose($handle);

		return new GeneratedFile(
			fileName: 'ib47-' . $taxYear . '-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $administrationId) . '.csv',
			mimeType: 'text/csv',
			format: 'csv',
			content: $content
		);
	}//end generate()

	/**
	 * The year's final records, by recipient name.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $taxYear          The tax year.
	 *
	 * @return list<array<string, mixed>> The records.
	 */
	private function records(string $administrationId, int $taxYear): array {
		$found = $this->objectService
			->setRegister(RegisterSlug::of(config: $this->appConfig))
			->setSchema('IB47Record')
			->findAll(['filters' => ['administrationId' => $administrationId, 'taxYear' => $taxYear]]);

		$records = [];
		foreach ($found as $candidate) {
			$record = ObjectIdentifier::recordWithId(candidate: $candidate);
			if ($record === null
				|| ($record['isDryRun'] ?? false) === true
				|| (int)($record['taxYear'] ?? 0) !== $taxYear
				|| (string)($record['administrationId'] ?? '') !== $administrationId
			) {
				continue;
			}

			$records[] = $record;
		}

		usort(
			$records,
			static fn (array $one, array $two): int => strcmp((string)($one['recipientName'] ?? ''), (string)($two['recipientName'] ?? ''))
		);

		return $records;
	}//end records()
}//end class
