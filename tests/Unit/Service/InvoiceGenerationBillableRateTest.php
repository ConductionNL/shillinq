<?php

/**
 * Unit tests for the per-hour rate of a time and material invoice draft:
 * each hour is priced from the rate card on the person's assignment, and a
 * draft holding an hour without a rate is refused, naming the person and the
 * date, before anything is written (REQ-BRPH-002, REQ-BRPH-005).
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
 * @spec openspec/changes/sales-billable-rate-per-hour/tasks.md#task-3.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Kernel\Exception\UnratedHoursException;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\BillableRateService;
use OCA\Shillinq\Service\BillingModelEngine;
use OCA\Shillinq\Service\InvoiceDeduplicationService;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\RateCardResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\RetainerResolver;
use OCA\Shillinq\Service\Usage\BilledReadings;
use OCA\Shillinq\Service\UsageRatingCalculator;
use OCA\Shillinq\Service\VATCalculationService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The generator over an in-memory register holding the Renovatie Kade 12 example.
 *
 * @covers \OCA\Shillinq\Service\InvoiceGenerationService
 * @covers \OCA\Shillinq\Kernel\Exception\UnratedHoursException
 * @uses   \OCA\Shillinq\Service\BillableRateService
 * @uses   \OCA\Shillinq\Service\RateCardResolver
 * @uses   \OCA\Shillinq\Service\RetainerResolver
 * @uses   \OCA\Shillinq\Service\BillingModelEngine
 * @uses   \OCA\Shillinq\Service\InvoiceDeduplicationService
 * @uses   \OCA\Shillinq\Service\VATCalculationService
 * @uses   \OCA\Shillinq\Service\UsageRatingCalculator
 * @uses   \OCA\Shillinq\Request\InvoiceGenerationRequest
 * @uses   \OCA\Shillinq\Service\BillableInvoiceRecords
 * @uses   \OCA\Shillinq\Service\InvoiceDraft
 * @uses   \OCA\Shillinq\Service\RegisterRecordFinder
 * @uses   \OCA\Shillinq\Service\RateQuery
 * @uses   \OCA\Shillinq\Service\EffectiveVersionPicker
 * @uses   \OCA\Shillinq\Service\StoredMoney
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class InvoiceGenerationBillableRateTest extends TestCase {
	/**
	 * The administration.
	 *
	 * @var string
	 */
	private const ADMIN = 'adm-1';

	/**
	 * The project.
	 *
	 * @var string
	 */
	private const PROJECT = 'proj-kade-12';

	/**
	 * The register.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The generator over the example register.
	 *
	 * @param array<int, array<string, mixed>> $userRates Extra user-tier schedules.
	 *
	 * @return InvoiceGenerationService
	 */
	private function service(array $userRates = []): InvoiceGenerationService {
		$schedule = static fn (string $id, string $tier, ?string $entity, float $rate, string $from = '2026-01-01', string $to = '2026-12-31'): array => [
			'id' => $id, 'versionId' => 'rcv-std', 'tier' => $tier, 'entityId' => $entity, 'rate' => $rate, 'unit' => 'hourly',
			'status' => 'active', 'effectiveDate' => $from, 'expiryDate' => $to, 'administrationId' => self::ADMIN,
		];
		$assignment = static fn (string $person, ?string $role): array => [
			'id' => 'pa-' . $person, 'administrationId' => self::ADMIN, 'personId' => $person, 'projectId' => self::PROJECT,
			'rateCardId' => 'RCT-STD', 'billingRoleId' => $role, 'startDate' => '2026-09-01',
		];
		$hour = static fn (string $id, string $person, float $hours, string $date = '2026-10-14'): array => [
			'id' => $id, 'administrationId' => self::ADMIN, 'personId' => $person, 'projectId' => self::PROJECT, 'hours' => $hours, 'date' => $date,
		];

		if ($userRates === []) {
			$userRates = [$schedule('rs-alice', 'user', 'user-alice', 130)];
		}

		$this->store = new InMemoryObjectServiceStub(
			[
				'CustomerMaster' => [['id' => 'cust-het-anker', 'administrationId' => self::ADMIN, 'legalName' => 'Woningcorporatie Het Anker']],
				'UrenRegistratie' => [
					$hour('te-alice', 'user-alice', 6),
					$hour('te-bram', 'user-bram', 4.5),
					$hour('te-dana', 'user-dana', 1),
				],
				'ProjectAssignment' => [$assignment('user-alice', 'role-senior'), $assignment('user-bram', 'role-senior')],
				'RateCardTemplate' => [['id' => 'rct-std', 'templateId' => 'RCT-STD', 'administrationId' => self::ADMIN]],
				'RateCardVersion' => [
					['id' => 'rcv-std', 'templateId' => 'RCT-STD', 'status' => 'active', 'effectiveDate' => '2026-01-01', 'administrationId' => self::ADMIN],
				],
				'RateSchedule' => array_merge(
					[
						$schedule('rs-blended', 'blended', null, 85),
						$schedule('rs-senior', 'role', 'role-senior', 120),
						$schedule('rs-het-anker', 'client', 'cust-het-anker', 95),
					],
					$userRates
				),
			]
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$logger = new NullLogger();

		return new InvoiceGenerationService(
			$appConfig,
			$logger,
			new RateCardResolver($container, $appConfig, $logger),
			new RetainerResolver($container, $appConfig, $logger),
			new BillingModelEngine(),
			new InvoiceDeduplicationService($container, $appConfig, $logger),
			new VATCalculationService(),
			new UsageRatingCalculator(),
			objectService: $this->store,
			billedReadings: $this->createMock(BilledReadings::class),
			billableRates: new BillableRateService(
				$this->store,
				$this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
				$this->createMock(ITimeFactory::class)
			),
		);
	}//end service()

	/**
	 * A time and material request for the given hours, naming no rate card.
	 *
	 * @param array<int, string> $timeEntryIds The ticked hours.
	 *
	 * @return InvoiceGenerationRequest
	 */
	private static function request(array $timeEntryIds): InvoiceGenerationRequest {
		return new InvoiceGenerationRequest(
			administrationId: self::ADMIN,
			billingModel: 't_and_m',
			customerId: 'cust-het-anker',
			fromDate: '2026-10-01',
			toDate: '2026-10-31',
			timeEntryIds: $timeEntryIds,
			projectId: self::PROJECT,
		);
	}//end request()

	/**
	 * The objects written to one schema, in write order.
	 *
	 * @param string $schema The schema.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function saved(string $schema): array {
		return array_values(
			array_map(
				static fn (array $save): array => $save['object'],
				array_filter($this->store->saved, static fn (array $save): bool => $save['schema'] === $schema)
			)
		);
	}//end saved()

	/**
	 * The written invoice lines.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lines(): array {
		return $this->saved(schema: 'BillableInvoiceLine');
	}//end lines()

	/**
	 * Alice's 6 hours at EUR 130 and Bram's 4.5 hours at EUR 120 total EUR 1,320
	 * and each line keeps its own rate.
	 *
	 * @return void
	 */
	public function testTwoPeopleOnOneInvoiceKeepTheirOwnRates(): void {
		$invoice = $this->service()->draftInvoice(self::request(['te-alice', 'te-bram']));

		self::assertSame(1320.0, $invoice['netAmount']);
		$lines = $this->lines();
		self::assertCount(2, $lines);
		self::assertSame([13000, 12000], array_map(static fn (array $line): int => $line['rateApplied']['rateCents'], $lines));
		self::assertSame(['user', 'role'], array_map(static fn (array $line): string => $line['rateApplied']['tier'], $lines));
		self::assertSame(['rs-alice', 'rs-senior'], array_map(static fn (array $line): string => $line['rateApplied']['scheduleId'], $lines));
		self::assertSame([6.0, 4.5], array_map(static fn (array $line): float => (float)$line['billableUnits'], $lines));
		self::assertCount(2, $this->saved(schema: 'RateRecord'));
	}//end testTwoPeopleOnOneInvoiceKeepTheirOwnRates()

	/**
	 * An hour of a person without an assignment refuses the draft, naming her
	 * and the date, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnratedHourRefusesTheDraft(): void {
		$service = $this->service();
		try {
			$service->draftInvoice(self::request(['te-alice', 'te-dana']));
			self::fail('The draft was written with an unrated hour.');
		} catch (UnratedHoursException $e) {
			self::assertSame('No rate for user-dana on 14 October 2026.', $e->getMessage());
			self::assertSame(
				[['person' => 'user-dana', 'date' => '2026-10-14', 'reason' => 'user-dana is not assigned to this project']],
				$e->getHours()
			);
		}

		self::assertSame([], $this->saved(schema: 'BillableInvoice'));
		self::assertSame([], $this->lines());
	}//end testAnUnratedHourRefusesTheDraft()

	/**
	 * A rate change before the draft does not matter to the hour's date: the
	 * hour is priced by the schedule covering its own date.
	 *
	 * @return void
	 */
	public function testEachHourIsPricedByTheScheduleOfItsDate(): void {
		$service = $this->service(
			[
				[
					'id' => 'rs-alice-old', 'versionId' => 'rcv-std', 'tier' => 'user', 'entityId' => 'user-alice', 'rate' => 130, 'unit' => 'hourly',
					'status' => 'active', 'effectiveDate' => '2026-01-01', 'expiryDate' => '2026-10-15', 'administrationId' => self::ADMIN,
				],
				[
					'id' => 'rs-alice-new', 'versionId' => 'rcv-std', 'tier' => 'user', 'entityId' => 'user-alice', 'rate' => 140, 'unit' => 'hourly',
					'status' => 'active', 'effectiveDate' => '2026-10-16', 'expiryDate' => '2026-12-31', 'administrationId' => self::ADMIN,
				],
			]
		);

		$invoice = $service->draftInvoice(self::request(['te-alice']));

		self::assertSame(780.0, $invoice['netAmount']);
		self::assertSame('rs-alice-old', $this->lines()[0]['rateApplied']['scheduleId']);
	}//end testEachHourIsPricedByTheScheduleOfItsDate()
}//end class
