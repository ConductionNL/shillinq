<?php

/**
 * Sales usage billing: import readings, rate them, bill them once.
 *
 * Runs over an in-memory register that answers the way OpenRegister does:
 * `findAll()` hands back entities, and a filter on `id` matches nothing. Every
 * payload written is checked against the real MeterReading fragment.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Usage
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

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Usage;

use DomainException;
use OCA\Shillinq\Lifecycle\Action\RateMeterReadingAction;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\BillingModelEngine;
use OCA\Shillinq\Service\InvoiceDeduplicationService;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RateCardResolver;
use OCA\Shillinq\Service\RetainerResolver;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\Usage\BilledReadings;
use OCA\Shillinq\Service\Usage\MeterReadingImportService;
use OCA\Shillinq\Service\Usage\MeterReadingRating;
use OCA\Shillinq\Service\UsageRatingCalculator;
use OCA\Shillinq\Service\VATCalculationService;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * REQ-USB-001 and REQ-USB-002.
 */
class SalesUsageBillingTest extends TestCase {

	private const ADMIN = 'adm-holding-nl';

	private const PLAN = 'a1b2c3d4-0000-4000-8000-000000000012';

	private const SEPT_A = 'a1b2c3d4-0000-4000-8000-0000000000a1';

	private const SEPT_B = 'a1b2c3d4-0000-4000-8000-0000000000b2';

	private const BILLED = 'a1b2c3d4-0000-4000-8000-0000000000c3';

	private InMemoryObjectServiceStub $store;

	private LifecycleFaithfulTransitionEngine $engine;

	/**
	 * Seed the "Opslag per GB" plan and three readings for Hosting Noord.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$reading = [
			'administrationId' => self::ADMIN,
			'customerId'       => 'cust-hosting-noord',
			'resourceType'     => 'storage_gb',
			'unit'             => 'GB',
			'ratePlanId'       => self::PLAN,
			'periodStart'      => '2026-09-01',
			'periodEnd'        => '2026-09-30',
		];
		$this->store = new InMemoryObjectServiceStub(
			[
				'UsageRatePlan'       => [
					['id' => self::PLAN, 'administrationId' => self::ADMIN, 'name' => 'Opslag per GB', 'resourceType' => 'storage_gb', 'unit' => 'GB', 'ratingMethod' => 'flat', 'unitPriceCents' => 12, 'vatRate' => 21],
				],
				'MeterReading'        => [
					(['id' => self::SEPT_A, 'meterId' => 'm-1', 'quantity' => 250, 'status' => 'rated', 'ratedAmount' => 30.0] + $reading),
					(['id' => self::SEPT_B, 'meterId' => 'm-2', 'quantity' => 100, 'status' => 'rated', 'ratedAmount' => 12.0] + $reading),
					(['id' => self::BILLED, 'meterId' => 'm-3', 'quantity' => 10, 'status' => 'invoiced', 'ratedAmount' => 1.2, 'invoiceId' => 'inv-old'] + $reading),
				],
				'BillableInvoice'     => [],
				'BillableInvoiceLine' => [],
			],
			findAllRendersEntities: true,
			idFiltersMatchNothing: true
		);
		$this->engine = new LifecycleFaithfulTransitionEngine($this->store, ['MeterReading']);

	}//end setUp()

	/**
	 * A month of readings with one negative row: two land unrated, row 3 is refused by number.
	 *
	 * @return void
	 */
	public function testAnImportRefusesANegativeQuantityByItsRowNumber(): void {
		$before = count($this->store->setSchema('MeterReading')->findAll());
		$row = ['meterId' => 'm-9', 'customerId' => 'cust-hosting-noord', 'resourceType' => 'storage_gb', 'unit' => 'GB', 'periodStart' => '2026-10-01', 'periodEnd' => '2026-10-31'];

		$result = $this->importer()->import(
			administrationId: self::ADMIN,
			rows: [
				(['quantity' => '250'] + $row),
				(['quantity' => '80.5'] + $row),
				(['quantity' => '-5'] + $row),
			]
		);

		$this->assertCount(2, $result['created']);
		$this->assertSame([['row' => 3, 'reason' => 'The quantity is negative.']], $result['refused']);
		$all = $this->store->setSchema('MeterReading')->findAll();
		$this->assertCount(($before + 2), $all);
		foreach ($result['created'] as $id) {
			$created = $this->store->find(id: $id, schema: 'MeterReading')->getObject();
			$this->assertSame('unrated', $created['status']);
			$this->assertSame(self::PLAN, $created['ratePlanId'], 'the plan is found from the resource type');
			unset($created['id']);
			$this->assertSame([], RegisterSchema::errors('MeterReading', $created));
		}

	}//end testAnImportRefusesANegativeQuantityByItsRowNumber()

	/**
	 * A row without a customer or with an unreadable date is refused with its reason.
	 *
	 * @return void
	 */
	public function testAnImportRefusesMissingAndMalformedFields(): void {
		$result = $this->importer()->import(
			administrationId: self::ADMIN,
			rows: [
				['resourceType' => 'storage_gb', 'quantity' => '1', 'periodStart' => '2026-10-01', 'periodEnd' => '2026-10-31'],
				['customerId' => 'c', 'resourceType' => 'storage_gb', 'quantity' => 'lots', 'periodStart' => '2026-10-01', 'periodEnd' => '2026-10-31'],
				['customerId' => 'c', 'resourceType' => 'storage_gb', 'quantity' => '1', 'periodStart' => '1 Oct', 'periodEnd' => '2026-10-31'],
				['customerId' => 'c', 'resourceType' => 'storage_gb', 'quantity' => '1', 'periodStart' => '2026-10-31', 'periodEnd' => '2026-10-01'],
			]
		);

		$this->assertSame([], $result['created']);
		$this->assertSame(
			[
				['row' => 1, 'reason' => 'The customer is missing.'],
				['row' => 2, 'reason' => 'The quantity is not a number.'],
				['row' => 3, 'reason' => 'The period start is not a date (YYYY-MM-DD).'],
				['row' => 4, 'reason' => 'The period ends before it starts.'],
			],
			$result['refused']
		);

	}//end testAnImportRefusesMissingAndMalformedFields()

	/**
	 * Rating 250 GB at EUR 0.12 a GB keeps EUR 30.00 on the reading; the fragment declares the action.
	 *
	 * @return void
	 */
	public function testRatingAReadingKeepsItsAmount(): void {
		$reading = ['administrationId' => self::ADMIN, 'customerId' => 'cust-hosting-noord', 'resourceType' => 'storage_gb', 'quantity' => 250, 'ratePlanId' => self::PLAN, 'periodStart' => '2026-09-01', 'periodEnd' => '2026-09-30', 'status' => 'rated'];

		$rated = (new RateMeterReadingAction($this->rating()))->execute($reading, ($reading + ['status' => 'unrated']), [], 'rate');

		$this->assertSame(30.0, $rated['ratedAmount']);
		$this->assertSame([], RegisterSchema::errors('MeterReading', $rated));
		$actions = array_column((RegisterSchema::schema(slug: 'MeterReading')['x-openregister-lifecycle']['transitions']['rate']['actions'] ?? []), 'action');
		$this->assertContains(RateMeterReadingAction::class, $actions);

	}//end testRatingAReadingKeepsItsAmount()

	/**
	 * A reading whose plan is missing or in another administration cannot be rated.
	 *
	 * @return void
	 */
	public function testAReadingWithoutAPlanInItsAdministrationIsNotRated(): void {
		$this->expectException(DomainException::class);
		$reading = ['administrationId' => 'adm-other', 'customerId' => 'c', 'resourceType' => 'storage_gb', 'quantity' => 1, 'ratePlanId' => self::PLAN];

		(new RateMeterReadingAction($this->rating()))->execute($reading, $reading, [], 'rate');

	}//end testAReadingWithoutAPlanInItsAdministrationIsNotRated()

	/**
	 * September usage: two usage lines of EUR 42.00 together, both readings invoiced on that invoice.
	 *
	 * @return void
	 */
	public function testAUsageInvoiceBillsTheReadingsAndMarksThemInvoiced(): void {
		$invoice = $this->generator()->draftInvoice($this->request(ids: [self::SEPT_A, self::SEPT_B]));

		$this->assertSame(42.0, $invoice['netAmount']);
		$lines = $this->store->setSchema('BillableInvoiceLine')->findAll();
		$this->assertCount(2, $lines);
		$invoiceId = (string)$invoice['id'];
		foreach ([self::SEPT_A, self::SEPT_B] as $id) {
			$reading = $this->store->find(id: $id, schema: 'MeterReading')->getObject();
			$this->assertSame('invoiced', $reading['status']);
			$this->assertSame($invoiceId, $reading['invoiceId']);
			unset($reading['id']);
			$this->assertSame([], RegisterSchema::errors('MeterReading', $reading));
		}

		$this->assertSame(['invoice', 'invoice'], array_column($this->engine->ran, 'action'));

	}//end testAUsageInvoiceBillsTheReadingsAndMarksThemInvoiced()

	/**
	 * An invoiced reading passed again is refused before anything is saved.
	 *
	 * @return void
	 */
	public function testAnInvoicedReadingIsRefused(): void {
		try {
			$this->generator()->draftInvoice($this->request(ids: [self::SEPT_A, self::BILLED]));
			$this->fail('an invoiced reading was billed again');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('Conflict', $e->getMessage());
		}

		$this->assertSame([], $this->store->setSchema('BillableInvoice')->findAll());
		$this->assertSame('rated', $this->store->find(id: self::SEPT_A, schema: 'MeterReading')->getObject()['status']);

	}//end testAnInvoicedReadingIsRefused()

	/**
	 * A usage request for September.
	 *
	 * @param array<int,string> $ids The reading ids.
	 *
	 * @return InvoiceGenerationRequest
	 */
	private function request(array $ids): InvoiceGenerationRequest {
		return new InvoiceGenerationRequest(
			administrationId: self::ADMIN,
			billingModel: 'usage',
			customerId: 'cust-hosting-noord',
			fromDate: '2026-09-01',
			toDate: '2026-09-30',
			meterReadingIds: $ids
		);

	}//end request()

	/**
	 * The importer over the store.
	 *
	 * @return MeterReadingImportService
	 */
	private function importer(): MeterReadingImportService {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		return new MeterReadingImportService($this->store, $this->settings(), $l10n);

	}//end importer()

	/**
	 * The rating over the store and the real calculator.
	 *
	 * @return MeterReadingRating
	 */
	private function rating(): MeterReadingRating {
		return new MeterReadingRating($this->store, $this->settings(), new UsageRatingCalculator());

	}//end rating()

	/**
	 * Settings answering the register slug.
	 *
	 * @return SettingsService
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return $settings;

	}//end settings()

	/**
	 * The real generator with the real engine, calculator and VAT, over the store.
	 *
	 * @return InvoiceGenerationService
	 */
	private function generator(): InvoiceGenerationService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => ($id === ObjectTransitionRunner::ENGINE_CLASS) ? $this->engine : $this->store
		);
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
			billedReadings: new BilledReadings($this->store, new ObjectTransitionRunner(container: $container), $this->settings()),
		);

	}//end generator()
}//end class
