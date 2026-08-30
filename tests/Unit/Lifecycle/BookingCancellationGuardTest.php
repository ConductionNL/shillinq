<?php

/**
 * Unit tests for BookingCancellationGuard.
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
 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-38
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BookingCancellationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BookingCancellationGuard per REQ-DI-006.
 *
 * Covers:
 * - canCancel happy path (invoiced, reversible, no existing credit note).
 * - canCancel denied when order has no invoice.
 * - canCancel denied when the linked invoice is missing.
 * - canCancel denied when the invoice is already reversed.
 * - canCancel denied — idempotency — when a credit note already exists.
 * - buildReversingCreditNote mirrors the full invoice gross (REQ-DI-006).
 * - shouldAutoRefundDeposit policy/state logic.
 * - exception fail-closed (CWE-863).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class BookingCancellationGuardTest extends TestCase {

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
	 * @var BookingCancellationGuard
	 */
	private BookingCancellationGuard $guard;

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

		$this->guard = new BookingCancellationGuard(
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
	 * A completed, invoiced order.
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
				'invoiceId' => 'inv-final-2001',
				'state' => 'completed',
			],
			$overrides
		);

	}//end order()

	/**
	 * An issued invoice for inv-final-2001.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function invoice(array $overrides = []): array {
		return array_merge(
			[
				'administrationId' => 'adm-1',
				'invoiceId' => 'inv-final-2001',
				'customerId' => 'cust-5432',
				'grossAmount' => 10650,
				'currency' => 'EUR',
				'state' => 'issued',
			],
			$overrides
		);

	}//end invoice()

	/**
	 * The `canCancel` is true for an invoiced, reversible order with no existing credit note.
	 *
	 * @return void
	 */
	public function testCanCancelHappyPath(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'Invoice' => [$this->invoice()],
					'CreditNote' => [],
				]
			)
		);

		$this->assertTrue($this->guard->canCancel('ord-1001', $this->order()));

	}//end testCanCancelHappyPath()

	/**
	 * Regression guard (issue #503, 2026-07-23): when no $object is
	 * pre-supplied, canCancel must resolve the order via the CURRENT
	 * `BookingOrder` schema slug, not the stale `Order` slug the booking
	 * context was renamed away from in 07709a0f. Before the fix this
	 * fallback silently found nothing (schema `Order` no longer exists for
	 * bookings) and denied every cancellation reached through this path.
	 *
	 * @return void
	 */
	public function testCanCancelResolvesOrderViaBookingOrderSchemaFallback(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'BookingOrder' => [$this->order()],
					'Invoice' => [$this->invoice()],
					'CreditNote' => [],
				]
			)
		);

		$this->assertTrue($this->guard->canCancel('ord-1001'), 'fallback lookup must resolve against schema BookingOrder');

	}//end testCanCancelResolvesOrderViaBookingOrderSchemaFallback()

	/**
	 * The `canCancel` is denied when the order has no invoice.
	 *
	 * @return void
	 */
	public function testCanCancelDeniedWhenNoInvoice(): void {
		$this->withObjectService($this->buildObjectServiceStub([]));

		$this->assertFalse($this->guard->canCancel('ord-1001', $this->order(['invoiceId' => null])));

	}//end testCanCancelDeniedWhenNoInvoice()

	/**
	 * The `canCancel` is denied when the linked invoice cannot be found.
	 *
	 * @return void
	 */
	public function testCanCancelDeniedWhenInvoiceMissing(): void {
		$this->withObjectService($this->buildObjectServiceStub(['Invoice' => [], 'CreditNote' => []]));

		$this->assertFalse($this->guard->canCancel('ord-1001', $this->order()));

	}//end testCanCancelDeniedWhenInvoiceMissing()

	/**
	 * The `canCancel` is denied when the invoice is already reversed.
	 *
	 * @return void
	 */
	public function testCanCancelDeniedWhenInvoiceAlreadyReversed(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'Invoice' => [$this->invoice(['state' => 'reversed'])],
					'CreditNote' => [],
				]
			)
		);

		$this->assertFalse($this->guard->canCancel('ord-1001', $this->order()));

	}//end testCanCancelDeniedWhenInvoiceAlreadyReversed()

	/**
	 * The `canCancel` is denied — idempotency — when a credit note already exists (REQ-DI-006).
	 *
	 * @return void
	 */
	public function testCanCancelDeniedWhenCreditNoteExists(): void {
		$this->withObjectService(
			$this->buildObjectServiceStub(
				[
					'Invoice' => [$this->invoice()],
					'CreditNote' => [['creditNoteId' => 'cn-0501', 'linkedInvoiceId' => 'inv-final-2001']],
				]
			)
		);

		$this->assertFalse($this->guard->canCancel('ord-1001', $this->order()));

	}//end testCanCancelDeniedWhenCreditNoteExists()

	/**
	 * The `buildReversingCreditNote` mirrors the full invoice gross (REQ-DI-006).
	 *
	 * @return void
	 */
	public function testBuildReversingCreditNote(): void {
		$note = $this->guard->buildReversingCreditNote($this->invoice(), '2026-06-16', 'Booking cancelled');

		$this->assertSame('inv-final-2001', $note['linkedInvoiceId']);
		$this->assertSame('cust-5432', $note['customerId']);
		$this->assertSame(10650, $note['grossAmount']);
		$this->assertSame('2026-06-16', $note['creditDate']);
		$this->assertSame('Booking cancelled', $note['reason']);
		$this->assertSame('issued', $note['state']);

	}//end testBuildReversingCreditNote()

	/**
	 * The `shouldAutoRefundDeposit` returns true only for an automatic policy on a live deposit.
	 *
	 * @return void
	 */
	public function testShouldAutoRefundDepositPolicyAndState(): void {
		$this->assertTrue(
			$this->guard->shouldAutoRefundDeposit(
				['refundPolicy' => 'automatic_on_cancellation', 'state' => 'captured']
			)
		);

		$this->assertFalse(
			$this->guard->shouldAutoRefundDeposit(
				['refundPolicy' => 'manual', 'state' => 'captured']
			)
		);

		$this->assertFalse(
			$this->guard->shouldAutoRefundDeposit(
				['refundPolicy' => 'automatic_on_cancellation', 'state' => 'refunded']
			)
		);

		$this->assertFalse($this->guard->shouldAutoRefundDeposit(null));

	}//end testShouldAutoRefundDepositPolicyAndState()

	/**
	 * An exception during lookup fails closed (CWE-863).
	 *
	 * @return void
	 */
	public function testCanCancelFailsClosedOnException(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('boom'));

		$this->assertFalse($this->guard->canCancel('ord-1001', $this->order()));

	}//end testCanCancelFailsClosedOnException()
}//end class
