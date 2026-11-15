<?php

/**
 * Golden characterisation of RevenueRecognitionService::computeRecurring.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Recognition
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

namespace OCA\Shillinq\Tests\Unit\Recognition;

use OCA\Shillinq\Service\RevenueRecognitionService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins the recognised, one-off, ARR, currency and line figures over a grid of
 * lines, terms and periods, plus the log lines.
 */
final class RevenueRecognitionGoldenTest extends TestCase {

	/**
	 * Build the service over orders and lines; `$fail` makes the store throw.
	 *
	 * @param array<int,array<string,mixed>> $orders SalesOrders.
	 * @param array<int,array<string,mixed>> $lines  SalesOrderLines.
	 * @param array<int,mixed>               $logs   Recorded log lines (by reference).
	 * @param boolean                        $fail   Whether every read throws.
	 *
	 * @return RevenueRecognitionService
	 */
	private function service(array $orders, array $lines, array &$logs, bool $fail = false): RevenueRecognitionService {
		$store = new class(['SalesOrder' => $orders, 'SalesOrderLine' => $lines], $fail) {
			private string $schema = '';

			public function __construct(private array $rows, private bool $fail) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $config): array {
				if ($this->fail === true) {
					throw new \RuntimeException('down');
				}
				return array_values(array_filter($this->rows[$this->schema], static fn (array $row): bool => ($row['administrationId'] ?? '') === $config['filters']['administrationId']));
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$logs): void {
				$logs[] = [$message, $context];
			}
		);

		return new RevenueRecognitionService($container, $config, $logger);

	}//end service()

	/**
	 * Pin every figure and log line over the grid.
	 *
	 * @return void
	 */
	public function testFiguresMatchTheGolden(): void {
		$a = static fn (array $row): array => $row + ['administrationId' => 'adm'];
		$orders = [
			$a(['orderId' => 'o1', 'termStart' => '2026-01-15', 'termEnd' => '2026-12-31', 'currency' => 'USD']),
			$a(['orderId' => 'o2', 'termStart' => '2025-07-01', 'currency' => '']),
			$a(['orderId' => '', 'currency' => 'GBP']),
			['orderId' => 'o9', 'administrationId' => 'other', 'currency' => 'JPY'],
		];
		$lines = [];
		$frequencies = ['MONTHLY', 'QUARTERLY', 'ANNUALLY', 'WEEKLY', 'FORTNIGHTLY', 'DAILY', ''];
		foreach ($frequencies as $i => $frequency) {
			$lines[] = $a(['lineId' => 'r' . $i, 'orderId' => (($i % 2) === 0) ? 'o1' : 'o2', 'nature' => 'RECURRING', 'frequency' => $frequency, 'amount' => 100 + $i]);
		}
		$lines[] = $a(['lineId' => 'r-own', 'nature' => 'RECURRING', 'frequency' => 'MONTHLY', 'amount' => '12.345', 'termStart' => ' 2026-03-31 ', 'termEnd' => '2026-04-01']);
		$lines[] = $a(['lineId' => 'r-none', 'nature' => 'RECURRING', 'frequency' => 'MONTHLY', 'amount' => 5]);
		$lines[] = $a(['lineId' => 'r-bad', 'nature' => 'RECURRING', 'frequency' => 'MONTHLY', 'amount' => 5, 'termStart' => 'soon']);
		$lines[] = $a(['lineId' => 'p-in', 'orderId' => 'o1', 'nature' => 'ONE_OFF', 'recognitionMethod' => 'POINT_IN_TIME', 'recognitionDate' => '2026-02-01', 'amount' => 500]);
		$lines[] = $a(['lineId' => 'p-out', 'nature' => 'ONE_OFF', 'recognitionMethod' => 'POINT_IN_TIME', 'recognitionDate' => '2027-01-01', 'amount' => 500]);
		$lines[] = $a(['lineId' => 'p-none', 'nature' => 'ONE_OFF', 'recognitionMethod' => 'POINT_IN_TIME', 'amount' => 500]);
		$lines[] = $a(['lineId' => 'ot', 'orderId' => 'o1', 'nature' => 'ONE_OFF', 'recognitionMethod' => 'OVER_TIME', 'amount' => 1200]);
		$lines[] = $a(['lineId' => 'ot-open', 'orderId' => 'o2', 'nature' => 'ONE_OFF', 'amount' => 999.99]);
		$lines[] = $a(['lineId' => 'ot-none', 'nature' => 'ONE_OFF', 'recognitionMethod' => 'OVER_TIME', 'amount' => 50]);
		$lines[] = $a(['lineId' => 'other', 'nature' => 'SERVICE', 'amount' => 1]);
		$lines[] = $a(['lineId' => 'late-currency', 'orderId' => '', 'nature' => 'X']);

		$actual = [];
		foreach ([['2026-01-01', '2026-03-31'], ['2026-03-15', '2026-03-15'], ['2025-01-01', '2027-12-31'], ['2026-06-01', '2026-01-01'], ['', '2026-12-31']] as [$from, $to]) {
			$logs = [];
			$result = $this->service($orders, $lines, $logs)->computeRecurring('adm', $from, $to);
			$actual[$from . '..' . $to] = ['result' => $result, 'logs' => $logs];
		}
		$logs = [];
		$actual['empty'] = ['result' => $this->service([], [], $logs)->computeRecurring('none', '2026-01-01', '2026-12-31'), 'logs' => $logs];
		$logs = [];
		$actual['failing'] = ['result' => $this->service([], [], $logs, true)->computeRecurring('adm', '2026-01-01', '2026-12-31'), 'logs' => $logs];

		$golden = (string)file_get_contents(__DIR__ . '/fixtures/revenue-recognition-golden.json');
		self::assertSame($golden, json_encode($actual, (JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION)) . "\n");

	}//end testFiguresMatchTheGolden()
}//end class
