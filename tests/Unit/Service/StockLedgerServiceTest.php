<?php

/**
 * Characterisation tests for StockLedgerService.
 *
 * Pins the on-hand, reserved and trace read-outs of the StockMove ledger:
 * which moves count, their signs, the running total, the chronological order,
 * the de-duplication across the two query sides, and the logged fail-soft
 * answers.
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
 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\StockLedgerService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Characterisation tests for StockLedgerService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class StockLedgerServiceTest extends TestCase {

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the service over the given StockMove rows.
	 *
	 * @param array<int,array<string,mixed>> $moves StockMove rows.
	 *
	 * @return StockLedgerService
	 */
	private function ledger(array $moves): StockLedgerService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$this->logger = $this->createMock(LoggerInterface::class);

		return new StockLedgerService(
			appConfig: $appConfig,
			logger: $this->logger,
			objectService: new InMemoryObjectServiceStub(data: ['StockMove' => $moves]),
		);
	}

	/**
	 * A posted move of the fixture set.
	 *
	 * @param string $id          Move id.
	 * @param string $postedAt    Posting timestamp.
	 * @param string $source      Source location.
	 * @param string $destination Destination location.
	 * @param mixed  $quantity    Quantity.
	 *
	 * @return array<string,mixed>
	 */
	private static function move(string $id, string $postedAt, string $source, string $destination, mixed $quantity): array {
		return [
			'id' => $id,
			'movementNumber' => 'SM-' . $id,
			'movementType' => 'transfer',
			'postedAt' => $postedAt,
			'administrationId' => 'adm-1',
			'itemId' => 'SKU-1',
			'lifecycleState' => 'posted',
			'sourceLocationId' => $source,
			'destinationLocationId' => $destination,
			'quantity' => $quantity,
		];
	}

	/**
	 * The fixture ledger: receipts into, an issue from, a move within, and noise.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function moves(): array {
		$draft = self::move('d1', '2026-01-05', 'BIN-A', 'BIN-B', 2.5);
		$draft['lifecycleState'] = 'draft';
		$otherSku = self::move('o1', '2026-01-01', '', 'BIN-A', 99);
		$otherSku['itemId'] = 'SKU-2';
		$otherAdmin = self::move('x1', '2026-01-01', '', 'BIN-A', 99);
		$otherAdmin['administrationId'] = 'adm-2';

		return [
			self::move('m3', '2026-01-03', 'BIN-A', 'BIN-C', 4.005),
			self::move('m1', '2026-01-01', '', 'BIN-A', 10),
			self::move('m2', '2026-01-02', 'BIN-Z', 'BIN-A', '1.25'),
			self::move('m4', '2026-01-04', 'BIN-A', 'BIN-A', 3),
			$draft,
			$otherSku,
			$otherAdmin,
			['administrationId' => 'adm-1', 'itemId' => 'SKU-1', 'lifecycleState' => 'posted', 'destinationLocationId' => 'BIN-A', 'quantity' => 50],
		];
	}

	/**
	 * On hand adds receipts, subtracts issues, nets a move within, from the seed.
	 */
	public function testQuantityForLocationNetsTheLedger(): void {
		$ledger = $this->ledger(self::moves());

		self::assertSame(7.24, $ledger->quantityForLocation('adm-1', 'BIN-A', 'SKU-1'));
		self::assertSame(8.74, $ledger->quantityForLocation('adm-1', 'BIN-A', 'SKU-1', 1.5));
		self::assertSame(0.0, $ledger->quantityForLocation('adm-1', '', 'SKU-1'));
	}

	/**
	 * Reserved sums the draft moves out of the location.
	 */
	public function testReservedForLocationSumsDraftIssues(): void {
		$ledger = $this->ledger(self::moves());

		self::assertSame(2.5, $ledger->reservedForLocation('adm-1', 'BIN-A', 'SKU-1'));
		self::assertSame(0.0, $ledger->reservedForLocation('adm-1', 'BIN-B', 'SKU-1'));
	}

	/**
	 * Golden: the trace is chronological, signed from the location's side, with a running total.
	 */
	public function testTraceLocationGolden(): void {
		$ledger = $this->ledger(self::moves());

		self::assertSame(
			[
				['id' => 'm1', 'movementNumber' => 'SM-m1', 'postedAt' => '2026-01-01', 'movementType' => 'transfer', 'sign' => '+', 'quantity' => 10.0, 'runningTotal' => 10.0],
				['id' => 'm2', 'movementNumber' => 'SM-m2', 'postedAt' => '2026-01-02', 'movementType' => 'transfer', 'sign' => '+', 'quantity' => 1.25, 'runningTotal' => 11.25],
				['id' => 'm3', 'movementNumber' => 'SM-m3', 'postedAt' => '2026-01-03', 'movementType' => 'transfer', 'sign' => '-', 'quantity' => 4.01, 'runningTotal' => 7.24],
				['id' => 'm4', 'movementNumber' => 'SM-m4', 'postedAt' => '2026-01-04', 'movementType' => 'transfer', 'sign' => '-', 'quantity' => 3.0, 'runningTotal' => 4.24],
			],
			$ledger->traceLocation('adm-1', 'BIN-A', 'SKU-1')
		);
		self::assertSame([], $ledger->traceLocation('adm-1', 'BIN-A', ''));
	}

	/**
	 * A failing read is logged with its scope and answers zero or an empty trace.
	 */
	public function testFailingReadsAreLoggedAndAnswerEmpty(): void {
		$this->ledger([]);
		$messages = [];
		$this->logger->expects(self::exactly(3))->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$messages): void {
				$messages[] = [$message, array_keys($context), $context['sku']];
			}
		);

		$throwing = $this->createMock(IAppConfig::class);
		$throwing->method('getValueString')->willThrowException(new \RuntimeException('config down'));
		$broken = new StockLedgerService(appConfig: $throwing, logger: $this->logger, objectService: new InMemoryObjectServiceStub(data: []));

		self::assertSame(0.0, $broken->quantityForLocation('adm-1', 'BIN-A', 'SKU-1'));
		self::assertSame(0.0, $broken->reservedForLocation('adm-1', 'BIN-A', 'SKU-2'));
		self::assertSame([], $broken->traceLocation('adm-1', 'BIN-A', 'SKU-3'));
		self::assertSame(
			[
				['StockLedgerService: quantityForLocation failed', ['administrationId', 'locationId', 'sku', 'exception'], 'SKU-1'],
				['StockLedgerService: reservedForLocation failed', ['administrationId', 'locationId', 'sku', 'exception'], 'SKU-2'],
				['StockLedgerService: traceLocation failed', ['administrationId', 'locationId', 'sku', 'exception'], 'SKU-3'],
			],
			$messages
		);
	}
}
