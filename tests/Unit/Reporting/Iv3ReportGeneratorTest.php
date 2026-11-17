<?php

/**
 * Unit tests for the Iv3 report generator.
 *
 * Iv3 totals the ledger per task field: a line's own task field, else its
 * account's, else `0.0`. Debit lines are expenses and the rest revenue,
 * eliminations are left out, and the totals render as CSV or as the Iv3 XML.
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

use OCA\Shillinq\Kernel\Model\GeneratedFile;
use OCA\Shillinq\Reporting\CostSource\ClassifiedSubjectCostSource;
use OCA\Shillinq\Reporting\CostSource\ReportPeriod;
use OCA\Shillinq\Reporting\CostSource\SubjectCostGrouping;
use OCA\Shillinq\Reporting\CostSource\SubjectCostReport;
use OCA\Shillinq\Reporting\CostSource\SubjectCostSourceConfiguration;
use OCA\Shillinq\Reporting\CostSource\TaskFieldRollup;
use OCA\Shillinq\Reporting\Generator\Iv3ReportGenerator;
use OCA\Shillinq\Service\SubjectCostAggregator;
use OCA\Shillinq\Service\SubjectCostService;
use OCA\Shillinq\Support\FleetAppId;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCA\Shillinq\Tests\Unit\Reporting\CostSource\CostSourceWorld;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Iv3 aggregation and rendering.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(Iv3ReportGenerator::class)]
#[UsesClass(GeneratedFile::class)]
#[UsesClass(SubjectCostReport::class)]
#[UsesClass(ClassifiedSubjectCostSource::class)]
#[UsesClass(SubjectCostGrouping::class)]
#[UsesClass(TaskFieldRollup::class)]
#[UsesClass(SubjectCostSourceConfiguration::class)]
#[UsesClass(ReportPeriod::class)]
#[UsesClass(SubjectCostService::class)]
#[UsesClass(SubjectCostAggregator::class)]
#[UsesClass(FleetAppId::class)]
#[UsesClass(ObjectIdentifier::class)]
final class Iv3ReportGeneratorTest extends TestCase {

	/**
	 * The findAll queries per schema.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * A container whose object service answers fixed rows per schema.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$rows = [
			'GLLine' => [
				['accountNumber' => '4000', 'amount' => '100.10', 'side' => 'debit'],
				['accountNumber' => '4000', 'amount' => 20, 'side' => 'credit'],
				['accountNumber' => '4000', 'taskField' => '7.1', 'amount' => 5, 'side' => 'debit'],
				['accountNumber' => '8000', 'amount' => 300, 'side' => 'credit'],
				['accountNumber' => '9999', 'amount' => 'x', 'side' => 'debit'],
				['accountNumber' => '8000', 'amount' => 999, 'side' => 'credit', 'eliminationFlag' => true],
				'junk',
			],
			'Account' => [
				['accountNumber' => '4000', 'taskField' => '0.4'],
				['code' => '8000', 'taskField' => '0.8'],
			],
		];
		$test = $this;
		$objectService = new class($rows, $test) {
			private string $schema = '';

			/**
			 * @param array<string, list<mixed>> $rows The rows.
			 * @param Iv3ReportGeneratorTest     $test The test, which records the queries.
			 */
			public function __construct(
				private readonly array $rows,
				private readonly Iv3ReportGeneratorTest $test,
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
				$this->test->record($this->schema, $config);
				return $this->rows[$this->schema] ?? [];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

		return $container;
	}//end container()

	/**
	 * Record one findAll query.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $config The query.
	 *
	 * @return void
	 */
	public function record(string $schema, array $config): void {
		$this->queries[$schema] = $config;
	}//end record()

	/**
	 * The type and formats it registers under.
	 *
	 * @return void
	 */
	public function testItIsTheIv3ReportInXmlAndCsv(): void {
		self::assertSame('iv3', Iv3ReportGenerator::reportType());
		self::assertSame(['xml', 'csv'], Iv3ReportGenerator::supportedFormats());
	}//end testItIsTheIv3ReportInXmlAndCsv()

	/**
	 * The CSV totals per task field, sorted, with eliminations left out.
	 *
	 * @return void
	 */
	public function testTheCsvTotalsEachTaskField(): void {
		$generator = new Iv3ReportGenerator($this->container(), $this->createMock(LoggerInterface::class));
		$file = $generator->generate(['period' => '2026-Q1', 'administrationId' => 'adm-1'], 'csv');

		self::assertSame('iv3-2026-Q1.csv', $file->fileName);
		self::assertSame('text/csv', $file->mimeType);
		self::assertSame('csv', $file->format);
		self::assertSame(
			"taskField,expenses,revenue,saldo\n"
			. "0.0,0.00,0.00,0.00\n"
			. "0.4,100.10,20.00,-80.10\n"
			. "0.8,0.00,300.00,300.00\n"
			. "7.1,5.00,0.00,-5.00\n",
			$file->content
		);
		self::assertSame(['limit' => 10000, 'filters' => ['administrationId' => 'adm-1']], $this->queries['Account']);
		self::assertSame(['limit' => 10000, 'filters' => ['administrationId' => 'adm-1', 'periodId' => '2026-Q1']], $this->queries['GLLine']);
	}//end testTheCsvTotalsEachTaskField()

	/**
	 * Any other format is the Iv3 XML with the same totals.
	 *
	 * @return void
	 */
	public function testTheXmlCarriesTheTotalsAndTheContext(): void {
		$generator = new Iv3ReportGenerator($this->container(), $this->createMock(LoggerInterface::class));
		$file = $generator->generate(['period' => '2026-Q1', 'administrationId' => 'adm-1'], 'xml');

		self::assertSame('iv3-2026-Q1.xml', $file->fileName);
		self::assertSame('text/xml', $file->mimeType);
		self::assertSame('xml', $file->format);

		$xml = simplexml_load_string($file->content);
		self::assertNotFalse($xml);
		self::assertSame('Iv3Rapportage', $xml->getName());
		self::assertSame('2026-Q1', (string)$xml['period']);
		self::assertSame('adm-1', (string)$xml['administration']);
		self::assertSame('EUR', (string)$xml['valuta']);
		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)$xml['opgesteld']);

		$rows = [];
		foreach ($xml->Taakveld as $field) {
			$rows[(string)$field['code']] = [(string)$field->Lasten, (string)$field->Baten, (string)$field->Saldo];
		}

		self::assertSame(
			[
				'0.0' => ['0.00', '0.00', '0.00'],
				'0.4' => ['100.10', '20.00', '-80.10'],
				'0.8' => ['0.00', '300.00', '300.00'],
				'7.1' => ['5.00', '0.00', '-5.00'],
			],
			$rows
		);
	}//end testTheXmlCarriesTheTotalsAndTheContext()

	/**
	 * Without a context no filter is sent; a store that fails is logged and the report is empty.
	 *
	 * @return void
	 */
	public function testAFailingStoreGivesAnEmptyReport(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::exactly(2))->method('warning')->with(self::stringStartsWith('Shillinq report generator: could not load '));

		$file = (new Iv3ReportGenerator($container, $logger))->generate(['period' => 'Q1'], 'csv');

		self::assertSame("taskField,expenses,revenue,saldo\n", $file->content);
		self::assertSame('iv3-Q1.csv', $file->fileName);
	}//end testAFailingStoreGivesAnEmptyReport()
	/**
	 * Case costs are a section of their own: the GL lasten and baten per
	 * taakveld are identical with and without the cost source.
	 *
	 * @return void
	 */
	public function testCaseCostsDoNotChangeTheLedgerTotals(): void {
		$world = new CostSourceWorld($this);
		$world->rates = ['ann' => 10000];
		$world->caseType('t', 'Omgevingsvergunning', '0.4');
		$world->caseWithHours('c1', 't', [['ann', 5.0, '2026-02-01']]);
		$context = ['period' => '2026-Q1', 'administrationId' => 'adm-1'];

		$without = new Iv3ReportGenerator($this->container(), $this->createMock(LoggerInterface::class));
		$with = new Iv3ReportGenerator($this->container(), $this->createMock(LoggerInterface::class), $world->report());

		$plainCsv = $without->generate($context, 'csv')->content;
		self::assertSame(
			$plainCsv . "\nsubjectCostsPerTaskField,objects,hours,cost,incomplete,types\n0.4,1,5.00,500.00,0,\n",
			$with->generate($context, 'csv')->content
		);

		$stamp = static fn (string $xml): string => (string)preg_replace('/opgesteld="[^"]*"/', '', $xml);
		self::assertSame($stamp($without->generate($context, 'xml')->content), $stamp($with->generate($context, 'xml')->content));
		self::assertSame([], $without->subjectCostsPerTaskField($context));
		self::assertSame(50000, $with->subjectCostsPerTaskField($context)[0]['totals']['costCents']);
	}//end testCaseCostsDoNotChangeTheLedgerTotals()
}//end class
