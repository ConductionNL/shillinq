<?php

/**
 * Golden test for InvoiceFromBookingGuard::canComplete.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\InvoiceFromBookingGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;

/**
 * Pins the decision, every lookup and every log line per order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InvoiceFromBookingCompleteGoldenTest extends TestCase {

	/**
	 * Recorded lookups and log lines of the current scenario.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testDecisionsMatchGolden(): void {
		$order = static fn (array $over): array => array_merge(
			['orderId' => 'ord-1', 'administrationId' => 'adm-1', 'completedAt' => '2026-05-01T10:00:00Z'],
			$over
		);
		$scenarios = [
			'storedComplete' => ['ord-stored', null, false],
			'notFound' => ['ord-404', null, false],
			'storeBroken' => ['ord-stored', null, true],
			'notCompleted' => ['ord-1', $order(['completedAt' => null]), false],
			'blankCompleted' => ['ord-1', $order(['completedAt' => '']), false],
			'askedForOtherId' => ['ord-asked', $order(['completedAt' => null]), false],
			'alreadyInvoiced' => ['ord-1', $order(['invoiceId' => 'inv-9']), false],
			'blankInvoice' => ['ord-1', $order(['invoiceId' => '']), false],
			'noDepositNeeded' => ['ord-1', $order(['depositRequired' => false]), false],
			'depositNoPayment' => ['ord-1', $order(['depositRequired' => true]), false],
			'depositNoOrderId' => ['ord-x', ['completedAt' => 'x', 'depositRequired' => 1], false],
			'depositAuthorised' => ['ord-1', $order(['depositRequired' => true, 'depositPaymentId' => 'dp-ok']), false],
			'depositPending' => ['ord-1', $order(['depositRequired' => true, 'depositPaymentId' => 'dp-pending']), false],
			'depositMissing' => ['ord-1', $order(['depositRequired' => true, 'depositPaymentId' => 'dp-none']), false],
			'depositOtherAdmin' => ['ord-1', $order(['depositRequired' => true, 'depositPaymentId' => 'dp-ok', 'administrationId' => 'adm-2']), false],
			'throws' => ['ord-1', $order(['completedAt' => new \stdClass()]), false],
		];
		$result = [];
		foreach ($scenarios as $name => [$orderId, $object, $broken]) {
			$this->calls = [];
			$result[$name] = ['allowed' => $this->guard(broken: $broken)->canComplete($orderId, $object), 'calls' => $this->calls];
		}

		$file = __DIR__.'/fixtures/invoice-from-booking-complete.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		if (getenv('BOOKING_INVOICE_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testDecisionsMatchGolden()

	/**
	 * A guard over a recording store.
	 *
	 * @param bool $broken Whether the container lookup throws.
	 *
	 * @return InvoiceFromBookingGuard
	 */
	private function guard(bool $broken): InvoiceFromBookingGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		if ($broken === true) {
			$container->method('get')->willThrowException(new \RuntimeException('no object service'));
		} else {
			$container->method('get')->willReturn($this->store());
		}

		return new InvoiceFromBookingGuard(container: $container, appConfig: $appConfig, logger: $this->logger());

	}//end guard()

	/**
	 * The recording store.
	 *
	 * @return object
	 */
	private function store(): object {
		$rows = [
			'BookingOrder' => [['orderId' => 'ord-stored', 'administrationId' => 'adm-1', 'completedAt' => '2026-05-01']],
			'DepositPayment' => [
				['depositPaymentId' => 'dp-ok', 'administrationId' => 'adm-1', 'state' => 'authorized'],
				['depositPaymentId' => 'dp-pending', 'administrationId' => 'adm-1', 'state' => 'pending'],
			],
		];
		$test = $this;
		return new class($rows, $test) {

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Construct.
			 *
			 * @param array  $rows Rows.
			 * @param object $test Recorder.
			 */
			public function __construct(private array $rows, private object $test) {
			}//end __construct()

			/**
			 * Set register.
			 *
			 * @param string $register Register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Set schema.
			 *
			 * @param string $schema Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Find rows matching every filter.
			 *
			 * @param array $params Params.
			 *
			 * @return array
			 */
			public function findAll(array $params=[]): array {
				$this->test->calls[] = ['findAll', $this->schema, $params];
				$filters = ($params['filters'] ?? []);
				return array_values(
					array_filter(
						($this->rows[$this->schema] ?? []),
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
		};

	}//end store()

	/**
	 * A logger recording into $this->calls.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$test = $this;
		return new class($test) extends AbstractLogger {

			/**
			 * Construct.
			 *
			 * @param object $test Recorder.
			 */
			public function __construct(private object $test) {
			}//end __construct()

			/**
			 * Record.
			 *
			 * @param mixed              $level   Level.
			 * @param string|\Stringable $message Message.
			 * @param array              $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context=[]): void {
				$this->test->calls[] = ['log', $level, (string) $message, $context];
			}//end log()
		};

	}//end logger()
}//end class
