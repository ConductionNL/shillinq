<?php

/**
 * Unit tests for the ThreeWayMatchingEngine hand-over of a matched supplier
 * invoice to its `supplier-invoice-approval` chain (REQ-PAD-004).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The in-memory ObjectService double plays OpenRegister's approval gate the
 * way `ApprovalChainGateListener` does on main: a write that moves a
 * SupplierInvoice from matched to approved at or above the controller tier
 * (EUR 10.000 including VAT, in cents) is refused with the error code
 * `approval-chain-pending`, which OpenRegister raises as a hook exception
 * carrying `getErrors()`. Below the tier the write goes through.
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
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Service\ThreeWayMatchingEngine;
use OCA\Shillinq\Service\ToleranceProfileService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests that a matched invoice runs on its approval chain before payment.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ThreeWayMatchingEngineApprovalHandOverTest extends TestCase {

	/**
	 * Every SupplierInvoice write the store accepted, in order.
	 *
	 * @var array<int,string>
	 */
	private array $statuses = [];

	/**
	 * Every SupplierInvoice write the gate refused, in order.
	 *
	 * @var array<int,string>
	 */
	private array $refused = [];

	/**
	 * Log lines as [level, message].
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $logs = [];

	/**
	 * A large matched invoice waits for the controller: it stays matched and
	 * the engine does not approve it.
	 *
	 * @return void
	 */
	public function testALargeMatchedInvoiceWaitsForTheController(): void {
		$store  = $this->store(totalInclVat: 1854700, refusalCode: 'approval-chain-pending');
		$result = $this->engine(store: $store)->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		self::assertSame(ThreeWayMatchingEngine::STATUS_AUTO_APPROVED, $result['matchStatus']);
		self::assertSame(['matching', 'matched'], $this->statuses);
		self::assertSame(['approved'], $this->refused, 'the engine asked for approveMatched and the chain refused it');
		self::assertSame('matched', $store->statusOf(id: 'inv-1'), 'a payment run, which takes approved invoices only, leaves it out');
		self::assertContains(['info', 'ThreeWayMatchingEngine: matched invoice waits for its approval chain'], $this->logs);
		self::assertNotContains(['warning', 'ThreeWayMatchingEngine: matched invoice not approved'], $this->logs);

	}//end testALargeMatchedInvoiceWaitsForTheController()

	/**
	 * A small matched invoice is approved by the match without a step.
	 *
	 * @return void
	 */
	public function testASmallMatchedInvoiceIsApprovedByTheMatch(): void {
		$store = $this->store(totalInclVat: 45000, refusalCode: 'approval-chain-pending');
		$this->engine(store: $store)->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		self::assertSame(['matching', 'matched', 'approved'], $this->statuses);
		self::assertSame([], $this->refused);
		self::assertSame('approved', $store->statusOf(id: 'inv-1'));
		self::assertNotEmpty($store->rowOf(id: 'inv-1')['approvedAt'] ?? null, 'the approval is stamped like any other transition');

	}//end testASmallMatchedInvoiceIsApprovedByTheMatch()

	/**
	 * Any other refusal of the approval also leaves the invoice matched, and
	 * is logged as a failure rather than as a pending approval.
	 *
	 * @return void
	 */
	public function testAnotherRefusalLeavesTheInvoiceMatchedAndIsLogged(): void {
		$store = $this->store(totalInclVat: 1854700, refusalCode: 'approval-chain-misconfigured');
		$this->engine(store: $store)->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		self::assertSame('matched', $store->statusOf(id: 'inv-1'));
		self::assertContains(['warning', 'ThreeWayMatchingEngine: matched invoice not approved'], $this->logs);
		self::assertNotContains(['info', 'ThreeWayMatchingEngine: matched invoice waits for its approval chain'], $this->logs);

	}//end testAnotherRefusalLeavesTheInvoiceMatchedAndIsLogged()

	/**
	 * An invoice routed to an exception is never handed to approveMatched:
	 * accepting an exception stays the guarded manual transition.
	 *
	 * @return void
	 */
	public function testAnExceptionIsNotHandedToTheChain(): void {
		$store = $this->store(totalInclVat: 1854700, refusalCode: 'approval-chain-pending', invoicedQuantity: 20.0);
		$result = $this->engine(store: $store)->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		self::assertStringStartsWith('exception_', $result['matchStatus']);
		self::assertSame(['matching', 'exception'], $this->statuses);
		self::assertSame([], $this->refused);

	}//end testAnExceptionIsNotHandedToTheChain()

	/**
	 * The rows of one invoice matched against one PO with one accepted receipt.
	 *
	 * @param int    $totalInclVat     Invoice total including VAT, in cents.
	 * @param string $refusalCode      The code the gate refuses approved with at or above the tier.
	 * @param float  $invoicedQuantity Invoiced quantity (10 matches the order).
	 *
	 * @return object The in-memory store.
	 */
	private function store(int $totalInclVat, string $refusalCode, float $invoicedQuantity = 10.0): object {
		$data = [
			'SupplierInvoice' => [
				[
					'id' => 'inv-1',
					'invoiceNumber' => 'INV-2026-0912',
					'supplierId' => 'vendor-001',
					'totalInclVat' => $totalInclVat,
					'statusCode' => 'received',
					'administrationId' => 'adm-1',
					'matchedPoIds' => ['po-1'],
					'lines' => [
						[
							'lineNumber' => 1,
							'productCode' => 'DESK-1',
							'quantity' => $invoicedQuantity,
							'unitPrice' => 10000,
							'lineExtension' => 100000,
							'vatRate' => 0.21,
						],
					],
				],
			],
			'PurchaseOrder' => [
				['id' => 'po-1', 'poNumber' => 'PO-2026-040', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				[
					'id' => 'pol-1',
					'poId' => 'po-1',
					'lineNumber' => 1,
					'productOrServiceCode' => 'DESK-1',
					'quantityOrdered' => 10.0,
					'unitPrice' => 10000,
					'vatRate' => 2100,
					'vatAmount' => 21000,
					'administrationId' => 'adm-1',
				],
			],
			'GoodsReceiptNote' => [
				['id' => 'grn-1', 'poIds' => ['po-1'], 'statusCode' => 'accepted', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptLine' => [
				[
					'id' => 'grl-1',
					'grnId' => 'grn-1',
					'poLineId' => 'pol-1',
					'quantityReceived' => 10.0,
					'quantityAccepted' => 10.0,
					'administrationId' => 'adm-1',
				],
			],
			'ToleranceProfile' => [],
			'ThreeWayMatch' => [],
		];

		$statuses = &$this->statuses;
		$refused  = &$this->refused;

		return new class($data, $refusalCode, $statuses, $refused) {

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Id counter.
			 *
			 * @var integer
			 */
			private int $ids = 0;

			/**
			 * Accepted SupplierInvoice statuses (reference).
			 *
			 * @var array<int,string>
			 */
			private array $statuses;

			/**
			 * Refused SupplierInvoice statuses (reference).
			 *
			 * @var array<int,string>
			 */
			private array $refused;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data        Schema rows.
			 * @param string                                       $refusalCode The gate's refusal code.
			 * @param array<int,string>                            $statuses    Accepted statuses (reference).
			 * @param array<int,string>                            $refused     Refused statuses (reference).
			 */
			public function __construct(
				private array $data,
				private string $refusalCode,
				array &$statuses,
				array &$refused,
			) {
				$this->statuses = &$statuses;
				$this->refused  = &$refused;
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
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Rows of the active schema that match every equality filter.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						($this->data[$this->schema] ?? []),
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Save a row, refusing matched -> approved at or above the controller tier.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if ($this->schema === 'SupplierInvoice') {
					$this->gate(object: $object);
					$this->statuses[] = (string)($object['statusCode'] ?? '');
				}

				if (($object['id'] ?? '') === '') {
					$this->ids++;
					$object['id'] = 'obj-' . $this->ids;
				}

				foreach (($this->data[$this->schema] ?? []) as $i => $row) {
					if (($row['id'] ?? null) === $object['id']) {
						$this->data[$this->schema][$i] = $object;
						return $object;
					}
				}

				$this->data[$this->schema][] = $object;
				return $object;
			}//end saveObject()

			/**
			 * Play OpenRegister's approval gate on the approveMatched transition.
			 *
			 * @param array<string,mixed> $object The SupplierInvoice being written.
			 *
			 * @return void
			 *
			 * @throws RuntimeException The hook refusal, carrying getErrors().
			 */
			private function gate(array $object): void {
				$current = ($this->rowOf(id: (string)($object['id'] ?? ''))['statusCode'] ?? null);
				if ($current !== 'matched' || ($object['statusCode'] ?? null) !== 'approved' || (int)($object['totalInclVat'] ?? 0) < 1000000) {
					return;
				}

				$this->refused[] = 'approved';
				throw new class('Approval chain refused the transition', $this->refusalCode) extends RuntimeException {

					/**
					 * Constructor.
					 *
					 * @param string $message   The refusal message.
					 * @param string $errorCode The refusal code.
					 */
					public function __construct(string $message, private string $errorCode) {
						parent::__construct($message);
					}//end __construct()

					/**
					 * The hook's error payload, as OpenRegister's HookStoppedException carries it.
					 *
					 * @return array<string,string>
					 */
					public function getErrors(): array {
						return ['code' => $this->errorCode, 'message' => $this->getMessage()];
					}//end getErrors()
				};
			}//end gate()

			/**
			 * The stored SupplierInvoice row with this id.
			 *
			 * @param string $id Invoice id.
			 *
			 * @return array<string,mixed>
			 */
			public function rowOf(string $id): array {
				foreach (($this->data['SupplierInvoice'] ?? []) as $row) {
					if (($row['id'] ?? null) === $id) {
						return $row;
					}
				}

				return [];
			}//end rowOf()

			/**
			 * The stored statusCode of a SupplierInvoice.
			 *
			 * @param string $id Invoice id.
			 *
			 * @return string|null
			 */
			public function statusOf(string $id): ?string {
				return ($this->rowOf(id: $id)['statusCode'] ?? null);
			}//end statusOf()
		};

	}//end store()

	/**
	 * The engine over the store, with a logger that records levels and messages.
	 *
	 * @param object $store The in-memory store.
	 *
	 * @return ThreeWayMatchingEngine
	 */
	private function engine(object $store): ThreeWayMatchingEngine {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'debug', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message) use ($level): void {
					$this->logs[] = [$level, (string)$message];
				}
			);
		}

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturn(true);
		$administrationContext->method('currentUserId')->willReturn('matcher-bot');

		$invoiceService = new SupplierInvoiceService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($store),
			checks: $this->createStub(SupplierInvoiceChecks::class),
		);

		return new ThreeWayMatchingEngine(
			appConfig: $appConfig,
			toleranceService: new ToleranceProfileService(container: $container, appConfig: $appConfig, logger: $logger),
			invoiceService: $invoiceService,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($store),
		);

	}//end engine()
}//end class
