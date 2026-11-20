<?php

/**
 * InvoiceGenerationRechargeTest
 *
 * The Het Anker invoice of sales-time-and-expense-billing, drafted and posted
 * through the generator over an in-memory register with real collaborators:
 * ticked pass-through items become lines of cost plus locked markup at the
 * main supply's VAT rate, a disbursement is outside the VAT base, an item
 * cannot be billed twice, and posting closes the claim.
 *
 * @category  Tests
 * @package   OCA\Shillinq\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/shillinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Shillinq\Request\InvoiceGenerationRequest;
use OCA\Shillinq\Service\Billing\PassThroughBilling;
use OCA\Shillinq\Service\BillableRateService;
use OCA\Shillinq\Service\BillingModelEngine;
use OCA\Shillinq\Service\InvoiceDeduplicationService;
use OCA\Shillinq\Service\InvoiceGenerationService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RateCardResolver;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\RetainerResolver;
use OCA\Shillinq\Service\Usage\BilledReadings;
use OCA\Shillinq\Service\UsageRatingCalculator;
use OCA\Shillinq\Service\VATCalculationService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Recharged pass-through items on a drafted and posted invoice.
 */
final class InvoiceGenerationRechargeTest extends TestCase {

	/**
	 * The register.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The claim transition runner.
	 *
	 * @var ObjectTransitionRunner&MockObject
	 */
	private ObjectTransitionRunner&MockObject $transitions;

	/**
	 * The generator.
	 *
	 * @var InvoiceGenerationService
	 */
	private InvoiceGenerationService $service;

	/**
	 * Seed Adviesbureau Kade: a posted pass-through claim with the train
	 * receipt (10 percent travel rule locked at submit), the parking receipt
	 * (no rule) and the land registry extract.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectServiceStub(
			[
				'UrenRegistratie' => [
					['id' => 'te-1', 'administrationId' => 'adm-kade', 'hours' => 12.5, 'date' => '2026-10-10', 'resourceType' => 'consultant',
						'hourlyRateOverride' => 95.0],
				],
				'ExpenseClaimEntry' => [
					['id' => 'c-vries', 'administrationId' => 'adm-kade', 'state' => 'posted', 'settlementMode' => 'pass-through',
						'glPassThroughTransactionId' => 'gl-1', 'receiptIds' => ['r-train', 'r-parking', 'r-kadaster']],
				],
				'Receipt' => [
					['id' => 'r-train', 'administrationId' => 'adm-kade', 'claimId' => 'c-vries', 'settlementMode' => 'pass-through',
						'linkedCustomerId' => 'anker', 'description' => 'Treinreis Utrecht-Zwolle v.v.', 'amountInBaseCurrency' => 42.80,
						'vatRate' => 9, 'markupRuleId' => 'rule-travel', 'markupRateApplied' => 0.10, 'markupAmountCalculated' => 4.28],
					['id' => 'r-parking', 'administrationId' => 'adm-kade', 'claimId' => 'c-vries', 'settlementMode' => 'pass-through',
						'linkedCustomerId' => 'anker', 'description' => 'Parkeren Zwolle', 'amountInBaseCurrency' => 12.50,
						'markupRuleId' => null, 'markupRateApplied' => null, 'markupAmountCalculated' => 0.0],
					['id' => 'r-kadaster', 'administrationId' => 'adm-kade', 'claimId' => 'c-vries', 'settlementMode' => 'pass-through',
						'linkedCustomerId' => 'anker', 'description' => 'Uittreksel Kadaster Kade 12', 'amountInBaseCurrency' => 3.70,
						'markupRuleId' => null, 'markupRateApplied' => null, 'markupAmountCalculated' => 0.0],
				],
			]
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
		$logger = new NullLogger();
		$this->transitions = $this->createMock(ObjectTransitionRunner::class);

		$this->service = new InvoiceGenerationService(
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
			passThrough: new PassThroughBilling(
				objectService: $this->store,
				location: $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
				transitions: $this->transitions,
				logger: $logger,
			),
			billableRates: new BillableRateService(
				objectService: $this->store,
				registerLocation: $this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
				timeFactory: $this->createMock(ITimeFactory::class)
			),
		);
	}//end setUp()

	/**
	 * October's hours with the ticked receipts; the extract as a disbursement.
	 *
	 * @param array<int,array<string,mixed>> $refs The ticked items.
	 *
	 * @return InvoiceGenerationRequest
	 */
	private static function request(array $refs): InvoiceGenerationRequest {
		return InvoiceGenerationRequest::fromArray(
			'adm-kade',
			[
				'billingModel' => 't_and_m',
				'customerId' => 'anker',
				'fromDate' => '2026-10-01',
				'toDate' => '2026-10-31',
				'timeEntryIds' => ['te-1'],
				'rateCardId' => 'rc-standaard',
				'expenseItemRefs' => $refs,
			]
		);
	}//end request()

	/**
	 * The lines the draft saved, keyed by source id.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function savedLines(): array {
		$lines = [];
		foreach ($this->store->saved as $saved) {
			if ($saved['schema'] === 'BillableInvoiceLine') {
				$lines[(string)$saved['object']['sourceId']] = $saved['object'];
			}
		}

		return $lines;
	}//end savedLines()

	/**
	 * The train at EUR 47.08 and parking at cost at 21 percent; the extract
	 * at 0 percent with the mention; the invoice records the items.
	 *
	 * @return void
	 */
	public function testTickedItemsAreRechargedWithMarkupAndTheirVatTreatment(): void {
		$invoice = $this->service->draftInvoice(
			self::request(
				[
					['schema' => 'Receipt', 'id' => 'r-train'],
					['schema' => 'Receipt', 'id' => 'r-parking'],
					['schema' => 'Receipt', 'id' => 'r-kadaster', 'disbursement' => true],
				]
			)
		);
		$lines = $this->savedLines();

		self::assertSame(47.08, $lines['r-train']['costAmount']);
		self::assertSame(21.0, $lines['r-train']['vatRate']);
		self::assertSame(9.89, $lines['r-train']['vatAmount']);
		self::assertSame(10.0, $lines['r-train']['markup']);
		self::assertSame(42.8, $lines['r-train']['recharge']['costAmount']);
		self::assertSame(4.28, $lines['r-train']['recharge']['markupAmount']);
		self::assertSame('main-supply', $lines['r-train']['recharge']['vatTreatment']);
		self::assertSame(12.5, $lines['r-parking']['costAmount']);
		self::assertSame(21.0, $lines['r-parking']['vatRate']);
		self::assertSame(3.7, $lines['r-kadaster']['costAmount']);
		self::assertSame(0.0, $lines['r-kadaster']['vatRate']);
		self::assertSame(0.0, $lines['r-kadaster']['vatAmount']);
		self::assertSame('Verschotten, buiten de btw-grondslag', $lines['r-kadaster']['recharge']['vatMention']);
		self::assertSame(
			[
				['schema' => 'Receipt', 'id' => 'r-train', 'claimId' => 'c-vries', 'disbursement' => false],
				['schema' => 'Receipt', 'id' => 'r-parking', 'claimId' => 'c-vries', 'disbursement' => false],
				['schema' => 'Receipt', 'id' => 'r-kadaster', 'claimId' => 'c-vries', 'disbursement' => true],
			],
			$invoice['expenseItemRefs']
		);
		self::assertSame([], $invoice['expenseIds']);
	}//end testTickedItemsAreRechargedWithMarkupAndTheirVatTreatment()

	/**
	 * An item on a draft invoice is refused on the next one; its sibling is not.
	 *
	 * @return void
	 */
	public function testAnItemCannotBeBilledTwiceButItsSiblingCan(): void {
		$this->service->draftInvoice(self::request([['schema' => 'Receipt', 'id' => 'r-train']]));

		$sibling = InvoiceGenerationRequest::fromArray(
			'adm-kade',
			['billingModel' => 'fixed_fee', 'fixedFeeCents' => 100, 'customerId' => 'anker', 'fromDate' => '2026-10-01', 'toDate' => '2026-10-31',
				'expenseItemRefs' => [['schema' => 'Receipt', 'id' => 'r-parking']]]
		);
		$second = $this->service->draftInvoice($sibling);
		self::assertSame('r-parking', $second['expenseItemRefs'][0]['id']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Conflict: source ids already invoiced');
		$this->service->draftInvoice(self::request([['schema' => 'Receipt', 'id' => 'r-train']]));
	}//end testAnItemCannotBeBilledTwiceButItsSiblingCan()

	/**
	 * An item whose whole claim an older invoice billed is refused.
	 *
	 * @return void
	 */
	public function testAnItemOfAClaimBilledWholeIsRefused(): void {
		$this->store->saveObject(
			object: ['id' => 'inv-old', 'administrationId' => 'adm-kade', 'status' => 'posted', 'expenseIds' => ['c-vries']],
			options: new ObjectSaveOptions(target: new ObjectFindTarget(schema: 'BillableInvoice'))
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Conflict: source ids already invoiced');
		$this->service->draftInvoice(self::request([['schema' => 'Receipt', 'id' => 'r-parking']]));
	}//end testAnItemOfAClaimBilledWholeIsRefused()

	/**
	 * Posting the invoice that holds every pass-through item closes the claim.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-3.3
	 */
	public function testPostingTheLastItemsMarksTheClaimInvoiced(): void {
		$invoice = $this->service->draftInvoice(
			self::request(
				[
					['schema' => 'Receipt', 'id' => 'r-train'],
					['schema' => 'Receipt', 'id' => 'r-parking'],
					['schema' => 'Receipt', 'id' => 'r-kadaster', 'disbursement' => true],
				]
			)
		);
		$this->transitions->expects(self::once())->method('run')->with('c-vries', 'markInvoiced');

		$posted = $this->service->postInvoice($invoice);

		self::assertSame('posted', $posted['status']);
	}//end testPostingTheLastItemsMarksTheClaimInvoiced()
}//end class
