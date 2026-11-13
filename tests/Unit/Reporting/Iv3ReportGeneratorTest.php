<?php

/**
 * Unit tests for Iv3ReportGenerator.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting;

use DOMDocument;
use DOMXPath;
use OCA\Shillinq\Reporting\Generator\Iv3ReportGenerator;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The Iv3 report totals the period's ledger lines per task field.
 *
 * @covers \OCA\Shillinq\Reporting\Generator\Iv3ReportGenerator
 * @uses   \OCA\Shillinq\Reporting\GeneratedFile
 */
final class Iv3ReportGeneratorTest extends TestCase {

	/**
	 * The ledger: two administrations, two periods.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private const DATA = [
		'Account' => [
			['id' => 'a1', 'administrationId' => 'adm-1', 'accountNumber' => '4000', 'taskField' => '0.1'],
			['id' => 'a2', 'administrationId' => 'adm-1', 'code' => '8000', 'taskField' => '7.2'],
			['id' => 'a3', 'administrationId' => 'adm-1', 'accountNumber' => '9000'],
		],
		'GLLine' => [
			// Task field from the account, by accountNumber and by code.
			['administrationId' => 'adm-1', 'periodId' => '2026-03', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 100],
			['administrationId' => 'adm-1', 'periodId' => '2026-03', 'accountNumber' => '4000', 'side' => 'credit', 'amount' => '25.50'],
			['administrationId' => 'adm-1', 'periodId' => '2026-03', 'accountNumber' => '8000', 'side' => 'credit', 'amount' => 300.0],
			// The line's own task field wins over the account's.
			['administrationId' => 'adm-1', 'periodId' => '2026-03', 'accountNumber' => '8000', 'taskField' => '0.1', 'side' => 'debit', 'amount' => 10],
			// No task field anywhere: 0.0; a non-numeric amount counts as zero.
			['administrationId' => 'adm-1', 'periodId' => '2026-03', 'accountNumber' => '9000', 'side' => 'debit', 'amount' => 40],
			['administrationId' => 'adm-1', 'periodId' => '2026-03', 'accountNumber' => '9000', 'side' => 'debit', 'amount' => 'n/a'],
			// Eliminated, another period, another administration: excluded.
			['administrationId' => 'adm-1', 'periodId' => '2026-03', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 999, 'eliminationFlag' => true],
			['administrationId' => 'adm-1', 'periodId' => '2026-04', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 999],
			['administrationId' => 'adm-2', 'periodId' => '2026-03', 'accountNumber' => '4000', 'side' => 'debit', 'amount' => 999],
		],
	];

	/**
	 * The CSV totals each task field, sorted, with saldo = revenue - expenses.
	 *
	 * @return void
	 */
	public function testCsvTotalsPerTaskField(): void {
		$file = $this->generator()->generate(['administrationId' => 'adm-1', 'period' => '2026-03'], 'csv');

		$this->assertSame('iv3-2026-03.csv', $file->fileName);
		$this->assertSame('text/csv', $file->mimeType);
		$this->assertSame('csv', $file->format);
		$this->assertSame(
			"taskField,expenses,revenue,saldo\n"
			. "0.0,40.00,0.00,-40.00\n"
			. "0.1,110.00,25.50,-84.50\n"
			. "7.2,0.00,300.00,300.00\n",
			$file->content
		);
	}//end testCsvTotalsPerTaskField()

	/**
	 * The XML carries the same totals per Taakveld, with the context.
	 *
	 * @return void
	 */
	public function testXmlCarriesTheSameTotals(): void {
		$file = $this->generator()->generate(['administrationId' => 'adm-1', 'period' => '2026-03'], 'xml');

		$this->assertSame('iv3-2026-03.xml', $file->fileName);
		$this->assertSame('text/xml', $file->mimeType);
		$this->assertSame('xml', $file->format);

		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($file->content));
		$root = $dom->documentElement;
		$this->assertSame('Iv3Rapportage', $root->nodeName);
		$this->assertSame('2026-03', $root->getAttribute('period'));
		$this->assertSame('adm-1', $root->getAttribute('administration'));
		$this->assertSame('EUR', $root->getAttribute('valuta'));
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $root->getAttribute('opgesteld'));

		$rows = [];
		foreach ((new DOMXPath($dom))->query('/Iv3Rapportage/Taakveld') as $field) {
			$rows[] = [
				$field->getAttribute('code'),
				$field->getElementsByTagName('Lasten')->item(0)->textContent,
				$field->getElementsByTagName('Baten')->item(0)->textContent,
				$field->getElementsByTagName('Saldo')->item(0)->textContent,
			];
		}

		$this->assertSame(
			[
				['0.0', '40.00', '0.00', '-40.00'],
				['0.1', '110.00', '25.50', '-84.50'],
				['7.2', '0.00', '300.00', '300.00'],
			],
			$rows
		);
	}//end testXmlCarriesTheSameTotals()

	/**
	 * An unreadable ledger is logged and gives an empty report.
	 *
	 * @return void
	 */
	public function testAnUnreadableLedgerGivesAnEmptyReport(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$logger = $this->createMock(LoggerInterface::class);
		$warnings = [];
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);

		$file = (new Iv3ReportGenerator(container: $container, logger: $logger))->generate(['period' => 'Q1/2026'], 'csv');

		$this->assertSame("taskField,expenses,revenue,saldo\n", $file->content);
		$this->assertSame('iv3-Q12026.csv', $file->fileName);
		$this->assertSame(
			[
				'Shillinq report generator: could not load Account: no openregister',
				'Shillinq report generator: could not load GLLine: no openregister',
			],
			$warnings
		);
	}//end testAnUnreadableLedgerGivesAnEmptyReport()

	/**
	 * The report type and its formats.
	 *
	 * @return void
	 */
	public function testTypeAndFormats(): void {
		$this->assertSame('iv3', Iv3ReportGenerator::reportType());
		$this->assertSame(['xml', 'csv'], Iv3ReportGenerator::supportedFormats());
	}//end testTypeAndFormats()

	/**
	 * A generator over the in-memory ledger.
	 *
	 * @return Iv3ReportGenerator
	 */
	private function generator(): Iv3ReportGenerator {
		$store = new InMemoryObjectServiceStub(data: self::DATA);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($store);

		return new Iv3ReportGenerator(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end generator()
}//end class
