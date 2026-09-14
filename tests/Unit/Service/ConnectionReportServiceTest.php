<?php

/**
 * ConnectionReportService unit tests.
 *
 * The service tells integriq's connection registry which adapter answers for
 * each adapter family shillinq calls. Every test here guards one way it could
 * quietly stop doing that: sending the wrong app or key, calling a log-only
 * adapter configured, letting one broken binding stop the other reports,
 * turning a listener's failure into a failed job, or logging a fault when
 * integriq is simply not installed.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/external-connections/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Shillinq\Service\ConnectionReportService;
use OCA\Shillinq\Service\External\DepositPayment\DepositPaymentAdapterInterface;
use OCA\Shillinq\Service\External\DepositPayment\LogDepositPaymentAdapter;
use OCA\Shillinq\Service\External\Mollie\LogMolliePaymentAdapter;
use OCA\Shillinq\Service\External\Mollie\MolliePaymentAdapterInterface;
use OCA\Shillinq\Service\External\TreasuryRate\LogTreasuryRateAdapter;
use OCA\Shillinq\Service\External\TreasuryRate\TreasuryRateAdapterInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for ConnectionReportService.
 *
 * @covers \OCA\Shillinq\Service\ConnectionReportService
 */
class ConnectionReportServiceTest extends TestCase {

	/**
	 * Mocked event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every event handed to the dispatcher.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->sent = [];
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);
	}//end setUp()

	/**
	 * A container that answers with the given bindings, or throws for a missing one.
	 *
	 * @param array<string, object> $bindings Adapter per interface.
	 *
	 * @return ContainerInterface
	 */
	private function container(array $bindings): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($bindings): object {
				if (isset($bindings[$id]) === false) {
					throw new RuntimeException('No binding for ' . $id);
				}

				return $bindings[$id];
			}
		);

		return $container;
	}//end container()

	/**
	 * The bindings Application::register() ships: a log-only adapter per port.
	 *
	 * @return array<string, object>
	 */
	private function shippedBindings(): array {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return [
			MolliePaymentAdapterInterface::class => new LogMolliePaymentAdapter(logger: $logger),
			TreasuryRateAdapterInterface::class => new LogTreasuryRateAdapter(logger: $logger),
			DepositPaymentAdapterInterface::class => new LogDepositPaymentAdapter(logger: $logger),
		];
	}//end shippedBindings()

	/**
	 * The service as production builds it.
	 *
	 * @param array<string, object> $bindings Adapter per interface.
	 *
	 * @return ConnectionReportService
	 */
	private function service(array $bindings): ConnectionReportService {
		return new ConnectionReportService(
			container: $this->container(bindings: $bindings),
			eventDispatcher: $this->dispatcher,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * The sent events, keyed by connection key.
	 *
	 * @return array<string, ConnectionStatusReportedEvent>
	 */
	private function sentByKey(): array {
		$byKey = [];
		foreach ($this->sent as $event) {
			$this->assertInstanceOf(expected: ConnectionStatusReportedEvent::class, actual: $event);
			$byKey[$event->key] = $event;
		}

		return $byKey;
	}//end sentByKey()

	/**
	 * The shipped log-only adapters are reported simulated, one event per family.
	 *
	 * @return void
	 */
	public function testShippedLogOnlyAdaptersAreReportedSimulated(): void {
		$result = $this->service(bindings: $this->shippedBindings())->reportAdapterBindings();

		$this->assertSame(
			expected: ['mollie' => 'simulated', 'treasury-rates' => 'simulated', 'deposit-payment' => 'simulated'],
			actual: $result
		);
		$this->assertCount(expectedCount: 3, haystack: $this->sent);

		$mollie = $this->sentByKey()['mollie'];
		$this->assertSame(expected: 'shillinq', actual: $mollie->app);
		$this->assertSame(expected: 'mollie', actual: $mollie->key);
		$this->assertSame(expected: 'simulated', actual: $mollie->status);
		$this->assertSame(expected: 'A log-only adapter answers here. No payment reaches Mollie.', actual: $mollie->message);
	}//end testShippedLogOnlyAdaptersAreReportedSimulated()

	/**
	 * A bound adapter that is not dormant is reported configured, and says it is untested.
	 *
	 * @return void
	 */
	public function testARealAdapterIsReportedConfiguredWithoutClaimingATest(): void {
		$real = $this->createMock(originalClassName: TreasuryRateAdapterInterface::class);
		$real->method('isDormant')->willReturn(false);

		$bindings = $this->shippedBindings();
		$bindings[TreasuryRateAdapterInterface::class] = $real;

		$result = $this->service(bindings: $bindings)->reportAdapterBindings();

		$this->assertSame(expected: 'configured', actual: $result['treasury-rates']);
		$message = $this->sentByKey()['treasury-rates']->message;
		$this->assertStringContainsString(needle: 'A real adapter is bound: ' . get_class($real), haystack: $message);
		$this->assertStringContainsString(needle: 'does not test', haystack: $message);
	}//end testARealAdapterIsReportedConfiguredWithoutClaimingATest()

	/**
	 * A binding that throws is reported error, and the other families still report.
	 *
	 * @return void
	 */
	public function testABrokenBindingIsReportedErrorAndTheRestStillReport(): void {
		$bindings = $this->shippedBindings();
		unset($bindings[DepositPaymentAdapterInterface::class]);

		$result = $this->service(bindings: $bindings)->reportAdapterBindings();

		$this->assertSame(expected: 'error', actual: $result['deposit-payment']);
		$this->assertSame(expected: 'simulated', actual: $result['mollie']);
		$this->assertStringContainsString(
			needle: 'Shillinq could not load the adapter: No binding for ' . DepositPaymentAdapterInterface::class,
			haystack: $this->sentByKey()['deposit-payment']->message
		);
	}//end testABrokenBindingIsReportedErrorAndTheRestStillReport()

	/**
	 * Without integriq nothing is resolved, sent or logged.
	 *
	 * Only the class lookup is replaced. The stub makes the event class
	 * resolvable in this process, so absence is simulated at the one seam
	 * that asks.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSentOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('error');

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$service = new class($container, $this->dispatcher, $this->logger) extends ConnectionReportService {

			/**
			 * Integriq is not installed, so no class resolves.
			 *
			 * @param string $eventClass The class name asked for.
			 *
			 * @return string|null Always null.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};

		$this->assertSame(expected: [], actual: $service->reportAdapterBindings());
	}//end testWithoutIntegriqNothingIsSentOrLogged()

	/**
	 * The class lookup answers null for a class nobody ships, and the class for the stub.
	 *
	 * This is the real guard, not the test double above.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		$method = new ReflectionMethod(ConnectionReportService::class, 'resolveEventClass');
		$service = $this->service(bindings: []);

		$this->assertNull(actual: $method->invoke($service, 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		$this->assertSame(
			expected: '\\' . ConnectionReportService::STATUS_EVENT,
			actual: $method->invoke($service, ConnectionReportService::STATUS_EVENT)
		);
	}//end testTheLookupAnswersNullForAnAbsentClass()

	/**
	 * The event name is the one the contract fixes.
	 *
	 * A string class name is exactly the reference that rots into a silent
	 * no-op after a rename, so it is compared to the stub's real name.
	 *
	 * @return void
	 */
	public function testTheEventNameIsTheContractName(): void {
		$this->assertSame(expected: ConnectionStatusReportedEvent::class, actual: ConnectionReportService::STATUS_EVENT);
	}//end testTheEventNameIsTheContractName()

	/**
	 * A listener that throws never escapes into the job, and is logged per family.
	 *
	 * @return void
	 */
	public function testAThrowingListenerNeverEscapes(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->exactly(3))->method('warning')
			->with($this->stringContains(string: 'could not send'), $this->arrayHasKey(key: 'key'));

		$service = new ConnectionReportService(
			container: $this->container(bindings: $this->shippedBindings()),
			eventDispatcher: $dispatcher,
			logger: $this->logger,
		);

		$this->assertSame(expected: [], actual: $service->reportAdapterBindings());
	}//end testAThrowingListenerNeverEscapes()

	/**
	 * Every reported family is bound to a log-only adapter that says it is dormant.
	 *
	 * If a shipped log-only adapter stopped answering `isDormant()` true, the
	 * page would call a stub configured. The shipped classes are asked directly.
	 *
	 * @return void
	 */
	public function testEveryShippedAdapterOfAReportedFamilyIsDormant(): void {
		foreach ($this->shippedBindings() as $interface => $adapter) {
			$this->assertInstanceOf(expected: $interface, actual: $adapter);
			$this->assertTrue(condition: $adapter->isDormant(), message: get_class($adapter) . ' must report itself dormant');
		}

		$this->assertSame(
			expected: array_keys($this->shippedBindings()),
			actual: array_column(ConnectionReportService::FAMILIES, 'interface')
		);
	}//end testEveryShippedAdapterOfAReportedFamilyIsDormant()
}//end class
