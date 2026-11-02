<?php

/**
 * Golden record of the XAF and SAF-T audit files and the management letter body.
 *
 * Each scenario feeds the generator one data set and compares the produced
 * document (XML text, or the management letter's section blocks), every
 * object-service read and every log line with
 * tests/Unit/Reporting/fixtures/audit-file-report-golden.json. The creation
 * date is recorded as TODAY. SHILLINQ_GOLDEN_RECORD=1 rewrites the record.
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

use OCA\Shillinq\Reporting\Generator\ManagementLetterReportGenerator;
use OCA\Shillinq\Reporting\Generator\SaftReportGenerator;
use OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator;
use OCA\Shillinq\Reporting\ReportSection;
use OCA\Shillinq\Service\RuleAuditService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * Audit file and management letter golden tests.
 *
 * @covers \OCA\Shillinq\Reporting\Generator\XafAuditfileGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\SaftReportGenerator
 * @covers \OCA\Shillinq\Reporting\Generator\ManagementLetterReportGenerator
 * @uses   \OCA\Shillinq\Reporting\Generator\AbstractDocumentReportGenerator
 * @uses   \OCA\Shillinq\Reporting\GeneratedFile
 * @uses   \OCA\Shillinq\Reporting\ReportSection
 * @uses   \OCA\Shillinq\Reporting\ReportTable
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AuditFileReportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/audit-file-report-golden.json';

	/**
	 * Every audit file generator against every data set.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function fileScenarios(): array {
		$cases = [];
		foreach (['xaf', 'saft'] as $type) {
			foreach (['full', 'noAdministration', 'empty', 'loadFails'] as $data) {
				$cases[$type . ' ' . $data] = [$type, $data];
			}
		}

		return $cases;

	}//end fileScenarios()

	/**
	 * Every management letter audit shape.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function letterScenarios(): array {
		$cases = [];
		foreach (['full', 'clean', 'nothingChecked', 'oddShapes', 'unavailable', 'noAdministration'] as $audit) {
			$cases['letter ' . $audit] = [$audit];
		}

		return $cases;

	}//end letterScenarios()

	/**
	 * The audit file matches the golden record.
	 *
	 * @param string $type The generator.
	 * @param string $data The data set.
	 *
	 * @return void
	 */
	#[DataProvider('fileScenarios')]
	public function testAuditFileMatchesGolden(string $type, string $data): void {
		$objects = new GoldenReportObjects(rowsBySchema: self::rows(data: $data), fails: $data === 'loadFails');
		$logger  = new GoldenReportLogger();
		$generator = new XafAuditfileGenerator(new GoldenReportContainer(service: $objects), $logger);
		if ($type === 'saft') {
			$generator = new SaftReportGenerator(new GoldenReportContainer(service: $objects), $logger);
		}

		$context = ['administrationId' => 'adm-1', 'period' => '2025-03'];
		if ($data === 'noAdministration') {
			$context = ['period' => '2025'];
		}

		$file = $generator->generate($context, 'xml');

		$this->assertGolden(
			key: $type . ' ' . $data,
			recorded: [
				'fileName' => $file->fileName,
				'content'  => explode("\n", str_replace(gmdate('Y-m-d'), 'TODAY', $file->content)),
				'reads'    => $objects->reads,
				'log'      => $logger->lines,
			]
		);

	}//end testAuditFileMatchesGolden()

	/**
	 * The management letter body matches the golden record.
	 *
	 * @param string $audit The audit shape.
	 *
	 * @return void
	 */
	#[DataProvider('letterScenarios')]
	public function testLetterMatchesGolden(string $audit): void {
		$service = $this->createStub(RuleAuditService::class);
		$contexts = [];
		$service->method('audit')->willReturnCallback(
			static function (array $context) use ($audit, &$contexts): array {
				$contexts[] = $context;
				return self::audit(shape: $audit);
			}
		);

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($audit, $service): object {
				if ($audit === 'unavailable') {
					throw new RuntimeException('no ' . $id);
				}

				return $service;
			}
		);

		$context = ['reportType' => 'management-letter', 'period' => '2025-03', 'administrationId' => 'adm-1'];
		if ($audit === 'noAdministration') {
			$context = ['reportType' => 'management-letter', 'period' => '2025-03'];
		}

		$section = new ReportSection();
		$build   = new ReflectionMethod(ManagementLetterReportGenerator::class, 'build');
		$build->invoke(new ManagementLetterReportGenerator($container), $section, $context);

		$blocks = json_decode((string) preg_replace('/Gegenereerd: [0-9: -]+ UTC/', 'Gegenereerd: NOW', (string) json_encode($section->toArray())), true);
		$this->assertGolden(key: 'letter ' . $audit, recorded: ['blocks' => $blocks, 'auditContexts' => $contexts]);

	}//end testLetterMatchesGolden()

	/**
	 * Compare one recording with the golden file (or record it).
	 *
	 * @param string $key The scenario key.
	 * @param array<string, mixed> $recorded What the scenario did.
	 *
	 * @return void
	 */
	private function assertGolden(string $key, array $recorded): void {
		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		}

		if (getenv('SHILLINQ_GOLDEN_RECORD') === '1') {
			$golden[$key] = $recorded;
			ksort($golden);
			$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
			file_put_contents(self::GOLDEN, json_encode($golden, $flags) . "\n");
		}

		self::assertSame(
			json_encode($golden[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION),
			json_encode($recorded, JSON_PRESERVE_ZERO_FRACTION)
		);

	}//end assertGolden()

	/**
	 * One audit report shape for the management letter.
	 *
	 * @param string $shape The audit shape.
	 *
	 * @return array<string, mixed>
	 */
	private static function audit(string $shape): array {
		$full = [
			'catalogueVersion' => '2026.1',
			'corpusTotal' => 812,
			'machineCheckable' => 400,
			'enforceableRules' => 377,
			'coveragePct' => 94.25,
			'types' => [
				'ARInvoice' => ['checked' => 12, 'withViolations' => 3, 'violations' => 5],
				'GLTransaction' => ['checked' => 40, 'withViolations' => 0],
			],
			'objectsChecked' => 52,
			'objectsCompliant' => 49,
			'objectsWithViolations' => 3,
			'violationsBySeverity' => ['mandatory' => 3, 'conditional' => 1, 'recommended' => 1],
			'topViolatedRules' => [['ruleId' => 'BR-CO-10', 'count' => 3], ['ruleId' => 'BR-16'], ['count' => 1]],
		];

		return match ($shape) {
			'clean' => ['objectsChecked' => 7, 'objectsCompliant' => 7, 'objectsWithViolations' => 0, 'coveragePct' => '12.5'],
			'nothingChecked' => [],
			'oddShapes' => [
				'objectsChecked' => 3,
				'objectsCompliant' => 1,
				'violationsBySeverity' => 'none',
				'types' => ['Payee' => 'skip', 'Account' => ['violations' => 2]],
				'topViolatedRules' => ['skip', ['ruleId' => 'R-1', 'count' => '4']],
			],
			default => $full,
		};

	}//end audit()

	/**
	 * The rows per schema for one data set.
	 *
	 * @param string $data The data set.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function rows(string $data): array {
		if ($data === 'empty') {
			return [];
		}

		return [
			'Account' => [
				['accountNumber' => '1000', 'name' => 'Kas', 'accountType' => 'asset', 'administrationId' => 'adm-1', 'parentAccountNumber' => '10'],
				['code' => '4000', 'description' => 'Kosten', 'accountType' => 'expense', 'administrationId' => 'adm-1'],
				['code' => '8000', 'name' => 'Omzet', 'accountType' => 'revenue', 'administrationId' => 'adm-1'],
				['name' => 'Geen nummer', 'administrationId' => 'adm-1'],
				['accountNumber' => '1100', 'name' => 'Bank', 'accountType' => 'liability', 'administrationId' => 'adm-2'],
				['accountNumber' => '0100', 'name' => 'Zonder administratie'],
			],
			'CustomerMaster' => [
				['customerId' => 'C-1', 'name' => 'Klant BV', 'kvkNumber' => '123', 'vatNumber' => 'NL1', 'email' => 'a@b.nl', 'phone' => '010', 'administrationId' => 'adm-1'],
				['customerNumber' => 'C-2', 'legalName' => 'Klant Twee', 'vatID' => 'NL2', 'telephone' => '020', 'administrationId' => 'adm-1'],
				['customerId' => 'C-3', 'tradingName' => 'Derde', 'vatId' => 'NL3', 'email' => 'c@d.nl', 'administrationId' => 'adm-1'],
				['name' => 'Zonder id', 'administrationId' => 'adm-1'],
				['customerId' => 'C-9', 'name' => 'Andere administratie', 'administrationId' => 'adm-2'],
			],
			'Payee' => [
				['vendorNumber' => 'S-1', 'tradeName' => 'Leverancier', 'administrationId' => 'adm-1'],
				['vendorNumber' => 'S-2', 'administrationId' => 'adm-1'],
				['name' => 'Geen nummer', 'administrationId' => 'adm-1'],
			],
			'GLTransaction' => [
				['id' => 't-1', 'transactionNumber' => 'M-1', 'description' => 'Memoriaal', 'postingDate' => '2025-03-03', 'postingKind' => 'M', 'administrationId' => 'adm-1'],
				['@self' => ['id' => 't-2'], 'valueDate' => '2025-03-04', 'administrationId' => 'adm-1'],
				[
					'transactionNumber' => 'M-3',
					'administrationId' => 'adm-1',
					'lines' => [
						['lineNumber' => 1, 'accountNumber' => '1000', 'amount' => '12.5', 'side' => 'debit'],
						['id' => 'l-x', 'accountNumber' => '8000', 'amount' => 12.5, 'side' => 'credit', 'description' => 'Omzet'],
					],
				],
				['id' => 't-4', 'lines' => [], 'administrationId' => 'adm-1'],
				['id' => 't-9', 'transactionNumber' => 'X-9', 'administrationId' => 'adm-2'],
			],
			'GLLine' => [
				['transactionId' => 't-1', 'lineNumber' => 1, 'accountNumber' => '1000', 'amount' => 100, 'side' => 'debit', 'description' => 'Kas in'],
				['transactionId' => 't-1', 'id' => 'l-2', 'accountNumber' => '8000', 'amount' => '100.004', 'side' => 'credit'],
				['transactionId' => 't-2', 'lineNumber' => 1, 'accountNumber' => '4000', 'amount' => 'abc'],
				['transactionId' => 't-9', 'lineNumber' => 1, 'accountNumber' => '1100', 'amount' => 5, 'side' => 'debit'],
				['transactionId' => 'M-3', 'lineNumber' => 9, 'accountNumber' => '1000', 'amount' => 1, 'side' => 'debit'],
				['lineNumber' => 1, 'accountNumber' => '1000', 'amount' => 7, 'side' => 'debit'],
			],
		];

	}//end rows()
}//end class

/**
 * Object store double that records every read.
 */
final class GoldenReportObjects {

	/**
	 * Every read as schema plus the findAll config.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $reads = [];

	/**
	 * The active schema.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows by schema.
	 * @param bool $fails Whether every read throws.
	 */
	public function __construct(
		private readonly array $rowsBySchema,
		private readonly bool $fails,
	) {
	}//end __construct()

	/**
	 * Fluent register setter.
	 *
	 * @param string $register Register slug.
	 *
	 * @return static
	 */
	public function setRegister(string $register): static {
		return $this;
	}//end setRegister()

	/**
	 * Fluent schema setter.
	 *
	 * @param string $schema Schema name.
	 *
	 * @return static
	 */
	public function setSchema(string $schema): static {
		$this->schema = $schema;
		return $this;
	}//end setSchema()

	/**
	 * The rows of the active schema.
	 *
	 * @param array<string, mixed> $config The findAll config.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = []): array {
		$this->reads[] = ['schema' => $this->schema, 'config' => $config];
		if ($this->fails === true) {
			throw new RuntimeException('read failed for ' . $this->schema);
		}

		return ($this->rowsBySchema[$this->schema] ?? []);
	}//end findAll()
}//end class

/**
 * Container that resolves only the object service.
 */
final class GoldenReportContainer implements ContainerInterface {

	/**
	 * Constructor.
	 *
	 * @param GoldenReportObjects $service The object service double.
	 */
	public function __construct(private readonly GoldenReportObjects $service) {
	}//end __construct()

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service id.
	 *
	 * @return mixed
	 */
	public function get(string $id): mixed {
		return $this->service;
	}//end get()

	/**
	 * Whether a service exists.
	 *
	 * @param string $id Service id.
	 *
	 * @return bool
	 */
	public function has(string $id): bool {
		return true;
	}//end has()
}//end class

/**
 * Logger that records every line as level: message.
 */
final class GoldenReportLogger extends AbstractLogger {

	/**
	 * The recorded lines.
	 *
	 * @var array<int, string>
	 */
	public array $lines = [];

	/**
	 * Record one log line.
	 *
	 * @param mixed $level The level.
	 * @param string|Stringable $message The message.
	 * @param array<string, mixed> $context The context.
	 *
	 * @return void
	 */
	public function log($level, string|Stringable $message, array $context = []): void {
		$this->lines[] = $level . ': ' . $message;
	}//end log()
}//end class
