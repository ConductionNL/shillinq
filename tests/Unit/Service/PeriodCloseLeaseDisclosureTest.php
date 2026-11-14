<?php

/**
 * Closing a period stores its IFRS 16 lease disclosure table (REQ-LD-008).
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-lease-disclosures/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\LeaseAmortizationCalculator;
use OCA\Shillinq\Service\LeaseDisclosureService;
use OCA\Shillinq\Service\PeriodCloseService;
use OCA\Shillinq\Service\SuspenseAgeingService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The year closes over a register with three active leases, one ended lease
 * and another administration's lease; the disclosures page then has the
 * year's table, once, even after a reopen and a second close.
 */
#[CoversClass(PeriodCloseService::class)]
#[CoversClass(LeaseDisclosureService::class)]
#[UsesClass(LeaseAmortizationCalculator::class)]
class PeriodCloseLeaseDisclosureTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The app config.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Seed the year and the leases.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectServiceStub(
			[
				'FiscalPeriod' => [
					[
						'id' => 'fp-2026',
						'periodId' => '2026',
						'administrationId' => 'adm-1',
						'fiscalYear' => 2026,
						'state' => 'closing',
						'taskChecklistItems' => [],
						'reopenedHistory' => [],
					],
				],
				'LeaseContract' => [
					$this->lease(slug: 'car', assetClass: 'vehicle', months: 36, payment: 1000.0, admin: 'adm-1'),
					$this->lease(slug: 'office', assetClass: 'real-estate', months: 60, payment: 2500.0, admin: 'adm-1'),
					$this->lease(slug: 'van', assetClass: 'vehicle', months: 24, payment: 800.0, admin: 'adm-1'),
					['status' => 'terminated'] + $this->lease(slug: 'old', assetClass: 'vehicle', months: 12, payment: 500.0, admin: 'adm-1'),
					$this->lease(slug: 'foreign', assetClass: 'real-estate', months: 12, payment: 9999.0, admin: 'adm-2'),
				],
				'LeaseDisclosureTable' => [],
			]
		);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
	}//end setUp()

	/**
	 * A capitalised lease.
	 *
	 * @param string $slug       The lease slug.
	 * @param string $assetClass Its asset class.
	 * @param int    $months     The non-cancellable term.
	 * @param float  $payment    The monthly payment.
	 * @param string $admin      The administration.
	 *
	 * @return array<string,mixed>
	 */
	private function lease(string $slug, string $assetClass, int $months, float $payment, string $admin): array {
		return [
			'@self' => ['slug' => $slug],
			'assetClass' => $assetClass,
			'status' => 'active',
			'classification' => 'IFRS16-capitalised',
			'nonCancellableTermMonths' => $months,
			'paymentFrequency' => 'monthly',
			'paymentTiming' => 'in-arrears',
			'basePaymentAmount' => $payment,
			'ibrPercent' => 4.0,
			'administrationId' => $admin,
			'extensionOptions' => [],
		];
	}//end lease()

	/**
	 * The period-close service, with or without disclosures wired.
	 *
	 * @param LeaseDisclosureService|null $disclosures The disclosure service.
	 * @param LoggerInterface|null        $logger      The logger.
	 *
	 * @return PeriodCloseService
	 */
	private function periodClose(?LeaseDisclosureService $disclosures, ?LoggerInterface $logger = null): PeriodCloseService {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn(true);
		$ageing = $this->createMock(SuspenseAgeingService::class);
		$ageing->method('hasUnresolvedItems')->willReturn(false);

		return new PeriodCloseService(
			appConfig: $this->appConfig,
			groupManager: $groups,
			suspenseAgeing: $ageing,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			objectService: $this->store,
			leaseDisclosures: $disclosures,
		);
	}//end periodClose()

	/**
	 * The disclosure service over the store.
	 *
	 * @return LeaseDisclosureService
	 */
	private function disclosures(): LeaseDisclosureService {
		return new LeaseDisclosureService(
			appConfig: $this->appConfig,
			calculator: new LeaseAmortizationCalculator(),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->store,
		);
	}//end disclosures()

	/**
	 * The stored disclosure tables.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function tables(): array {
		return array_values(array_map(static fn ($row): array => (array)$row, $this->store->setSchema('LeaseDisclosureTable')->findAll()));
	}//end tables()

	/**
	 * Closing the year stores its table with the maturity analysis of the
	 * administration's three active leases.
	 *
	 * @return void
	 */
	public function testClosingTheYearStoresItsDisclosureTable(): void {
		$closed = $this->periodClose(disclosures: $this->disclosures())->closePeriod('2026', 'adm-1', 'alice');

		$this->assertSame('closed', $closed['state']);
		$tables = $this->tables();
		$this->assertCount(1, $tables);
		$this->assertSame('2026', $tables[0]['fiscalPeriod']);
		$this->assertSame('adm-1', $tables[0]['administrationId']);
		$this->assertTrue($tables[0]['materializedAtPeriodClose']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $tables[0]['generatedAt']);
		// Undiscounted: 36 × 1,000 + 60 × 2,500 + 24 × 800 for the three active leases only.
		$this->assertEqualsWithDelta(36000.0 + 150000.0 + 19200.0, array_sum($tables[0]['maturityAnalysis']), 1.0);
		$this->assertEqualsWithDelta(4.0, $tables[0]['weightedAverageIbrByClass']['real-estate'], 0.01);
	}//end testClosingTheYearStoresItsDisclosureTable()

	/**
	 * Reopening and closing again replaces the year's table rather than adding a second one.
	 *
	 * @return void
	 */
	public function testClosingAgainReplacesTheTable(): void {
		$service = $this->periodClose(disclosures: $this->disclosures());
		$service->closePeriod('2026', 'adm-1', 'alice');
		$first = $this->tables()[0]['id'];

		$service->reopenPeriod('2026', 'adm-1', 'late invoice', 'alice');
		$service->closePeriod('2026', 'adm-1', 'alice');

		$tables = $this->tables();
		$this->assertCount(1, $tables);
		$this->assertSame($first, $tables[0]['id']);
	}//end testClosingAgainReplacesTheTable()

	/**
	 * A disclosure that cannot be written is logged and the close still stands.
	 *
	 * @return void
	 */
	public function testAFailedDisclosureIsLoggedAndTheCloseStands(): void {
		$disclosures = $this->createMock(LeaseDisclosureService::class);
		$disclosures->expects($this->once())->method('materialiseForPeriod')->with('adm-1', '2026')
			->willThrowException(new RuntimeException('register offline'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'PeriodCloseService: the lease disclosure table could not be stored for the closed period',
			['administrationId' => 'adm-1', 'periodId' => '2026', 'exception' => 'register offline']
		);

		$closed = $this->periodClose(disclosures: $disclosures, logger: $logger)->closePeriod('2026', 'adm-1', 'alice');

		$this->assertSame('closed', $closed['state']);
		$this->assertSame('closed', $this->store->setSchema('FiscalPeriod')->findAll()[0]['state']);
	}//end testAFailedDisclosureIsLoggedAndTheCloseStands()

	/**
	 * A refused close stores no table.
	 *
	 * @return void
	 */
	public function testARefusedCloseStoresNoTable(): void {
		$service = $this->periodClose(disclosures: $this->disclosures());
		$service->closePeriod('2026', 'adm-1', 'alice');

		try {
			$service->closePeriod('2026', 'adm-1', 'alice');
			$this->fail('A closed period was closed again.');
		} catch (\OCA\Shillinq\Kernel\Exception\PeriodCloseException $e) {
			$this->assertSame('Period is not in a closable state', $e->getMessage());
		}

		$this->assertCount(1, $this->tables());
	}//end testARefusedCloseStoresNoTable()
}//end class
