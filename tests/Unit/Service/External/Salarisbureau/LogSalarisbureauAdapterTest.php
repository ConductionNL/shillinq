<?php

/**
 * Unit tests for LogSalarisbureauAdapter.
 *
 * Without an outbound connector a payroll run is deferred, and no employee BSN
 * reaches the log.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\External\Salarisbureau
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

namespace OCA\Shillinq\Tests\Unit\Service\External\Salarisbureau;

use OCA\Shillinq\Service\External\Salarisbureau\LogSalarisbureauAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Shillinq\Service\External\Salarisbureau\LogSalarisbureauAdapter
 * @uses \OCA\Shillinq\Service\External\Salarisbureau\SalarisbureauPayrollRunResult
 */
final class LogSalarisbureauAdapterTest extends TestCase {

	/**
	 * The info lines the adapter logged, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The adapter, over a logger that records every info line.
	 *
	 * @return LogSalarisbureauAdapter The adapter.
	 */
	private function adapter(): LogSalarisbureauAdapter {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->logged[] = [$message, $context];
			}
		);

		return new LogSalarisbureauAdapter(logger: $logger);
	}//end adapter()

	/**
	 * Each employee row loses its BSN before logging; a non-row is redacted whole.
	 *
	 * @return void
	 */
	public function testEmployeeBsnsNeverReachTheLog(): void {
		$result = $this->adapter()->submit(
			[
				'bureau' => 'nmbrs',
				'employees' => [
					['bsn' => '123456782', 'name' => 'A. Jansen', 'gross' => 3200],
					'raw row',
				],
			]
		);

		$this->assertSame('DEFERRED', $result->deliveryStatus);
		$this->assertMatchesRegularExpression('/^salarisbureau-log-[0-9a-f]{16}$/', $result->runId);
		$this->assertSame('nmbrs', $result->extras['bureau']);
		$this->assertSame(
			[
				'runId' => $result->runId,
				'bureau' => 'nmbrs',
				'payload' => [
					'bureau' => 'nmbrs',
					'employees' => [['name' => 'A. Jansen', 'gross' => 3200], ['_redacted' => true]],
				],
			],
			$this->logged[0][1]
		);
		$this->assertStringNotContainsString('123456782', json_encode($this->logged));
	}//end testEmployeeBsnsNeverReachTheLog()

	/**
	 * A run naming no bureau is logged under "unknown".
	 *
	 * @return void
	 */
	public function testAnUnnamedBureauIsUnknown(): void {
		$result = $this->adapter()->submit(['period' => '2026-11']);

		$this->assertSame('unknown', $result->extras['bureau']);
		$this->assertSame(['period' => '2026-11'], $this->logged[0][1]['payload']);
	}//end testAnUnnamedBureauIsUnknown()


	/**
	 * The adapter reports itself dormant.
	 *
	 * @return void
	 */
	public function testTheAdapterIsDormant(): void {
		$this->assertTrue($this->adapter()->isDormant());
		$this->assertSame([], $this->logged);
	}//end testTheAdapterIsDormant()
}//end class
