<?php

/**
 * Unit tests for LeaseDisclosureService.
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
 * @spec openspec/changes/bookkeeping-ifrs-16-lease/specs/bookkeeping-lease-disclosures/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\LeaseAmortizationCalculator;
use OCA\Shillinq\Service\LeaseDisclosureService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the IFRS 16 disclosure aggregation: RoU by class, current/non-current
 * liability split, undiscounted maturity analysis (REQ-LD-002), liability-weighted
 * IBR per class (REQ-LD-003), and the exemption expense breakdown (REQ-LE-003).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class LeaseDisclosureServiceTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

	}//end setUp()

	/**
	 * Build the service with an ObjectService stub returning the given leases.
	 *
	 * @param array<int,array<string,mixed>> $leases LeaseContract records.
	 *
	 * @return LeaseDisclosureService
	 */
	private function buildService(array $leases): LeaseDisclosureService {
		$stub = new class($leases) {

			/**
			 * Lease records.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $leases;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $leases Lease records.
			 */
			public function __construct(array $leases) {
				$this->leases = $leases;
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
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Return leases matching the administration filter.
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$admin = ($params['filters']['administrationId'] ?? null);
				if ($admin === null) {
					return $this->leases;
				}

				return array_values(
					array_filter(
						$this->leases,
						static fn (array $lease): bool => ($lease['administrationId'] ?? null) === $admin
					)
				);
			}//end findAll()
		};

		$this->container->method('get')->willReturn($stub);

		return new LeaseDisclosureService(
			appConfig: $this->appConfig,
			calculator: new LeaseAmortizationCalculator(),
			logger: $this->createMock(LoggerInterface::class),
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * A capitalised vehicle lease populates RoU-by-class and the liability split (REQ-LD-001).
	 *
	 * @return void
	 */
	public function testAggregatesCapitalisedLease(): void {
		$lease = [
			'@self' => ['slug' => 'lease-v'],
			'assetClass' => 'vehicle',
			'status' => 'active',
			'classification' => 'IFRS16-capitalised',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'ibrPercent' => 4.0,
			'administrationId' => 'adm-1',
			'extensionOptions' => [],
		];

		$table = $this->buildService([$lease])->generateForPeriod('adm-1', '2026');

		self::assertSame('2026', $table['fiscalPeriod']);
		self::assertTrue($table['materializedAtPeriodClose']);

		// RoU asset depreciates to zero over the term, so closing RoU is 0.
		self::assertSame(0.0, $table['totalRouAssetByClass']['vehicle']);
		self::assertGreaterThan(0.0, $table['totalRouDepreciationInPeriod']);

		// First 12 months' principal is current, remainder non-current (REQ-LD-001 51(d)).
		self::assertGreaterThan(0.0, $table['totalLeaseLiabilityCurrent']);
		self::assertGreaterThan(0.0, $table['totalLeaseLiabilityNoncurrent']);

		// Undiscounted maturity total ≈ 36 × 1,000 = 36,000 (REQ-LD-002).
		$maturityTotal = array_sum($table['maturityAnalysis']);
		self::assertEqualsWithDelta(36000.0, $maturityTotal, 1.0);

		// Weighted-average IBR for the only vehicle lease is its own IBR (REQ-LD-003).
		self::assertEqualsWithDelta(4.0, $table['weightedAverageIbrByClass']['vehicle'], 0.01);

	}//end testAggregatesCapitalisedLease()

	/**
	 * Exempt leases contribute straight-line expense, no RoU / liability (REQ-LE-003).
	 *
	 * @return void
	 */
	public function testExemptLeaseExpenseOnly(): void {
		$lease = [
			'@self' => ['slug' => 'lease-st'],
			'assetClass' => 'IT-hardware',
			'status' => 'active',
			'classification' => 'short-term-exempt',
			'nonCancellableTermMonths' => 12,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 850.0,
			'ibrPercent' => 0.0,
			'administrationId' => 'adm-1',
			'extensionOptions' => [],
		];

		$table = $this->buildService([$lease])->generateForPeriod('adm-1', '2026');

		// 12 × 850 = 10,200 short-term expense; no RoU / liability.
		self::assertEqualsWithDelta(10200.0, $table['totalShortTermLeaseExpense'], 0.01);
		self::assertSame(0.0, $table['totalLeaseLiabilityCurrent']);
		self::assertSame(0.0, $table['totalLeaseLiabilityNoncurrent']);

	}//end testExemptLeaseExpenseOnly()

	/**
	 * Liability-weighted IBR averages two leases of the same class (REQ-LD-003).
	 *
	 * @return void
	 */
	public function testWeightedAverageIbrAcrossTwoLeases(): void {
		$leaseA = [
			'@self' => ['slug' => 'a'],
			'assetClass' => 'vehicle',
			'status' => 'active',
			'classification' => 'IFRS16-capitalised',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'ibrPercent' => 4.0,
			'administrationId' => 'adm-2',
			'extensionOptions' => [],
		];
		$leaseB = $leaseA;
		$leaseB['@self'] = ['slug' => 'b'];
		$leaseB['ibrPercent'] = 6.0;

		$table = $this->buildService([$leaseA, $leaseB])->generateForPeriod('adm-2', '2026');

		// Equal opening liabilities → simple average of 4% and 6% = 5%.
		self::assertEqualsWithDelta(5.0, $table['weightedAverageIbrByClass']['vehicle'], 0.05);

	}//end testWeightedAverageIbrAcrossTwoLeases()

	/**
	 * Only active / modified leases are aggregated; drafts are excluded (REQ-LD-001).
	 *
	 * @return void
	 */
	public function testDraftLeasesExcluded(): void {
		$draft = [
			'@self' => ['slug' => 'draft'],
			'assetClass' => 'vehicle',
			'status' => 'draft',
			'classification' => 'IFRS16-capitalised',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'ibrPercent' => 4.0,
			'administrationId' => 'adm-3',
			'extensionOptions' => [],
		];

		$table = $this->buildService([$draft])->generateForPeriod('adm-3', '2026');
		self::assertSame(0.0, $table['totalLeaseLiabilityCurrent']);
		self::assertSame(0.0, $table['totalInterestExpense']);

	}//end testDraftLeasesExcluded()

	/**
	 * exportToCSV flattens the disclosure payload to header + section rows (task 10.3).
	 *
	 * @return void
	 */
	public function testExportToCsvFlattensPayload(): void {
		$lease = [
			'@self' => ['slug' => 'lease-v'],
			'assetClass' => 'vehicle',
			'status' => 'active',
			'classification' => 'IFRS16-capitalised',
			'nonCancellableTermMonths' => 36,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => 1000.0,
			'ibrPercent' => 4.0,
			'administrationId' => 'adm-csv',
			'extensionOptions' => [],
		];

		$service = $this->buildService([$lease]);
		$table = $service->generateForPeriod('adm-csv', '2026');
		$csv = $service->exportToCSV($table);

		self::assertStringContainsString('section,label,value', $csv);
		self::assertStringContainsString('header,fiscalPeriod,2026', $csv);
		self::assertStringContainsString('rou-by-class,vehicle,', $csv);
		self::assertStringContainsString('maturity-analysis,lt1y,', $csv);
		self::assertStringContainsString('weighted-average-ibr,vehicle,', $csv);
		self::assertStringContainsString('totals,totalLeaseLiabilityCurrent,', $csv);
		self::assertStringEndsWith("\n", $csv);

	}//end testExportToCsvFlattensPayload()

	/**
	 * exportToCSV escapes commas / quotes per RFC 4180.
	 *
	 * @return void
	 */
	public function testExportToCsvEscapesQuotesAndCommas(): void {
		$service = $this->buildService([]);

		$csv = $service->exportToCSV(
			[
				'fiscalPeriod' => '2026',
				'administrationId' => 'adm-quote',
				'materializedAtPeriodClose' => true,
				'qualitativeNarrative' => 'Lessor "Acme, Inc." reviewed contracts.',
			]
		);

		self::assertStringContainsString('"Lessor ""Acme, Inc."" reviewed contracts."', $csv);

	}//end testExportToCsvEscapesQuotesAndCommas()

	/**
	 * exportDisclosureNoteToHtml renders a self-contained HTML document
	 * with the IFRS 16 sections (Task 10.2 skeleton).
	 *
	 * @return void
	 */
	public function testExportDisclosureNoteToHtmlRendersSections(): void {
		$service = $this->buildService([]);
		$disclosure = [
			'fiscalPeriod' => '2026',
			'administrationId' => 'adm-pdf',
			'closingRouByClass' => ['vehicle' => 1500.0, 'real-estate' => 2500.0],
			'maturityAnalysis' => ['lt1y' => 12000.0, '1to2y' => 12000.0],
			'weightedAverageIbrByClass' => ['vehicle' => 4.2],
			'totalLeaseLiabilityCurrent' => 9000.0,
			'totalLeaseLiabilityNoncurrent' => 30000.0,
			'totalInterestExpense' => 1500.0,
			'totalShortTermLeaseExpense' => 500.0,
			'totalLowValueLeaseExpense' => 0.0,
			'totalVariableLeaseExpense' => 0.0,
			'qualitativeNarrative' => 'The entity leases assets recognised under IFRS 16.',
		];

		$html = $service->exportDisclosureNoteToHtml($disclosure, 'en');

		self::assertStringContainsString('<!DOCTYPE html>', $html);
		self::assertStringContainsString('<html lang="en">', $html);
		self::assertStringContainsString('IFRS 16 Disclosure Note', $html);
		self::assertStringContainsString('Closing right-of-use asset by class', $html);
		self::assertStringContainsString('Undiscounted maturity analysis', $html);
		self::assertStringContainsString('Weighted-average incremental borrowing rate by class', $html);
		self::assertStringContainsString('Qualitative narrative', $html);
		self::assertStringContainsString('vehicle', $html);

	}//end testExportDisclosureNoteToHtmlRendersSections()

	/**
	 * Dutch language flag flips both the <html lang="nl"> attribute and
	 * the section headings to the Dutch labels.
	 *
	 * @return void
	 */
	public function testExportDisclosureNoteToHtmlDutch(): void {
		$service = $this->buildService([]);
		$html = $service->exportDisclosureNoteToHtml(
			[
				'fiscalPeriod' => '2026',
				'closingRouByClass' => ['vehicle' => 100.0],
				'maturityAnalysis' => ['lt1y' => 12000.0],
			],
			'nl'
		);

		self::assertStringContainsString('<html lang="nl">', $html);
		self::assertStringContainsString('IFRS 16-toelichting', $html);
		self::assertStringContainsString('Looptijdanalyse', $html);
		self::assertStringContainsString('Boekwaarde gebruiksrechtactivum', $html);

	}//end testExportDisclosureNoteToHtmlDutch()

	/**
	 * exportDisclosureNoteToPDF wraps the HTML in the docudesk-render envelope
	 * with status pending-pdf-pipeline (Task 10.2).
	 *
	 * @return void
	 */
	public function testExportDisclosureNoteToPDFEnvelope(): void {
		$service = $this->buildService([]);
		$envelope = $service->exportDisclosureNoteToPDF(
			['fiscalPeriod' => '2026', 'administrationId' => 'adm-pdf'],
			'en'
		);

		self::assertSame('lease-disclosure-note', $envelope['kind']);
		self::assertSame('pending-pdf-pipeline', $envelope['status']);
		self::assertSame('2026', $envelope['fiscalPeriod']);
		self::assertSame('en', $envelope['language']);
		self::assertStringContainsString('<!DOCTYPE html>', $envelope['html']);

	}//end testExportDisclosureNoteToPDFEnvelope()

	/**
	 * exportToXBRL produces the iXBRL skeleton with the IFRS-full facts
	 * the bookkeeping-sbr-xbrl-reporting integration consumes (Task 10.4).
	 *
	 * @return void
	 */
	public function testExportToXbrlSkeletonShape(): void {
		$service = $this->buildService([]);
		$payload = $service->exportToXBRL(
			[
				'fiscalPeriod' => '2026',
				'totalLeaseLiabilityCurrent' => 9000.0,
				'totalLeaseLiabilityNoncurrent' => 30000.0,
				'totalInterestExpense' => 1500.0,
				'totalShortTermLeaseExpense' => 500.0,
			]
		);

		self::assertSame('lease-disclosure-xbrl', $payload['kind']);
		self::assertSame('pending-sbr-xbrl-reporting', $payload['status']);
		self::assertSame('ctx-2026', $payload['contextRef']);
		self::assertSame('ifrs-full-2024', $payload['taxonomy']);
		self::assertArrayHasKey('ifrs-full:LeaseLiabilitiesCurrent', $payload['facts']);
		self::assertSame(9000.0, $payload['facts']['ifrs-full:LeaseLiabilitiesCurrent']);
		self::assertSame(1500.0, $payload['facts']['ifrs-full:InterestExpenseOnLeaseLiabilities']);

	}//end testExportToXbrlSkeletonShape()
}//end class
