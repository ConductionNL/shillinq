<?php

/**
 * Golden test for the BookingCancellationGuard cancel decision.
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
 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-27
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\BookingCancellationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins decision, every log record and every store call per scenario.
 *
 * Regenerate with BOOKING_CANCEL_GOLDEN_WRITE=1.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class BookingCancellationGuardGoldenTest extends TestCase {

	private const GOLDEN = __DIR__.'/fixtures/booking-cancellation-guard.golden.json';

	public function testDecisionsMatchGolden(): void {
		$order = ['orderId' => 'o1', 'invoiceId' => 'inv-1', 'administrationId' => 'adm-1'];
		$invoice = ['invoiceId' => 'inv-1', 'state' => 'issued'];
		$scenarios = [
			'happy' => [$order, ['Invoice' => [$invoice]]],
			'happyOverdue' => [$order, ['Invoice' => [['state' => 'overdue']]]],
			'fallbackLookup' => [null, ['BookingOrder' => [$order], 'Invoice' => [$invoice]]],
			'orderNotFound' => [null, []],
			'noInvoice' => [['orderId' => 'o1'], []],
			'noAdministration' => [['invoiceId' => 'inv-1'], ['Invoice' => [$invoice]]],
			'invoiceMissing' => [$order, []],
			'invoicePaid' => [$order, ['Invoice' => [['state' => 'paid']]]],
			'invoiceStateless' => [$order, ['Invoice' => [[]]]],
			'creditNoteExists' => [$order, ['Invoice' => [$invoice], 'CreditNote' => [['id' => 'cn-1']]]],
			'storeDown' => [$order, ['*throw*' => true]],
		];

		$actual = [];
		foreach ($scenarios as $name => [$object, $records]) {
			$actual[$name] = $this->scenario($object, $records);
		}

		if (getenv('BOOKING_CANCEL_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."\n");
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame($golden, $actual);
	}

	/**
	 * Run one scenario and capture result, logs and store calls.
	 *
	 * @param array<string,mixed>|null $object The order passed in, or null.
	 * @param array<string,mixed> $records Records per schema.
	 *
	 * @return array<string,mixed>
	 */
	private function scenario(array|null $object, array $records): array {
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$store = new class($records) {

			public array $calls = [];

			private string $schema = '';

			public function __construct(private array $records) {
			}

			public function setRegister(string $register): self {
				$this->calls[] = 'register:'.$register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				$this->calls[] = 'schema:'.$schema;
				return $this;
			}

			public function findAll(array $args = []): array {
				$this->calls[] = ['findAll' => $args];
				if (isset($this->records['*throw*']) === true) {
					throw new \RuntimeException('store down');
				}

				return ($this->records[$this->schema] ?? []);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$guard = new BookingCancellationGuard($container, $appConfig, $logger);

		return [
			'result' => $guard->canCancel('o1', $object),
			'logs' => $logger->records,
			'calls' => $store->calls,
		];
	}
}
