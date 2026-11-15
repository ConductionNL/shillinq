<?php

/**
 * Unit tests for the filtered subscription path of FilteredObjectListenerTrait.
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

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener\Registration;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Dashboard\CashPositionWidget;
use OCA\Shillinq\Dashboard\DashboardWidgetRegistration;
use OCA\Shillinq\Dashboard\OpenReceivablesWidget;
use OCA\Shillinq\Dashboard\ResultByMonthWidget;
use OCA\Shillinq\Dashboard\RevenueThisMonthWidget;
use OCA\Shillinq\Dashboard\TopCustomersWidget;
use OCA\Shillinq\Listener\AssignmentHoursListener;
use OCA\Shillinq\Listener\BbvAllocationValidationListener;
use OCA\Shillinq\Listener\GLLineResultStampListener;
use OCA\Shillinq\Listener\PurchaseOrderDecisionLockListener;
use OCA\Shillinq\Listener\Registration\BbvValidationRegistration;
use OCA\Shillinq\Listener\Registration\ObjectRequestSettlementRegistration;
use OCA\Shillinq\Listener\Registration\ProjectHoursRegistration;
use OCA\Shillinq\Listener\Registration\PurchasingControlsRegistration;
use OCA\Shillinq\Listener\Registration\ReportingRegistration;
use OCA\Shillinq\Listener\Registration\ReportScheduleControlsRegistration;
use OCA\Shillinq\Listener\ReportScheduleWriteListener;
use OCA\Shillinq\Listener\SupplierInvoiceApBindingLockListener;
use OCA\Shillinq\Notification\ScheduledReportNotifier;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Each registration binds exactly its listeners to exactly its events.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(BbvValidationRegistration::class)]
#[CoversClass(PurchasingControlsRegistration::class)]
#[CoversClass(ReportScheduleControlsRegistration::class)]
#[CoversClass(ProjectHoursRegistration::class)]
#[CoversClass(ReportingRegistration::class)]
#[UsesClass(DashboardWidgetRegistration::class)]
#[UsesClass(ObjectRequestSettlementRegistration::class)]
final class AreaRegistrationsTest extends TestCase {

	/**
	 * Captured registerEventListener() calls as [event, listener] pairs, in order.
	 *
	 * @var array<int, array{0:string,1:string}>
	 */
	private array $listeners = [];

	/**
	 * Captured registerNotifierService() classes.
	 *
	 * @var array<int, string>
	 */
	private array $notifiers = [];

	/**
	 * Captured registerDashboardWidget() classes.
	 *
	 * @var array<int, string>
	 */
	private array $widgets = [];

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
		$context->method('registerNotifierService')->willReturnCallback(
			function (string $notifier): void {
				$this->notifiers[] = $notifier;
			}
		);
		$context->method('registerDashboardWidget')->willReturnCallback(
			function (string $widget): void {
				$this->widgets[] = $widget;
			}
		);

		return $context;

	}//end recordingContext()

	/**
	 * The BBV validation runs on create and update, nothing else.
	 *
	 * @return void
	 */
	public function testBbvValidationIsBoundToBothPreSaveEvents(): void {
		(new BbvValidationRegistration())->register($this->recordingContext());

		self::assertSame(
			[
				[ObjectCreatingEvent::class, BbvAllocationValidationListener::class],
				[ObjectUpdatingEvent::class, BbvAllocationValidationListener::class],
			],
			$this->listeners
		);

	}//end testBbvValidationIsBoundToBothPreSaveEvents()

	/**
	 * The decision lock covers create and update; the AP binding lock only update.
	 *
	 * @return void
	 */
	public function testPurchasingLocksAreBoundToTheirEvents(): void {
		(new PurchasingControlsRegistration())->register($this->recordingContext());

		self::assertSame(
			[
				[ObjectCreatingEvent::class, PurchaseOrderDecisionLockListener::class],
				[ObjectUpdatingEvent::class, PurchaseOrderDecisionLockListener::class],
				[ObjectUpdatingEvent::class, SupplierInvoiceApBindingLockListener::class],
			],
			$this->listeners
		);

	}//end testPurchasingLocksAreBoundToTheirEvents()

	/**
	 * The report schedule check runs on create and update.
	 *
	 * @return void
	 */
	public function testReportScheduleWritesAreCheckedOnBothPreSaveEvents(): void {
		(new ReportScheduleControlsRegistration())->register($this->recordingContext());

		self::assertSame(
			[
				[ObjectCreatingEvent::class, ReportScheduleWriteListener::class],
				[ObjectUpdatingEvent::class, ReportScheduleWriteListener::class],
			],
			$this->listeners
		);

	}//end testReportScheduleWritesAreCheckedOnBothPreSaveEvents()

	/**
	 * The hours budget follows creates, updates and deletes.
	 *
	 * @return void
	 */
	public function testAssignmentHoursFollowEveryPostSaveEvent(): void {
		(new ProjectHoursRegistration())->register($this->recordingContext());

		self::assertSame(
			[
				[ObjectCreatedEvent::class, AssignmentHoursListener::class],
				[ObjectUpdatedEvent::class, AssignmentHoursListener::class],
				[ObjectDeletedEvent::class, AssignmentHoursListener::class],
			],
			$this->listeners
		);

	}//end testAssignmentHoursFollowEveryPostSaveEvent()

	/**
	 * Reporting stamps GL lines, registers its notifier and widgets, and
	 * composes the hours and settlement registrations.
	 *
	 * @return void
	 */
	public function testReportingRegistersStampNotifierWidgetsAndComposedAreas(): void {
		(new ReportingRegistration())->register($this->recordingContext());

		self::assertSame([ObjectTransitionedEvent::class, GLLineResultStampListener::class], $this->listeners[0]);
		self::assertSame([ObjectCreatedEvent::class, GLLineResultStampListener::class], $this->listeners[1]);
		self::assertSame([ObjectCreatedEvent::class, AssignmentHoursListener::class], $this->listeners[2]);
		self::assertSame([ObjectDeletedEvent::class, AssignmentHoursListener::class], $this->listeners[4]);
		self::assertGreaterThan(5, count($this->listeners), 'The settlement registration adds its listeners after the hours ones.');
		self::assertSame([ScheduledReportNotifier::class], $this->notifiers);
		self::assertSame(
			[
				RevenueThisMonthWidget::class,
				OpenReceivablesWidget::class,
				CashPositionWidget::class,
				ResultByMonthWidget::class,
				TopCustomersWidget::class,
			],
			$this->widgets
		);

	}//end testReportingRegistersStampNotifierWidgetsAndComposedAreas()
}//end class
