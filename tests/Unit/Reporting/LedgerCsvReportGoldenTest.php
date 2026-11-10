<?php

/**
 * Golden test for the general-ledger and trial-balance CSV reports.
 *
 * Generates both CSVs over fixed GLLine and Account rows and compares the
 * file name, mime type, format and exact bytes with recorded expectations, so
 * restructuring the grouping, ordering, running balance, totals or the CSV
 * writing cannot change the output.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reports-via-docudesk/specs/reports-via-docudesk/spec.md#req-rvd-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use OCA\Shillinq\Reporting\Generator\GeneralLedgerReportGenerator;
use OCA\Shillinq\Reporting\Generator\TrialBalanceReportGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden CSVs for the two ledger reports.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LedgerCsvReportGoldenTest extends TestCase {

	/**
	 * Path of the recorded golden.
	 *
	 * @var string
	 */
	private const GOLDEN = __DIR__ . '/fixtures/ledger-csv-report-golden.json';

	/**
	 * A container whose object service answers rows per schema.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$rows = [
			'GLLine' => [
				['accountNumber' => '4000', 'lineNumber' => 2, 'transactionId' => 't-2', 'postingDate' => '2026-01-05', 'description' => 'Huur, jan', 'amount' => '100.10', 'side' => 'debit'],
				['accountNumber' => '4000', 'lineNumber' => 1, 'transactionId' => 't-9', 'valueDate' => '2026-01-01', 'amount' => 50, 'side' => 'credit'],
				['accountNumber' => '4000', 'lineNumber' => 1, 'transactionId' => 't-1', 'postingDate' => '2026-01-02', 'amount' => 25.555, 'side' => 'debit'],
				['accountNumber' => '1000', 'lineNumber' => 1, 'transactionId' => 't-3', 'amount' => 'x', 'side' => 'debit', 'accountName' => 'Kas (regel)'],
				['accountNumber' => '', 'lineNumber' => 1, 'transactionId' => 't-4', 'amount' => 7, 'side' => 'debit'],
				['accountNumber' => '8000', 'lineNumber' => 1, 'transactionId' => 't-5', 'amount' => 300, 'side' => 'credit', 'accountName' => 'Omzet (regel)'],
				['accountNumber' => '8000', 'lineNumber' => 2, 'transactionId' => 't-6', 'amount' => 40, 'side' => 'credit', 'eliminationFlag' => true],
				['accountNumber' => '9000', 'lineNumber' => 1, 'transactionId' => 't-7', 'amount' => 12, 'side' => 'credit', 'eliminationFlag' => 'yes'],
				'junk',
			],
			'Account' => [
				['accountNumber' => '4000', 'name' => 'Huur "kantoor"'],
				['accountNumber' => '1000', 'name' => 'Kas'],
			],
		];
		$objectService = new class($rows) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, list<mixed>> $rows The rows.
			 */
			public function __construct(
				private readonly array $rows,
			) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return list<mixed>
			 */
			public function findAll(array $config): array {
				return $this->rows[$this->schema] ?? [];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return $container;

	}//end container()

	/**
	 * Both reports equal the golden.
	 *
	 * @return void
	 */
	public function testReportsMatchGolden(): void {
		$actual = [];
		foreach (['general-ledger' => GeneralLedgerReportGenerator::class, 'trial-balance' => TrialBalanceReportGenerator::class] as $name => $class) {
			$generator = new $class($this->container(), $this->createMock(LoggerInterface::class));
			$file = $generator->generate(['period' => '2026-Q1', 'administrationId' => 'adm-1'], 'csv');
			$actual[$name] = ['fileName' => $file->fileName, 'mimeType' => $file->mimeType, 'format' => $file->format, 'content' => $file->content];
		}

		if (getenv('SHILLINQ_RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		self::assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);

	}//end testReportsMatchGolden()
}//end class
