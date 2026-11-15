<?php

/**
 * Characterisation tests for the bundle ThreeWayMatchAuditController loads.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Controller
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

namespace OCA\Shillinq\Tests\Unit\Controller;

use OCA\Shillinq\Controller\ThreeWayMatchAuditController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AuditExportService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the queries and the bundle behind the audit ledger.
 */
final class ThreeWayMatchAuditBundleTest extends TestCase {

	/**
	 * Log lines.
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	/**
	 * Build the controller over a fake object service and call ledger().
	 *
	 * @param object $objects The fake object service.
	 * @param array<string,mixed>|null $bundle Out: the bundle handed to the ledger.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function ledger(object $objects, ?array &$bundle): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['administrationId', '', ' adm-1 '], ['invoiceId', '', 'inv-1']]);
		$export = $this->createMock(AuditExportService::class);
		$export->method('buildLedger')->willReturnCallback(
			function (array $given) use (&$bundle): array {
				$bundle = $given;
				return ['ledger' => true];
			}
		);
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('canAccess')->willReturn(true);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logs[] = $message . '|' . json_encode($context);
			}
		);

		$response = (new ThreeWayMatchAuditController($request, $export, $context, $session, $container, $logger))->ledger();
		return [$response->getStatus(), $response->getData()];

	}//end ledger()

	/**
	 * A fake object service answering per schema and filters.
	 *
	 * @param array<string,mixed> $answers Answer per "schema|json(filters)".
	 *
	 * @return object
	 */
	private static function objects(array $answers): object {
		return new class($answers) {
			/**
			 * Calls in order.
			 *
			 * @var array<int,string>
			 */
			public array $calls = [];

			/**
			 * Current schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $answers Answers.
			 */
			public function __construct(private array $answers) {
			}

			/**
			 * Scope to a register.
			 *
			 * @param string $r Register.
			 *
			 * @return self
			 */
			public function setRegister(string $r): self {
				$this->calls[] = 'register:' . $r;
				return $this;
			}

			/**
			 * Scope to a schema.
			 *
			 * @param string $s Schema.
			 *
			 * @return self
			 */
			public function setSchema(string $s): self {
				$this->schema = $s;
				return $this;
			}

			/**
			 * List objects.
			 *
			 * @param array<string,mixed> $query Query.
			 *
			 * @return array<int,mixed>
			 */
			public function findAll(array $query): array {
				$key = $this->schema . '|' . json_encode($query['filters']);
				$this->calls[] = $key;
				$answer = ($this->answers[$key] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		};

	}//end objects()

	/**
	 * The bundle gathers the invoice, its matches and each linked PO and GRN once.
	 *
	 * @return void
	 */
	public function testBundle(): void {
		$scope = static fn (string $id): string => json_encode(['id' => $id, 'administrationId' => 'adm-1']);
		$objects = self::objects(
			[
				'SupplierInvoice|' . $scope('inv-1') => ['not a row', ['id' => 'inv-1']],
				'ThreeWayMatch|' . json_encode(['invoiceId' => 'inv-1', 'administrationId' => 'adm-1']) => [
					['matchedPoIds' => [' po-1 ', 'po-2', ''], 'matchedGrnIds' => 'grn-1'],
					'not a row',
					['matchedPoIds' => ['po-1', 'po-3'], 'matchedGrnIds' => ['grn-1', 'grn-2', ' ']],
					['id' => 'm-3'],
				],
				'PurchaseOrder|' . $scope('po-1') => [['id' => 'po-1']],
				'PurchaseOrder|' . $scope('po-2') => new \RuntimeException('po index'),
				'PurchaseOrder|' . $scope('po-3') => [['id' => 'po-3'], ['id' => 'po-3b']],
				'GoodsReceiptNote|' . $scope('grn-1') => [],
				'GoodsReceiptNote|' . $scope('grn-2') => [['id' => 'grn-2']],
			]
		);

		$bundle = null;
		self::assertSame([200, ['ledger' => true]], $this->ledger($objects, $bundle));

		self::assertSame(
			[
				'invoice' => ['id' => 'inv-1'],
				'purchaseOrders' => [['id' => 'po-1'], ['id' => 'po-3']],
				'goodsReceiptNotes' => [['id' => 'grn-2']],
				'threeWayMatches' => [
					['matchedPoIds' => [' po-1 ', 'po-2', ''], 'matchedGrnIds' => 'grn-1'],
					['matchedPoIds' => ['po-1', 'po-3'], 'matchedGrnIds' => ['grn-1', 'grn-2', ' ']],
					['id' => 'm-3'],
				],
			],
			$bundle
		);
		self::assertSame(
			[
				'register:shillinq', 'SupplierInvoice|' . $scope('inv-1'),
				'register:shillinq', 'ThreeWayMatch|' . json_encode(['invoiceId' => 'inv-1', 'administrationId' => 'adm-1']),
				'register:shillinq', 'PurchaseOrder|' . $scope('po-1'),
				'register:shillinq', 'PurchaseOrder|' . $scope('po-2'),
				'register:shillinq', 'PurchaseOrder|' . $scope('po-3'),
				'register:shillinq', 'GoodsReceiptNote|' . $scope('grn-1'),
				'register:shillinq', 'GoodsReceiptNote|' . $scope('grn-2'),
			],
			$objects->calls
		);
		self::assertSame(['ThreeWayMatchAuditController: failed to query OpenRegister|{"schema":"PurchaseOrder","exception":"po index"}'], $this->logs);

	}//end testBundle()

	/**
	 * Without the invoice nothing else is queried and the ledger is a 404.
	 *
	 * @return void
	 */
	public function testMissingInvoice(): void {
		$objects = self::objects([]);
		$bundle = null;

		self::assertSame([404, ['error' => 'Supplier invoice not found']], $this->ledger($objects, $bundle));
		self::assertNull($bundle);
		self::assertCount(2, $objects->calls);

	}//end testMissingInvoice()
}//end class
