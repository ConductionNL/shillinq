<?php

/**
 * Golden tests for StockLedgerController::trace().
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

use OCA\Shillinq\Controller\StockLedgerController;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\StockLedgerService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins every refusal, the access check and the trace of one location.
 */
final class StockLedgerTraceGoldenTest extends TestCase {

	/**
	 * Ledger mock.
	 *
	 * @var StockLedgerService&MockObject
	 */
	private StockLedgerService&MockObject $ledger;

	/**
	 * Context mock.
	 *
	 * @var AdministrationContextService&MockObject
	 */
	private AdministrationContextService&MockObject $context;

	/**
	 * Logged errors.
	 *
	 * @var array<int,string>
	 */
	private array $logs = [];

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->ledger = $this->createMock(StockLedgerService::class);
		$this->context = $this->createMock(AdministrationContextService::class);

	}//end setUp()

	/**
	 * Call trace() with parameters.
	 *
	 * @param array<string,mixed> $params The parameters.
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function trace(array $params): array {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logs[] = $message . '|' . json_encode($context);
			}
		);
		$response = (new StockLedgerController($request, $this->ledger, $this->context, $logger))->trace();
		return [$response->getStatus(), $response->getData()];

	}//end trace()

	/**
	 * Inputs refused with a 400.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function badInputs(): array {
		$required = 'administration_id, location_id and sku are required';
		$invalid = 'Invalid identifier in administration_id / location_id / sku';
		$ok = ['administration_id' => 'adm-1', 'location_id' => 'loc-1', 'sku' => 'SKU-1'];
		return [
			'no administration' => [['administration_id' => ' '] + $ok, $required],
			'no location' => [['location_id' => ''] + $ok, $required],
			'no sku' => [['location_id' => 'loc-1', 'administration_id' => 'adm-1'], $required],
			'bad administration' => [['administration_id' => 'adm 1'] + $ok, $invalid],
			'bad location' => [['location_id' => str_repeat('l', 65)] + $ok, $invalid],
			'bad sku' => [['sku' => 'SKU/1'] + $ok, $invalid],
		];

	}//end badInputs()

	/**
	 * A missing or malformed input is a 400 without an access check.
	 *
	 * @param array<string,mixed> $params The parameters.
	 * @param string $error The expected error.
	 *
	 * @return void
	 *
	 * @dataProvider badInputs
	 */
	public function testBadInputIsRefused(array $params, string $error): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->expects(self::never())->method('canAccess');
		$this->ledger->expects(self::never())->method(self::anything());

		self::assertSame([400, ['error' => $error]], $this->trace($params));

	}//end testBadInputIsRefused()

	/**
	 * The caller is checked first, then the administration.
	 *
	 * @return void
	 */
	public function testCallerAndAdministration(): void {
		$this->context->method('currentUserId')->willReturnOnConsecutiveCalls(null, 'alice', 'alice');
		$this->context->method('canAccess')->willReturnCallback(
			static function (string $administrationId): bool {
				if ($administrationId === 'adm-boom') {
					throw new \RuntimeException('ctx');
				}

				return false;
			}
		);
		$this->ledger->expects(self::never())->method(self::anything());

		self::assertSame([401, ['error' => 'Not authenticated']], $this->trace([]));
		self::assertSame([404, ['error' => 'Administration not found']], $this->trace(['administration_id' => ' adm-1 ', 'location_id' => 'l', 'sku' => 's']));
		self::assertSame([500, ['error' => 'Failed to authorise stock ledger']], $this->trace(['administration_id' => 'adm-boom', 'location_id' => 'l', 'sku' => 's']));
		self::assertSame(['StockLedgerController: failed to check administration access|{"administrationId":"adm-boom","exception":"ctx"}'], $this->logs);

	}//end testCallerAndAdministration()

	/**
	 * An allowed trace reports on hand, reserved, available and the trace.
	 *
	 * @return void
	 */
	public function testTrace(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willReturn(true);
		$calls = [];
		$this->ledger->method('quantityForLocation')->willReturnCallback(
			function (string $a, string $l, string $s) use (&$calls): float {
				$calls[] = "on:$a:$l:$s";
				return 10.5;
			}
		);
		$this->ledger->method('reservedForLocation')->willReturnCallback(
			function (string $a, string $l, string $s) use (&$calls): float {
				$calls[] = "res:$a:$l:$s";
				return 2.25;
			}
		);
		$this->ledger->method('traceLocation')->willReturnCallback(
			function (string $a, string $l, string $s) use (&$calls): array {
				$calls[] = "trace:$a:$l:$s";
				return [['m' => 1]];
			}
		);

		self::assertSame(
			[200, ['administrationId' => 'adm-1', 'locationId' => 'loc-1', 'sku' => 'S.1', 'onHand' => 10.5, 'reserved' => 2.25, 'available' => 8.25, 'trace' => [['m' => 1]]]],
			$this->trace(['administration_id' => 'adm-1', 'location_id' => ' loc-1', 'sku' => 'S.1 '])
		);
		self::assertSame(['on:adm-1:loc-1:S.1', 'res:adm-1:loc-1:S.1', 'trace:adm-1:loc-1:S.1'], $calls);

	}//end testTrace()

	/**
	 * A failing ledger read is logged with the location and a 500.
	 *
	 * @return void
	 */
	public function testLedgerFailure(): void {
		$this->context->method('currentUserId')->willReturn('alice');
		$this->context->method('canAccess')->willReturn(true);
		$this->ledger->method('quantityForLocation')->willReturn(1.0);
		$this->ledger->method('reservedForLocation')->willThrowException(new \RuntimeException('db'));
		$this->ledger->expects(self::never())->method('traceLocation');

		self::assertSame([500, ['error' => 'Failed to compute stock-ledger trace']], $this->trace(['administration_id' => 'adm-1', 'location_id' => 'loc-1', 'sku' => 's']));
		self::assertSame(['StockLedgerController: failed to compute stock-ledger trace|{"administrationId":"adm-1","locationId":"loc-1","sku":"s","exception":"db"}'], $this->logs);

	}//end testLedgerFailure()
}//end class
