<?php

/**
 * Unit tests for the CBSExportService (REQ-CBS-004 / REQ-CBS-005 / REQ-CBS-006).
 *
 * Covers the pure-input methods of the IV3-extended CBS submission pipeline:
 *   - getMappingFromSettings() — default RGS table + JSON override + bad-override fallback
 *   - aggregateLines()         — first-match-range bucketing + non-negative integer cents
 *   - generateIV3Json()        — envelope shape + sha256 checksum
 *   - validateSubmission()     — kvk + tax-id + period + duplicate-range detection
 *
 * The OpenRegister-driven `generateSubmission()` is exercised by the integration
 * test against a live OR instance; this suite covers the deterministic logic.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-cbs-bestanden-extended/spec.md#req-cbse-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\CBSExportService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * CBSExportService unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CBSExportServiceTest extends TestCase {
	/**
	 * @var ContainerInterface
	 */
	private ContainerInterface $container;

	/**
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the system under test with default mocks.
	 *
	 * @return CBSExportService
	 */
	private function svc(): CBSExportService {
		return new CBSExportService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end svc()

	/**
	 * REQ-CBS-005: default RGS 4xxx–9xxx mapping is returned when no override
	 * is stored in app config.
	 *
	 * @return void
	 */
	public function testGetMappingFromSettingsReturnsDefaultWhenNoOverride(): void {
		$this->appConfig->method('getValueString')
			->with(Application::APP_ID, 'cbs_account_mapping_adm-1', '')
			->willReturn('');

		$mapping = $this->svc()->getMappingFromSettings(administrationId: 'adm-1');

		self::assertCount(7, $mapping);
		self::assertSame('4000', $mapping[0]['start']);
		self::assertSame('Revenue', $mapping[0]['classification']);
		self::assertSame('1000', $mapping[0]['lineNumber']);
		self::assertSame('9999', $mapping[6]['end']);
		self::assertSame('OtherExpenses', $mapping[6]['classification']);

	}//end testGetMappingFromSettingsReturnsDefaultWhenNoOverride()

	/**
	 * REQ-CBS-005: a valid JSON override replaces the default mapping verbatim.
	 *
	 * @return void
	 */
	public function testGetMappingFromSettingsUsesValidJsonOverride(): void {
		$override = json_encode(
			[
				['start' => '3000', 'end' => '3999', 'classification' => 'CustomRevenue', 'lineNumber' => '0900'],
				['start' => '4000', 'end' => '4999', 'classification' => 'Revenue',       'lineNumber' => '1000'],
			],
			JSON_THROW_ON_ERROR,
		);

		$this->appConfig->method('getValueString')->willReturn($override);

		$mapping = $this->svc()->getMappingFromSettings(administrationId: 'adm-2');

		self::assertCount(2, $mapping);
		self::assertSame('CustomRevenue', $mapping[0]['classification']);
		self::assertSame('3000', $mapping[0]['start']);

	}//end testGetMappingFromSettingsUsesValidJsonOverride()

	/**
	 * REQ-CBS-005: malformed JSON falls back to the canonical default mapping
	 * and emits a warning log.
	 *
	 * @return void
	 */
	public function testGetMappingFromSettingsFallsBackOnInvalidJson(): void {
		$this->appConfig->method('getValueString')->willReturn('{not-json');
		$this->logger->expects(self::once())->method('warning');

		$mapping = $this->svc()->getMappingFromSettings(administrationId: 'adm-3');

		self::assertCount(7, $mapping);
		self::assertSame('Revenue', $mapping[0]['classification']);

	}//end testGetMappingFromSettingsFallsBackOnInvalidJson()

	/**
	 * REQ-CBS-005: entries missing required fields are skipped; if the override
	 * collapses to an empty mapping the default table is returned.
	 *
	 * @return void
	 */
	public function testGetMappingFromSettingsFallsBackWhenAllEntriesInvalid(): void {
		$override = json_encode(
			[
				['start' => '', 'end' => '4999', 'classification' => 'X', 'lineNumber' => '1'],
				['start' => '4000', 'classification' => 'X', 'lineNumber' => '1'],
			],
			JSON_THROW_ON_ERROR,
		);

		$this->appConfig->method('getValueString')->willReturn($override);

		$mapping = $this->svc()->getMappingFromSettings(administrationId: 'adm-4');

		self::assertCount(7, $mapping);

	}//end testGetMappingFromSettingsFallsBackWhenAllEntriesInvalid()

	/**
	 * REQ-CBS-004: GL lines are bucketed by first matching range; amount is
	 * accumulated as absolute integer cents and glLineCount is incremented.
	 *
	 * @return void
	 */
	public function testAggregateLinesBucketsByFirstMatchingRange(): void {
		$mapping = [
			['start' => '4000', 'end' => '4999', 'classification' => 'Revenue',        'lineNumber' => '1000'],
			['start' => '5000', 'end' => '5999', 'classification' => 'OperatingCosts', 'lineNumber' => '2000'],
		];

		$glLines = [
			['accountNumber' => '4100', 'amount' => 1000.00, 'side' => 'credit'],
			['accountNumber' => '4200', 'amount' => 500.50,  'side' => 'credit'],
			['accountNumber' => '5100', 'amount' => 250.25,  'side' => 'debit'],
			['accountNumber' => '8000', 'amount' => 999.99,  'side' => 'credit'], // out of mapping range
		];

		$rows = $this->svc()->aggregateLines(
			glLines: $glLines,
			accounts: [],
			mapping: $mapping,
		);

		self::assertCount(2, $rows);

		$revenue = $rows[0];
		self::assertSame('Revenue', $revenue['classification']);
		self::assertSame('1000', $revenue['lineNumber']);
		self::assertSame('4000', $revenue['accountRangeStart']);
		self::assertSame('4999', $revenue['accountRangeEnd']);
		self::assertSame(150050, $revenue['aggregatedAmount']); // 1000 + 500.50 → cents
		self::assertSame(2, $revenue['glLineCount']);

		$costs = $rows[1];
		self::assertSame('OperatingCosts', $costs['classification']);
		self::assertSame(25025, $costs['aggregatedAmount']);
		self::assertSame(1, $costs['glLineCount']);

	}//end testAggregateLinesBucketsByFirstMatchingRange()

	/**
	 * REQ-CBS-004: empty input + empty mapping yields an empty aggregation row
	 * list — guards against array-key drift.
	 *
	 * @return void
	 */
	public function testAggregateLinesReturnsEmptyForNoLines(): void {
		$rows = $this->svc()->aggregateLines(glLines: [], accounts: [], mapping: []);
		self::assertSame([], $rows);

	}//end testAggregateLinesReturnsEmptyForNoLines()

	/**
	 * REQ-CBS-004: a missing accountNumber is silently skipped; the bucketer
	 * never throws on malformed lines.
	 *
	 * @return void
	 */
	public function testAggregateLinesSkipsMissingAccountNumber(): void {
		$mapping = [
			['start' => '4000', 'end' => '4999', 'classification' => 'Revenue', 'lineNumber' => '1000'],
		];

		$rows = $this->svc()->aggregateLines(
			glLines: [['amount' => 100.00]],
			accounts: [],
			mapping: $mapping,
		);

		self::assertSame([], $rows);

	}//end testAggregateLinesSkipsMissingAccountNumber()

	/**
	 * Pins a whole generation: the log line, the reads in order, only the
	 * period's posted lines aggregated, the submission, its lines and the IV3
	 * reference and checksum written back, field for field.
	 *
	 * @return void
	 */
	public function testGenerateSubmissionWritesTheSubmissionItsLinesAndTheIv3Reference(): void {
		$store = new class {

			/**
			 * Rows keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $rows = [];

			/**
			 * Every read and write as "find:<schema>" or "save:<schema>".
			 *
			 * @var array<int,string>
			 */
			public array $calls = [];

			/**
			 * The schema being addressed.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Address a register.
			 *
			 * @param mixed $register The register.
			 *
			 * @return self
			 */
			public function setRegister($register): self {
				return $this;
			}//end setRegister()

			/**
			 * Address a schema.
			 *
			 * @param mixed $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema($schema): self {
				$this->schema = (string)$schema;
				return $this;
			}//end setSchema()

			/**
			 * Find rows matching every filter.
			 *
			 * @param array<string,mixed> $config The query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config = []): array {
				$this->calls[] = 'find:' . $this->schema;
				$filters = (array)($config['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
						static fn (array $row): bool => array_intersect_assoc($filters, $row) === $filters
					)
				);
			}//end findAll()

			/**
			 * Save a row, giving a new one an id.
			 *
			 * @param array<string,mixed> $object The row.
			 * @param mixed $register The register.
			 * @param mixed $schema The schema.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null): array {
				$schema = (string)$schema;
				$this->calls[] = 'save:' . $schema;
				if (isset($object['id']) === false) {
					$object['id'] = $schema . '-' . (count($this->rows[$schema] ?? []) + 1);
				}

				$this->rows[$schema][] = $object;
				return $object;
			}//end saveObject()
		};
		$store->rows = [
			'Account' => [['id' => 'acc-1', 'administrationId' => 'adm-1', 'lifecycleState' => 'active', 'accountNumber' => '4000']],
			'CBSSubmission' => [['id' => 'old', 'submissionNumber' => 'CBS-2026-001']],
			'GLTransaction' => [
				['id' => 'tx-1', 'administrationId' => 'adm-1', 'state' => 'posted', 'postingDate' => '2026-02-10'],
				['id' => 'tx-2', 'administrationId' => 'adm-1', 'state' => 'posted', 'postingDate' => '2026-04-01'],
				['uuid' => 'tx-3', 'administrationId' => 'adm-1', 'state' => 'posted', 'postingDate' => '2026-03-31'],
				['id' => 'tx-4', 'administrationId' => 'adm-1', 'state' => 'draft', 'postingDate' => '2026-02-11'],
			],
			'GLLine' => [
				['transactionId' => 'tx-1', 'accountNumber' => '4100', 'amount' => 10.5],
				['transactionId' => 'tx-1', 'accountNumber' => '5200', 'amount' => 3],
				['transactionId' => 'tx-2', 'accountNumber' => '4100', 'amount' => 99],
				['transactionId' => 'tx-3', 'accountNumber' => '4999', 'amount' => 1.25],
				['transactionId' => 'tx-4', 'accountNumber' => '4100', 'amount' => 7],
			],
		];
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$this->logger->expects(self::once())->method('info')->with(
			'CBSExportService: generating submission',
			['administrationId' => 'adm-1', 'periodStart' => '2026-01-01', 'periodEnd' => '2026-03-31']
		);
		$service = new CBSExportService(appConfig: $this->appConfig, logger: $this->logger, objectService: new DuckObjectServiceAdapter($store));

		$final = $service->generateSubmission(
			administrationId: 'adm-1',
			periodStart: new \DateTimeImmutable('2026-01-01'),
			periodEnd: new \DateTimeImmutable('2026-03-31'),
			organization: ['legalName' => 'Korenbloem BV', 'kvkNumber' => '12345678']
		);

		self::assertSame(
			[
				'find:Account',
				'find:GLTransaction',
				'find:GLLine',
				'find:GLLine',
				'find:CBSSubmission',
				'save:CBSSubmission',
				'save:CBSLine',
				'save:CBSLine',
				'save:CBSSubmission',
			],
			$store->calls
		);
		$submission = [
			'submissionNumber' => 'CBS-2026-002',
			'reportingPeriodStartDate' => '2026-01-01',
			'reportingPeriodEndDate' => '2026-03-31',
			'organizationLegalName' => 'Korenbloem BV',
			'kvkNumber' => '12345678',
			'taxIdentificationNumber' => '',
			'administrationId' => 'adm-1',
			'status' => 'draft',
			'submissionDate' => null,
			'currency' => 'EUR',
			'description' => 'Generated by CBSExportService for period 2026-01-01 — 2026-03-31',
			'id' => 'CBSSubmission-2',
		];
		self::assertSame($submission, $store->rows['CBSSubmission'][1]);
		$line = static fn (string $id, string $classification, string $number, string $start, string $end, int $amount, int $count): array => [
			'cbsSubmissionId' => 'CBSSubmission-2',
			'cbsLineClassification' => $classification,
			'cbsLineNumber' => $number,
			'accountRangeStart' => $start,
			'accountRangeEnd' => $end,
			'aggregatedAmount' => $amount,
			'glLineCount' => $count,
			'currency' => 'EUR',
			'description' => 'Aggregated GL lines for ' . $classification,
			'id' => $id,
		];
		$lines = [
			$line('CBSLine-1', 'Revenue', '1000', '4000', '4999', 1175, 2),
			$line('CBSLine-2', 'OperatingCosts', '2000', '5000', '5999', 300, 1),
		];
		self::assertSame($lines, $store->rows['CBSLine']);

		$iv3 = $service->generateIV3Json(submission: $submission, lines: $lines);
		self::assertSame(
			$submission + [
				'iv3FileUri' => 'shillinq://cbs-submissions/CBS-2026-002/iv3.json',
				'iv3Checksum' => 'sha256:' . hash('sha256', json_encode($iv3, JSON_THROW_ON_ERROR)),
			],
			$final
		);
		self::assertSame($final, $store->rows['CBSSubmission'][2]);

	}//end testGenerateSubmissionWritesTheSubmissionItsLinesAndTheIv3Reference()

	/**
	 * REQ-CBS-006: IV3 JSON envelope carries format, version, generation
	 * timestamp, submission metadata, line items, and a sha256 checksum.
	 *
	 * @return void
	 */
	public function testGenerateIV3JsonProducesEnvelopeWithChecksum(): void {
		$submission = [
			'submissionNumber' => 'CBS-2026-Q1',
			'reportingPeriodStartDate' => '2026-01-01',
			'reportingPeriodEndDate' => '2026-03-31',
			'organizationLegalName' => 'Acme BV',
			'kvkNumber' => '12345678',
			'taxIdentificationNumber' => 'NL123456789B01',
		];
		$lines = [
			[
				'cbsLineClassification' => 'Revenue',
				'cbsLineNumber' => '1000',
				'accountRangeStart' => '4000',
				'accountRangeEnd' => '4999',
				'aggregatedAmount' => 150050,
				'currency' => 'EUR',
			],
		];

		$envelope = $this->svc()->generateIV3Json(submission: $submission, lines: $lines);

		self::assertSame(CBSExportService::IV3_FORMAT, $envelope['format']);
		self::assertSame(CBSExportService::IV3_VERSION, $envelope['version']);
		self::assertArrayHasKey('generatedAt', $envelope);
		self::assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$envelope['generatedAt'],
		);
		self::assertSame('CBS-2026-Q1', $envelope['submission']['submissionNumber']);
		self::assertSame('Acme BV', $envelope['submission']['organization']['legalName']);
		self::assertCount(1, $envelope['lines']);
		self::assertSame(150050, $envelope['lines'][0]['amount']);
		self::assertStringStartsWith('sha256:', $envelope['checksum']);
		self::assertSame(64, strlen(substr($envelope['checksum'], 7)));

	}//end testGenerateIV3JsonProducesEnvelopeWithChecksum()

	/**
	 * REQ-CBS-006: an empty `lines` list is a valid envelope shape (preserves
	 * the empty-period reporting case); checksum still computes.
	 *
	 * @return void
	 */
	public function testGenerateIV3JsonHandlesEmptyLines(): void {
		$envelope = $this->svc()->generateIV3Json(
			submission: [
				'submissionNumber' => 'CBS-2026-Q2',
				'reportingPeriodStartDate' => '2026-04-01',
				'reportingPeriodEndDate' => '2026-06-30',
				'organizationLegalName' => 'Empty BV',
				'kvkNumber' => '87654321',
				'taxIdentificationNumber' => 'NL987654321B02',
			],
			lines: [],
		);

		self::assertSame([], $envelope['lines']);
		self::assertStringStartsWith('sha256:', $envelope['checksum']);

	}//end testGenerateIV3JsonHandlesEmptyLines()

	/**
	 * REQ-CBS-006: invalid KVK is reported by validateSubmission.
	 *
	 * @return void
	 */
	public function testValidateSubmissionRejectsMalformedKvk(): void {
		$result = $this->svc()->validateSubmission(submission: [
			'id' => 'sub-1',
			'kvkNumber' => '12',
			'taxIdentificationNumber' => 'NL123456789B01',
			'reportingPeriodStartDate' => '2026-01-01',
			'reportingPeriodEndDate' => '2026-03-31',
		]);

		self::assertFalse($result['valid']);
		self::assertContains('kvkNumber must match the pattern ^[0-9]{8}$', $result['errors']);

	}//end testValidateSubmissionRejectsMalformedKvk()

	/**
	 * REQ-CBS-006: invalid tax-identification-number is reported.
	 *
	 * @return void
	 */
	public function testValidateSubmissionRejectsMalformedTaxId(): void {
		$result = $this->svc()->validateSubmission(submission: [
			'id' => 'sub-1',
			'kvkNumber' => '12345678',
			'taxIdentificationNumber' => 'XX123',
			'reportingPeriodStartDate' => '2026-01-01',
			'reportingPeriodEndDate' => '2026-03-31',
		]);

		self::assertFalse($result['valid']);
		self::assertContains(
			'taxIdentificationNumber must match the pattern ^NL[0-9]{9}B[0-9]{2}$',
			$result['errors'],
		);

	}//end testValidateSubmissionRejectsMalformedTaxId()

	/**
	 * REQ-CBS-006: a Dutch BTW-id is NL, nine digits, B and two digits.
	 *
	 * @return void
	 */
	public function testValidateSubmissionAcceptsARealBtwId(): void {
		$result = $this->svc()->validateSubmission(submission: [
			'id' => 'sub-1',
			'kvkNumber' => '34365416',
			'taxIdentificationNumber' => 'NL814022818B01',
			'reportingPeriodStartDate' => '2026-01-01',
			'reportingPeriodEndDate' => '2026-03-31',
		]);

		foreach ($result['errors'] as $error) {
			self::assertStringNotContainsString('taxIdentificationNumber', $error);
		}

	}//end testValidateSubmissionAcceptsARealBtwId()

	/**
	 * REQ-CBS-006: missing reporting period is rejected.
	 *
	 * @return void
	 */
	public function testValidateSubmissionRejectsMissingPeriod(): void {
		$result = $this->svc()->validateSubmission(submission: [
			'id' => 'sub-1',
			'kvkNumber' => '12345678',
			'taxIdentificationNumber' => 'NL123456789B01',
			'reportingPeriodStartDate' => '',
			'reportingPeriodEndDate' => '',
		]);

		self::assertFalse($result['valid']);
		self::assertContains(
			'reportingPeriodStartDate and reportingPeriodEndDate are required',
			$result['errors'],
		);

	}//end testValidateSubmissionRejectsMissingPeriod()

}//end class
