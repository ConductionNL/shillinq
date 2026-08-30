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
			'taxIdentificationNumber must match the pattern ^NL[0-9]{10}B[0-9]{2}$',
			$result['errors'],
		);

	}//end testValidateSubmissionRejectsMalformedTaxId()

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
