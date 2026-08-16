<?php

/**
 * Unit tests for InvoiceFromBookingGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-31
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\InvoiceFromBookingGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for InvoiceFromBookingGuard per REQ-DI-002/003/004/005/008.
 *
 * Covers:
 * - canComplete happy path (deposit authorised, completedAt set, not invoiced).
 * - canComplete denied when completedAt missing (REQ-DI-002).
 * - canComplete denied when already invoiced — idempotency (REQ-DI-002).
 * - canComplete denied when required deposit not authorised (REQ-DI-002).
 * - canComplete allowed for no-deposit orders (REQ-DI-008).
 * - resolveDepositCreditCents from explicit amount and from DepositPayment.
 * - buildLineItems: service + negative 0%-VAT credit line (REQ-DI-003/004).
 * - buildLineItems: no credit line without a deposit (REQ-DI-008).
 * - computeTotals: net/VAT/gross math with deposit credit (REQ-DI-003/004).
 * - computeDueDate: default 14 days + month boundary (REQ-DI-005).
 * - sourceDocumentUri URN shape (REQ-DI-001).
 * - exception fail-closed (CWE-863).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class InvoiceFromBookingGuardTest extends TestCase {

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
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var InvoiceFromBookingGuard
	 */
	private InvoiceFromBookingGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new InvoiceFromBookingGuard(
			container: $this->container,
			appConfig: $this->appConfig,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Build a fluent ObjectService stub returning records by schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Map of schema → records.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $recordsBySchema): object {
		return new class($recordsBySchema) {
			/**
			 * Map of schema name → record arrays.
			 *
			 * @var array<string, array<int, array<string, mixed>>>
			 */
			private array $recordsBySchema;

			/**
			 * Currently active schema name.
			 *
			 * @var string
			 */
			private string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $recordsBySchema Records by schema.
			 */
			public function __construct(array $recordsBySchema) {
				$this->recordsBySchema = $recordsBySchema;

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
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return stubbed records for the current schema.
			 *
			 * @param array<string, mixed> $params Query parameters (unused in stub).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $params = []): array {
				return ($this->recordsBySchema[$this->currentSchema] ?? []);
			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * Stub the container to return the given ObjectService stub.
	 *
	 * @param object $objectService The ObjectService stub.
	 *
	 * @return void
	 */
	private function withObjectService(object $objectService): void {
		$this->container->method('get')->willReturn($objectService);

	}//end withObjectService()

	/**
	 * Build a base order, ready to complete, with overrides.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function order(array $overrides = []): array {
		return array_merge(
			[
				'administrationId' => 'adm-1',
				'orderId' => 'ord-1001',
				'customerId' => 'cust-5432',
				'description' => 'Studio Portrait Session (2-hour session)',
				'currency' => 'EUR',
				'basePrice' => 15000,
				'taxRate' => 21,
				'depositRequired' => true,
				'depositAmount' => 7500,
				'depositPaymentId' => 'dp-5001',
				'paymentTermsDays' => 14,
				'invoiceId' => null,
				'completedAt' => '2026-06-15T16:30:00Z',
				'state' => 'confirmed',
			],
			$overrides
		);

	}//end order()

	/**
	 * An authorised deposit record for dp-5001.
	 *
	 * @return array<string, mixed>
	 */
	private function authorizedDeposit(): array {
		return [
			'administrationId' => 'adm-1',
			'depositPaymentId' => 'dp-5001',
			'amount' => 7500,
			'currency' => 'EUR',
			'state' => 'authorized',
		];

	}//end authorizedDeposit()

	/**
	 * The `canComplete` is true for an order ready to invoice with an authorised deposit.
	 *
	 * @return void
	 */
	public function testCanCompleteHappyPath(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['DepositPayment' => [$this->authorizedDeposit()]])
		);

		$this->assertTrue($this->guard->canComplete('ord-1001', $this->order()));

	}//end testCanCompleteHappyPath()

	/**
	 * Regression guard (issue #503, 2026-07-23): when no $object is
	 * pre-supplied, canComplete must resolve the order via the CURRENT
	 * `BookingOrder` schema slug, not the stale `Order` slug the booking
	 * context was renamed away from in 07709a0f. Before the fix this
	 * fallback silently found nothing (schema `Order` no longer exists for
	 * bookings) and denied every completion reached through this path.
	 *
	 * @return void
	 */
	public function testCanCompleteResolvesOrderViaBookingOrderSchemaFallback(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'BookingOrder' => [$this->order()],
					'DepositPayment' => [$this->authorizedDeposit()],
				]
			)
		);

		$this->assertTrue($this->guard->canComplete('ord-1001'), 'fallback lookup must resolve against schema BookingOrder');

	}//end testCanCompleteResolvesOrderViaBookingOrderSchemaFallback()

	/**
	 * The `canComplete` is denied when completedAt is not set (REQ-DI-002).
	 *
	 * @return void
	 */
	public function testCanCompleteDeniedWhenCompletedAtMissing(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['DepositPayment' => [$this->authorizedDeposit()]])
		);

		$this->assertFalse($this->guard->canComplete('ord-1001', $this->order(['completedAt' => null])));

	}//end testCanCompleteDeniedWhenCompletedAtMissing()

	/**
	 * The `canComplete` is denied when the order is already invoiced — idempotency (REQ-DI-002).
	 *
	 * @return void
	 */
	public function testCanCompleteDeniedWhenAlreadyInvoiced(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['DepositPayment' => [$this->authorizedDeposit()]])
		);

		$this->assertFalse($this->guard->canComplete('ord-1001', $this->order(['invoiceId' => 'inv-final-2001'])));

	}//end testCanCompleteDeniedWhenAlreadyInvoiced()

	/**
	 * The `canComplete` is denied when the required deposit is not authorised (REQ-DI-002).
	 *
	 * @return void
	 */
	public function testCanCompleteDeniedWhenDepositNotAuthorised(): void {
		$pending = $this->authorizedDeposit();
		$pending['state'] = 'pending';
		$this->withObjectService($this->buildObjectServiceStub(['DepositPayment' => [$pending]]));

		$this->assertFalse($this->guard->canComplete('ord-1001', $this->order()));

	}//end testCanCompleteDeniedWhenDepositNotAuthorised()

	/**
	 * The `canComplete` is allowed for a no-deposit order (REQ-DI-008).
	 *
	 * @return void
	 */
	public function testCanCompleteAllowedForNoDepositOrder(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));

		$order = $this->order(
			[
				'depositRequired' => false,
				'depositAmount' => null,
				'depositPaymentId' => null,
			]
		);

		$this->assertTrue($this->guard->canComplete('ord-1001', $order));

	}//end testCanCompleteAllowedForNoDepositOrder()

	/**
	 * The `resolveDepositCreditCents` prefers the explicit Order.depositAmount.
	 *
	 * @return void
	 */
	public function testResolveDepositCreditFromExplicitAmount(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));

		$this->assertSame(7500, $this->guard->resolveDepositCreditCents($this->order()));

	}//end testResolveDepositCreditFromExplicitAmount()

	/**
	 * The `resolveDepositCreditCents` falls back to the linked DepositPayment amount.
	 *
	 * @return void
	 */
	public function testResolveDepositCreditFromDepositPayment(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(['DepositPayment' => [$this->authorizedDeposit()]])
		);

		$order = $this->order(['depositAmount' => null]);
		$this->assertSame(7500, $this->guard->resolveDepositCreditCents($order));

	}//end testResolveDepositCreditFromDepositPayment()

	/**
	 * The `resolveDepositCreditCents` returns 0 for a no-deposit order (REQ-DI-008).
	 *
	 * @return void
	 */
	public function testResolveDepositCreditZeroForNoDeposit(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));

		$order = $this->order(['depositRequired' => false, 'depositAmount' => null]);
		$this->assertSame(0, $this->guard->resolveDepositCreditCents($order));

	}//end testResolveDepositCreditZeroForNoDeposit()

	/**
	 * The `buildLineItems` produces a service line and a negative 0%-VAT credit line (REQ-DI-003/004).
	 *
	 * @return void
	 */
	public function testBuildLineItemsWithDepositCredit(): void {
		$lines = $this->guard->buildLineItems($this->order(), 7500);

		$this->assertCount(2, $lines);

		$service = $lines[0];
		$this->assertSame('service', $service['lineType']);
		$this->assertSame(15000, $service['lineAmount']);
		$this->assertSame(21, $service['taxRate']);
		$this->assertSame(3150, $service['taxAmount']);
		$this->assertSame(18150, $service['grossAmount']);

		$credit = $lines[1];
		$this->assertSame('deposit_credit', $credit['lineType']);
		$this->assertSame(-7500, $credit['lineAmount']);
		$this->assertSame(0, $credit['taxRate']);
		$this->assertSame(0, $credit['taxAmount']);
		$this->assertSame(-7500, $credit['grossAmount']);

	}//end testBuildLineItemsWithDepositCredit()

	/**
	 * The `buildLineItems` omits the credit line for a no-deposit order (REQ-DI-008).
	 *
	 * @return void
	 */
	public function testBuildLineItemsWithoutDeposit(): void {
		$lines = $this->guard->buildLineItems($this->order(), 0);

		$this->assertCount(1, $lines);
		$this->assertSame('service', $lines[0]['lineType']);
		$this->assertSame(18150, $lines[0]['grossAmount']);

	}//end testBuildLineItemsWithoutDeposit()

	/**
	 * The `computeTotals` nets, VATs and grosses correctly with a deposit credit (REQ-DI-003/004).
	 *
	 * @return void
	 */
	public function testComputeTotalsWithDepositCredit(): void {
		$lines = $this->guard->buildLineItems($this->order(), 7500);
		$totals = $this->guard->computeTotals($lines);

		// Net is service net only; the deposit credit is a post-VAT gross reduction.
		$this->assertSame(15000, $totals['netAmount']);
		$this->assertSame(3150, $totals['vatAmount']);
		$this->assertSame(10650, $totals['grossAmount']);

	}//end testComputeTotalsWithDepositCredit()

	/**
	 * The `computeTotals` for a no-deposit order equals the full gross (REQ-DI-008).
	 *
	 * @return void
	 */
	public function testComputeTotalsWithoutDeposit(): void {
		$lines = $this->guard->buildLineItems($this->order(), 0);
		$totals = $this->guard->computeTotals($lines);

		$this->assertSame(15000, $totals['netAmount']);
		$this->assertSame(3150, $totals['vatAmount']);
		$this->assertSame(18150, $totals['grossAmount']);

	}//end testComputeTotalsWithoutDeposit()

	/**
	 * The `computeDueDate` adds the default 14 days (REQ-DI-005).
	 *
	 * @return void
	 */
	public function testComputeDueDateDefault(): void {
		$this->assertSame('2026-06-29', $this->guard->computeDueDate('2026-06-15', $this->order()));

	}//end testComputeDueDateDefault()

	/**
	 * The `computeDueDate` handles a month boundary (REQ-DI-005).
	 *
	 * @return void
	 */
	public function testComputeDueDateAcrossMonthBoundary(): void {
		$order = $this->order(['paymentTermsDays' => 30]);
		$this->assertSame('2026-07-25', $this->guard->computeDueDate('2026-06-25', $order));

	}//end testComputeDueDateAcrossMonthBoundary()

	/**
	 * The `sourceDocumentUri` yields the canonical booking-order URN (REQ-DI-001).
	 *
	 * @return void
	 */
	public function testSourceDocumentUri(): void {
		$this->assertSame(
			'urn:nextcloud:booking:order:ord-1001',
			$this->guard->sourceDocumentUri('ord-1001')
		);

	}//end testSourceDocumentUri()

	/**
	 * An exception during lookup fails closed (CWE-863).
	 *
	 * @return void
	 */
	public function testCanCompleteFailsClosedOnException(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('boom'));

		// Deposit required → lookup throws → fail closed.
		$this->assertFalse($this->guard->canComplete('ord-1001', $this->order(['depositAmount' => null])));

	}//end testCanCompleteFailsClosedOnException()
}//end class
