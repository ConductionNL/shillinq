<?php

/**
 * Unit tests for QuoteOrderInvoiceGuard.
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
 * @spec openspec/changes/bookkeeping-quote-order-invoice/specs/bookkeeping-quote-order-invoice/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\QuoteOrderInvoiceGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for QuoteOrderInvoiceGuard lifecycle preconditions.
 *
 * Covers REQ-QOI-001 (quote send/accept), REQ-QOI-004 (order confirm blocked on
 * credit hold), REQ-QOI-005 (invoice issue balanced), REQ-QOI-010 (credit-hold
 * release audit trail + credit-note issue). All guards fail closed.
 */
class QuoteOrderInvoiceGuardTest extends TestCase {
	// phpcs:disable CustomSniffs.Functions.NamedParameters

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
	 * @var QuoteOrderInvoiceGuard
	 */
	private QuoteOrderInvoiceGuard $guard;

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

		$this->guard = new QuoteOrderInvoiceGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->buildObjectServiceStub([])),
		);

	}//end setUp()

	/**
	 * Rebuild the guard on a fluent ObjectService stub serving the given records
	 * by schema (matching the ADR-022 setRegister/setSchema/findAll API).
	 *
	 * The store is a constructor dependency since ADR-084, so the guard has to
	 * be rebuilt whenever a test seeds different records.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $itemsBySchema Map schema -> records.
	 *
	 * @return void
	 */
	private function stubObjectService(array $itemsBySchema): void {
		$service = $this->buildObjectServiceStub($itemsBySchema);

		$this->container->method('get')->willReturn($service);

		$this->guard = new QuoteOrderInvoiceGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($service),
		);

	}//end stubObjectService()

	/**
	 * Build a duck-typed ObjectService store over the given records.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $itemsBySchema Map schema -> records.
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $itemsBySchema): object {
		return new class($itemsBySchema) {

			/**
			 * Records keyed by schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $itemsBySchema;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			public string $currentSchema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $itemsBySchema Records by schema.
			 */
			public function __construct(array $itemsBySchema) {
				$this->itemsBySchema = $itemsBySchema;

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
				$this->currentSchema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Return stubbed records for the active schema, applying a simple
			 * equality filter when present (mirrors OR's filters param).
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$items = ($this->itemsBySchema[$this->currentSchema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $items;
				}

				return array_values(
					array_filter(
						$items,
						static function (array $item) use ($filters): bool {
							foreach ($filters as $field => $value) {
								if (($item[$field] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);

			}//end findAll()
		};

	}//end buildObjectServiceStub()

	/**
	 * A quote with a customer and at least one line may be sent (REQ-QOI-001).
	 *
	 * @return void
	 */
	public function testQuoteWithLineCanBeSent(): void {
		$this->stubObjectService(['QuoteLine' => [['quote' => 'q-1']]]);

		self::assertTrue(
			$this->guard->canSendQuote(quoteId: 'q-1', object: ['customerReference' => 'cust-1'])
		);

	}//end testQuoteWithLineCanBeSent()

	/**
	 * A quote without a customer reference cannot be sent (fail-closed).
	 *
	 * @return void
	 */
	public function testQuoteWithoutCustomerCannotBeSent(): void {
		$this->stubObjectService(['QuoteLine' => [['quote' => 'q-1']]]);

		self::assertFalse(
			$this->guard->canSendQuote(quoteId: 'q-1', object: ['customerReference' => ''])
		);

	}//end testQuoteWithoutCustomerCannotBeSent()

	/**
	 * A quote with a customer but no lines cannot be sent (REQ-QOI-001).
	 *
	 * @return void
	 */
	public function testQuoteWithoutLinesCannotBeSent(): void {
		$this->stubObjectService(['QuoteLine' => []]);

		self::assertFalse(
			$this->guard->canSendQuote(quoteId: 'q-1', object: ['customerReference' => 'cust-1'])
		);

	}//end testQuoteWithoutLinesCannotBeSent()

	/**
	 * Acceptance requires a recorded channel (REQ-QOI-002).
	 *
	 * @return void
	 */
	public function testQuoteAcceptRequiresChannel(): void {
		self::assertTrue(
			$this->guard->canAcceptQuote(quoteId: 'q-1', object: ['acceptanceChannel' => 'in-app-link'])
		);
		self::assertFalse(
			$this->guard->canAcceptQuote(quoteId: 'q-1', object: ['acceptanceChannel' => ''])
		);
		self::assertFalse(
			$this->guard->canAcceptQuote(quoteId: 'q-1', object: ['acceptanceChannel' => 'carrier-pigeon'])
		);

	}//end testQuoteAcceptRequiresChannel()

	/**
	 * An order cannot be confirmed while the customer is under a block-order hold
	 * (REQ-QOI-004 / design D9).
	 *
	 * @return void
	 */
	public function testOrderConfirmBlockedByActiveCreditHold(): void {
		$this->stubObjectService(
			[
				'CreditHold' => [
					['customerReference' => 'cust-1', 'severity' => 'block-order', 'status' => 'active'],
				],
			]
		);

		self::assertFalse(
			$this->guard->canConfirmOrder(orderId: 'so-1', object: ['customerReference' => 'cust-1'])
		);

	}//end testOrderConfirmBlockedByActiveCreditHold()

	/**
	 * An order confirms when the customer has only a released or warning-level hold
	 * (REQ-QOI-004).
	 *
	 * @return void
	 */
	public function testOrderConfirmAllowedWhenHoldReleasedOrWarning(): void {
		$this->stubObjectService(
			[
				'CreditHold' => [
					['customerReference' => 'cust-1', 'severity' => 'block-order', 'status' => 'released'],
					['customerReference' => 'cust-1', 'severity' => 'warning', 'status' => 'active'],
				],
			]
		);

		self::assertTrue(
			$this->guard->canConfirmOrder(orderId: 'so-1', object: ['customerReference' => 'cust-1'])
		);

	}//end testOrderConfirmAllowedWhenHoldReleasedOrWarning()

	/**
	 * Invoice issue requires a sequential number, at least one line, and a balanced
	 * net + vat = gross total (REQ-QOI-005).
	 *
	 * @return void
	 */
	public function testInvoiceIssueRequiresBalancedTotalAndLine(): void {
		$this->stubObjectService(['InvoiceLine' => [['invoice' => 'inv-1']]]);

		self::assertTrue(
			$this->guard->canIssueInvoice(
				invoiceId: 'inv-1',
				object: [
					'invoiceNumber' => '2026-0001',
					'netAmount' => 100.0,
					'vatAmount' => 21.0,
					'grossAmount' => 121.0,
				]
			)
		);

	}//end testInvoiceIssueRequiresBalancedTotalAndLine()

	/**
	 * An unbalanced invoice (net + vat != gross) cannot be issued (fail-closed).
	 *
	 * @return void
	 */
	public function testUnbalancedInvoiceCannotBeIssued(): void {
		$this->stubObjectService(['InvoiceLine' => [['invoice' => 'inv-1']]]);

		self::assertFalse(
			$this->guard->canIssueInvoice(
				invoiceId: 'inv-1',
				object: [
					'invoiceNumber' => '2026-0001',
					'netAmount' => 100.0,
					'vatAmount' => 21.0,
					'grossAmount' => 200.0,
				]
			)
		);

	}//end testUnbalancedInvoiceCannotBeIssued()

	/**
	 * An invoice without a sequential number cannot be issued (REQ-QOI-007).
	 *
	 * @return void
	 */
	public function testInvoiceWithoutNumberCannotBeIssued(): void {
		$this->stubObjectService(['InvoiceLine' => [['invoice' => 'inv-1']]]);

		self::assertFalse(
			$this->guard->canIssueInvoice(
				invoiceId: 'inv-1',
				object: [
					'invoiceNumber' => '',
					'netAmount' => 100.0,
					'vatAmount' => 21.0,
					'grossAmount' => 121.0,
				]
			)
		);

	}//end testInvoiceWithoutNumberCannotBeIssued()

	/**
	 * A credit note must reference a source invoice, a reason, and a positive
	 * amount (REQ-QOI-010).
	 *
	 * @return void
	 */
	public function testCreditNoteIssueRequiresInvoiceReasonAndAmount(): void {
		self::assertTrue(
			$this->guard->canIssueCreditNote(
				creditNoteId: 'cn-1',
				object: [
					'sourceInvoiceReference' => 'inv-1',
					'reason' => 'return',
					'totalAmount' => 50.0,
				]
			)
		);

		self::assertFalse(
			$this->guard->canIssueCreditNote(
				creditNoteId: 'cn-1',
				object: [
					'sourceInvoiceReference' => 'inv-1',
					'reason' => '',
					'totalAmount' => 50.0,
				]
			)
		);

		self::assertFalse(
			$this->guard->canIssueCreditNote(
				creditNoteId: 'cn-1',
				object: [
					'sourceInvoiceReference' => 'inv-1',
					'reason' => 'return',
					'totalAmount' => 0.0,
				]
			)
		);

	}//end testCreditNoteIssueRequiresInvoiceReasonAndAmount()

	/**
	 * A credit-hold release must record who released it and why (REQ-QOI-010 audit
	 * trail).
	 *
	 * @return void
	 */
	public function testCreditHoldReleaseRequiresAuditTrail(): void {
		self::assertTrue(
			$this->guard->canReleaseCreditHold(
				holdId: 'ch-1',
				object: ['releasedBy' => 'controller', 'releaseReason' => 'Payment plan agreed']
			)
		);

		self::assertFalse(
			$this->guard->canReleaseCreditHold(
				holdId: 'ch-1',
				object: ['releasedBy' => '', 'releaseReason' => 'Payment plan agreed']
			)
		);

		self::assertFalse(
			$this->guard->canReleaseCreditHold(
				holdId: 'ch-1',
				object: ['releasedBy' => 'controller', 'releaseReason' => '']
			)
		);

	}//end testCreditHoldReleaseRequiresAuditTrail()

	/**
	 * A delivery line missing an order-line reference fails the confirm check
	 * (REQ-QOI-005 fail-closed). No container access needed (short-circuits).
	 *
	 * @return void
	 */
	public function testDeliveryWithIncompleteLineCannotBeConfirmed(): void {
		self::assertFalse(
			$this->guard->canConfirmDelivery(
				deliveryId: 'dn-1',
				object: ['lines' => [['quantityShipped' => 10]]]
			)
		);

	}//end testDeliveryWithIncompleteLineCannotBeConfirmed()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
