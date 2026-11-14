<?php

/**
 * Unit tests for ListenerRegistrar.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener\Registration
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

namespace OCA\Shillinq\Tests\Unit\Listener\Registration;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Event\RegisterLeafProvidersEvent;
use OCA\Shillinq\Dashboard\DashboardWidgetRegistration;
use OCA\Shillinq\Lifecycle\CommitmentGuardServices;
use OCA\Shillinq\Listener\AppointmentCreatedListener;
use OCA\Shillinq\Listener\BbvAllocationValidationListener;
use OCA\Shillinq\Listener\BookingCreatedTimelinePublishListener;
use OCA\Shillinq\Listener\CommitmentMaterialisationListener;
use OCA\Shillinq\Listener\CommitmentTransitionListener;
use OCA\Shillinq\Listener\ContractObligationTaskListener;
use OCA\Shillinq\Listener\DeepLinkRegistrationListener;
use OCA\Shillinq\Listener\DeliveryDispatchListener;
use OCA\Shillinq\Listener\ExtractionCompletedListener;
use OCA\Shillinq\Listener\FeeScheduleValidationListener;
use OCA\Shillinq\Listener\GLTransactionComplianceCacheListener;
use OCA\Shillinq\Listener\InnovatieboxAuditTrailListener;
use OCA\Shillinq\Listener\LeaseActivationListener;
use OCA\Shillinq\Listener\OssPaymentReconciliationListener;
use OCA\Shillinq\Listener\PaymentRequestLeafRegistrationListener;
use OCA\Shillinq\Listener\PeppolInboundUblInvoiceListener;
use OCA\Shillinq\Listener\PosStockDecrementListener;
use OCA\Shillinq\Listener\ReconciliationMatchToReportListener;
use OCA\Shillinq\Listener\Registration\AdministrationConfigGuardServices;
use OCA\Shillinq\Listener\Registration\BbvValidationRegistration;
use OCA\Shillinq\Listener\Registration\FieldRequirementRegistration;
use OCA\Shillinq\Listener\Registration\GuardTagServices;
use OCA\Shillinq\Listener\Registration\ImportBatchServices;
use OCA\Shillinq\Listener\Registration\ListenerRegistrar;
use OCA\Shillinq\Listener\Registration\ObjectRequestSettlementRegistration;
use OCA\Shillinq\Listener\Registration\OrderFulfilmentGateRegistration;
use OCA\Shillinq\Listener\Registration\PaymentControlsRegistration;
use OCA\Shillinq\Listener\Registration\ProjectHoursRegistration;
use OCA\Shillinq\Listener\Registration\PurchasingControlsRegistration;
use OCA\Shillinq\Listener\Registration\ReportingRegistration;
use OCA\Shillinq\Listener\Registration\ReportScheduleControlsRegistration;
use OCA\Shillinq\Listener\Registration\SigningDelegationRegistration;
use OCA\Shillinq\Listener\StockMoveTransitionedListener;
use OCA\Shillinq\Listener\SupplierInvoiceWarningListener;
use OCA\Shillinq\Listener\TenderNedAwardDetectedListener;
use OCA\Shillinq\Support\FleetAppId;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the event bindings the Listener component states for Application.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(ListenerRegistrar::class)]
#[UsesClass(AdministrationConfigGuardServices::class)]
#[UsesClass(BbvValidationRegistration::class)]
#[UsesClass(FieldRequirementRegistration::class)]
#[UsesClass(GuardTagServices::class)]
#[UsesClass(ImportBatchServices::class)]
#[UsesClass(ObjectRequestSettlementRegistration::class)]
#[UsesClass(OrderFulfilmentGateRegistration::class)]
#[UsesClass(PaymentControlsRegistration::class)]
#[UsesClass(ProjectHoursRegistration::class)]
#[UsesClass(PurchasingControlsRegistration::class)]
#[UsesClass(ReportingRegistration::class)]
#[UsesClass(ReportScheduleControlsRegistration::class)]
#[UsesClass(SigningDelegationRegistration::class)]
#[UsesClass(DashboardWidgetRegistration::class)]
#[UsesClass(CommitmentGuardServices::class)]
#[UsesClass(FleetAppId::class)]
final class ListenerRegistrarTest extends TestCase {

	/**
	 * Captured registerEventListener() calls as [event, listener] pairs, in order.
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	private array $listeners = [];

	/**
	 * Captured registerService() names.
	 *
	 * @var array<int, string>
	 */
	private array $services = [];

	/**
	 * Captured addServiceListener() calls as [event, listener] pairs, in order.
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	private array $dispatched = [];

	/**
	 * The server container that was live before a test replaced it.
	 *
	 * @var object|null
	 */
	private ?object $previousServer = null;

	/**
	 * Restore the server container a test may have replaced.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->previousServer !== null) {
			\OC::$server = $this->previousServer;
			$this->previousServer = null;
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * A registration context that records what was registered on it.
	 *
	 * @return IRegistrationContext The recording context.
	 */
	private function recordingContext(): IRegistrationContext {
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener, int $priority = 0): void {
				$this->listeners[] = [$event, $listener];
			}
		);
		$context->method('registerService')->willReturnCallback(
			function (string $name, callable $factory, bool $shared = true): void {
				$this->services[] = $name;
			}
		);

		return $context;

	}//end recordingContext()

	/**
	 * A dispatcher that records every service listener added to it.
	 *
	 * @return IEventDispatcher The recording dispatcher.
	 */
	private function recordingDispatcher(): IEventDispatcher {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('addServiceListener')->willReturnCallback(
			function (string $event, string $listener, int $priority = 0): void {
				$this->dispatched[] = [$event, $listener];
			}
		);

		return $dispatcher;

	}//end recordingDispatcher()

	/**
	 * Swap the server container for one that hands out the given logger.
	 *
	 * @param LoggerInterface $logger The logger the fallback should receive.
	 *
	 * @return void
	 */
	private function serveLogger(LoggerInterface $logger): void {
		$this->previousServer = \OC::$server;
		\OC::$server = new class($logger) {
			/**
			 * Build the container.
			 *
			 * @param LoggerInterface $logger The logger to serve.
			 */
			public function __construct(private LoggerInterface $logger) {
			}//end __construct()

			/**
			 * Resolve a service.
			 *
			 * @param string $service The service id.
			 *
			 * @return object The logger.
			 */
			public function get(string $service): object {
				if ($service !== LoggerInterface::class) {
					throw new \RuntimeException('Unexpected service ' . $service);
				}

				return $this->logger;
			}//end get()
		};

	}//end serveLogger()

	/**
	 * The deep-link listener is registered first, on the deep-link event.
	 *
	 * @return void
	 */
	public function testTheDeepLinkListenerIsRegisteredFirst(): void {
		(new ListenerRegistrar())->register($this->recordingContext());

		self::assertSame([DeepLinkRegistrationEvent::class, DeepLinkRegistrationListener::class], $this->listeners[0]);

	}//end testTheDeepLinkListenerIsRegisteredFirst()

	/**
	 * The leaf provider is registered when OpenRegister ships the leaf event.
	 *
	 * @return void
	 */
	public function testThePaymentRequestLeafProviderIsRegisteredWhenTheEventExists(): void {
		self::assertTrue(class_exists(RegisterLeafProvidersEvent::class));

		(new ListenerRegistrar())->register($this->recordingContext());

		self::assertSame(
			[RegisterLeafProvidersEvent::class, PaymentRequestLeafRegistrationListener::class],
			$this->listeners[1]
		);

	}//end testThePaymentRequestLeafProviderIsRegisteredWhenTheEventExists()

	/**
	 * The inventory listeners keep their order on the transition event.
	 *
	 * @return void
	 */
	public function testStockMovesAreValuedBeforeDeliveriesDispatch(): void {
		(new ListenerRegistrar())->register($this->recordingContext());

		self::assertSame([ObjectTransitionedEvent::class, StockMoveTransitionedListener::class], $this->listeners[2]);
		self::assertSame([ObjectTransitionedEvent::class, DeliveryDispatchListener::class], $this->listeners[3]);
		self::assertSame([ObjectCreatedEvent::class, PeppolInboundUblInvoiceListener::class], $this->listeners[4]);

	}//end testStockMovesAreValuedBeforeDeliveriesDispatch()

	/**
	 * The fee schedule validation runs on both pre-save events.
	 *
	 * @return void
	 */
	public function testFeeScheduleValidationIsRegisteredOnBothPreSaveEvents(): void {
		(new ListenerRegistrar())->register($this->recordingContext());

		self::assertContains([ObjectCreatingEvent::class, FeeScheduleValidationListener::class], $this->listeners);
		self::assertContains([ObjectUpdatingEvent::class, FeeScheduleValidationListener::class], $this->listeners);

	}//end testFeeScheduleValidationIsRegisteredOnBothPreSaveEvents()

	/**
	 * The extraction listener hears the event under every app namespace filinq has had.
	 *
	 * @return void
	 */
	public function testExtractionListenerIsRegisteredForEveryFilinqNamespace(): void {
		(new ListenerRegistrar())->register($this->recordingContext());

		$events = [];
		foreach ($this->listeners as [$event, $listener]) {
			if ($listener === ExtractionCompletedListener::class) {
				$events[] = $event;
			}
		}

		self::assertSame(
			[
				'OCA\\Filinq\\Event\\FinancialExtractionCompletedEvent',
				'OCA\\DocuDesk\\Event\\FinancialExtractionCompletedEvent',
			],
			$events
		);

	}//end testExtractionListenerIsRegisteredForEveryFilinqNamespace()

	/**
	 * The commitment materialisation listener hears creates and transitions.
	 *
	 * @return void
	 */
	public function testCommitmentMaterialisationHearsCreatesAndTransitions(): void {
		(new ListenerRegistrar())->register($this->recordingContext());

		self::assertContains(['OCA\\Pipelinq\\Event\\PosStockMovedEvent', PosStockDecrementListener::class], $this->listeners);
		self::assertContains([ObjectCreatedEvent::class, CommitmentMaterialisationListener::class], $this->listeners);
		self::assertContains([ObjectTransitionedEvent::class, CommitmentMaterialisationListener::class], $this->listeners);
		self::assertContains([ObjectTransitionedEvent::class, CommitmentTransitionListener::class], $this->listeners);

	}//end testCommitmentMaterialisationHearsCreatesAndTransitions()

	/**
	 * The composed registrations run too, ending with the BBV validation.
	 *
	 * @return void
	 */
	public function testComposedRegistrationsRunAndBbvValidationIsLast(): void {
		(new ListenerRegistrar())->register($this->recordingContext());

		$last = array_slice($this->listeners, -2);
		self::assertSame(
			[
				[ObjectCreatingEvent::class, BbvAllocationValidationListener::class],
				[ObjectUpdatingEvent::class, BbvAllocationValidationListener::class],
			],
			$last
		);
		self::assertContains('OCA\Shillinq\Lifecycle\ImportBatchGuard::canParse', $this->services);
		self::assertContains('OCA\Shillinq\Lifecycle\OrderFulfilmentGuard::canVoltooien', $this->services);

	}//end testComposedRegistrationsRunAndBbvValidationIsLast()

	/**
	 * Without OpenRegister's subscription API, every filtered listener falls back
	 * to a plain global registration, in declaration order.
	 *
	 * @return void
	 */
	public function testSubscribeFallsBackToGlobalRegistrationsInOrder(): void {
		self::assertFalse(class_exists('OCA\\OpenRegister\\Event\\ObjectEventSubscription'));
		$this->serveLogger($this->createMock(LoggerInterface::class));

		(new ListenerRegistrar())->subscribe($this->recordingDispatcher());

		self::assertSame(
			[
				[ObjectCreatedEvent::class, AppointmentCreatedListener::class],
				[ObjectCreatedEvent::class, SupplierInvoiceWarningListener::class],
				[ObjectUpdatedEvent::class, SupplierInvoiceWarningListener::class],
				[ObjectCreatedEvent::class, ContractObligationTaskListener::class],
				[ObjectUpdatedEvent::class, ContractObligationTaskListener::class],
				[ObjectCreatedEvent::class, LeaseActivationListener::class],
				[ObjectUpdatedEvent::class, LeaseActivationListener::class],
				[ObjectCreatedEvent::class, OssPaymentReconciliationListener::class],
				[ObjectCreatedEvent::class, BookingCreatedTimelinePublishListener::class],
				[ObjectCreatedEvent::class, GLTransactionComplianceCacheListener::class],
				[ObjectUpdatedEvent::class, GLTransactionComplianceCacheListener::class],
				[ObjectCreatedEvent::class, InnovatieboxAuditTrailListener::class],
				[ObjectUpdatedEvent::class, InnovatieboxAuditTrailListener::class],
				[ObjectCreatedEvent::class, ReconciliationMatchToReportListener::class],
				[ObjectCreatedEvent::class, TenderNedAwardDetectedListener::class],
				[ObjectCreatedEvent::class, CommitmentTransitionListener::class],
			],
			$this->dispatched
		);

	}//end testSubscribeFallsBackToGlobalRegistrationsInOrder()

	/**
	 * Each unfiltered fallback is announced with a warning naming listener and event.
	 *
	 * @return void
	 */
	public function testEachFallbackLogsAWarningNamingListenerAndEvent(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$messages = [];
		$logger->expects(self::exactly(16))->method('warning')->willReturnCallback(
			function (string $message, array $context) use (&$messages): void {
				self::assertSame(['app' => 'shillinq'], $context);
				$messages[] = $message;
			}
		);
		$this->serveLogger($logger);

		(new ListenerRegistrar())->subscribe($this->recordingDispatcher());

		self::assertSame(
			'OpenRegister ObjectEventSubscription unavailable: ' . AppointmentCreatedListener::class
			. ' fell back to an UNFILTERED registration for ' . ObjectCreatedEvent::class
			. ' and will be invoked on every object write instance-wide.',
			$messages[0]
		);
		self::assertStringContainsString(ObjectUpdatedEvent::class, $messages[2]);
		self::assertStringContainsString(SupplierInvoiceWarningListener::class, $messages[2]);

	}//end testEachFallbackLogsAWarningNamingListenerAndEvent()
}//end class
