<?php

/**
 * Characterisation tests for DbaInvoiceMonitorListener.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
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

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\Shillinq\Listener\DbaInvoiceMonitorListener;
use OCA\Shillinq\Service\DBAVbarMonitorService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the payload extraction, the assess/flag calls and every log record.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class DbaInvoiceMonitorListenerTest extends TestCase {

	public function testOutcomes(): void {
		$invoice = [
			'dbaOpdrachtId' => 'dba-1',
			'administrationId' => 'adm-1',
			'@self' => ['id' => 'inv-1'],
			'bedragCents' => '120000',
			'hours' => '10',
		];
		$over = ['result' => 'OVER', 'uurtariefCents' => '12000', 'vbarGrensCents' => 9000];
		$ok = ['result' => 'OK'];

		$plain = new Event();
		self::assertSame(['calls' => [], 'logs' => []], $this->outcome($plain, $over));
		self::assertSame(['calls' => [], 'logs' => []], $this->outcome($this->objectEvent(['dbaOpdrachtId' => ''] + $invoice), $over));
		self::assertSame(
			[
				'calls' => [
					['assess', 120000, 10.0, 'adm-1'],
					['emitFlag', 'dba-1', 'adm-1', 'inv-1', 12000, 9000],
				],
				'logs' => [],
			],
			$this->outcome($this->objectEvent($invoice), $over)
		);
		self::assertSame(
			[['assess', 120000, 10.0, 'adm-1']],
			$this->outcome($this->objectEvent($invoice), $ok)['calls']
		);
		$fallbacks = ['dbaOpdrachtId' => 'dba-2', 'id' => 'inv-2', 'totalAmountCents' => 5000, 'hoursBilled' => 2.5];
		self::assertSame(
			[['assess', 5000, 2.5, ''], ['emitFlag', 'dba-2', '', 'inv-2', 0, 0]],
			$this->outcome($this->entityEvent($fallbacks), ['result' => 'OVER'])['calls']
		);
		self::assertSame(
			[['assess', 0, 0.0, '']],
			$this->outcome($this->dataEvent(['dbaOpdrachtId' => 'dba-3']), $over)['calls']
		);
		self::assertSame(
			[
				[
					'warning',
					'DbaInvoiceMonitorListener: VBAR assess/emit failed (non-blocking).',
					['assignmentId' => 'dba-1', 'invoiceId' => 'inv-1', 'exception' => 'assess down'],
				],
			],
			$this->outcome($this->objectEvent($invoice), '*throw*')['logs']
		);
		self::assertSame(
			['calls' => [], 'logs' => [['debug', 'DbaInvoiceMonitorListener: extract failed', ['exception' => 'boom']]]],
			$this->outcome($this->throwingEvent(), $over)
		);
	}

	private function objectEvent(array $payload): Event {
		return new class($payload) extends Event {

			public function __construct(private array $payload) {
			}

			public function getObject(): array {
				return $this->payload;
			}
		};
	}

	private function entityEvent(array $payload): Event {
		return new class($payload) extends Event {

			public function __construct(private array $payload) {
			}

			public function getObject(): object {
				return new class($this->payload) {

					public function __construct(private array $payload) {
					}

					public function getObject(): array {
						return $this->payload;
					}
				};
			}
		};
	}

	private function dataEvent(array $payload): Event {
		return new class($payload) extends Event {

			public function __construct(private array $payload) {
			}

			public function getObject(): string {
				return 'not-an-object';
			}

			public function getData(): array {
				return $this->payload;
			}
		};
	}

	private function throwingEvent(): Event {
		return new class extends Event {

			public function getObject(): array {
				throw new \RuntimeException('boom');
			}
		};
	}

	/**
	 * Dispatch one event and capture the monitor calls and log records.
	 *
	 * @param Event $event The event.
	 * @param mixed $assessment The assess() answer, or '*throw*'.
	 *
	 * @return array<string,mixed>
	 */
	private function outcome(Event $event, mixed $assessment): array {
		$calls = [];
		$monitor = $this->createMock(DBAVbarMonitorService::class);
		$monitor->method('assess')->willReturnCallback(
			function (int $amountCents, float $hours, string $administrationId) use (&$calls, $assessment): array {
				$calls[] = ['assess', $amountCents, $hours, $administrationId];
				if ($assessment === '*throw*') {
					throw new \RuntimeException('assess down');
				}

				return $assessment;
			}
		);
		$monitor->method('emitFlag')->willReturnCallback(
			function (string $assignmentId, string $administrationId, string $invoiceId, int $hourlyRateCents, int $vbarGrensCents) use (&$calls): bool {
				$calls[] = ['emitFlag', $assignmentId, $administrationId, $invoiceId, $hourlyRateCents, $vbarGrensCents];
				return true;
			}
		);
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};

		(new DbaInvoiceMonitorListener($monitor, $logger))->handle($event);

		return ['calls' => $calls, 'logs' => $logger->records];
	}
}
