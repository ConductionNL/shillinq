<?php

/**
 * Unit tests for PaymentPlanService when a collaborator fails: a refused
 * transition, a pause that cannot be resumed, a plan the monitor cannot
 * process, and an instalment already marked missed.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\PaymentPlan
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PaymentPlan;

use DateTime;
use OCA\Shillinq\PaymentPlan\PaymentPlanAllocator;
use OCA\Shillinq\PaymentPlan\PaymentPlanMailer;
use OCA\Shillinq\PaymentPlan\PaymentPlanRefusedException;
use OCA\Shillinq\PaymentPlan\PaymentPlanSchedule;
use OCA\Shillinq\PaymentPlan\PaymentPlanService;
use OCA\Shillinq\Service\DunningRunService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The service over an in-memory store with every collaborator a double.
 */
#[CoversClass(PaymentPlanService::class)]
#[UsesClass(PaymentPlanRefusedException::class)]
#[UsesClass(ObjectIdentifier::class)]
final class PaymentPlanServiceFailuresTest extends TestCase {
	/**
	 * The store.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The allocator double.
	 *
	 * @var PaymentPlanAllocator&MockObject
	 */
	private PaymentPlanAllocator&MockObject $allocator;

	/**
	 * The dunning double.
	 *
	 * @var DunningRunService&MockObject
	 */
	private DunningRunService&MockObject $dunning;

	/**
	 * The transition runner double.
	 *
	 * @var ObjectTransitionRunner&MockObject
	 */
	private ObjectTransitionRunner&MockObject $transitions;

	/**
	 * The mailer double.
	 *
	 * @var PaymentPlanMailer&MockObject
	 */
	private PaymentPlanMailer&MockObject $mailer;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the service over the given plans.
	 *
	 * @param array<int,array<string,mixed>> $plans The stored plans.
	 *
	 * @return PaymentPlanService
	 */
	private function service(array $plans): PaymentPlanService {
		$this->store = new InMemoryObjectServiceStub(['PaymentPlan' => $plans]);
		$this->allocator = $this->createMock(PaymentPlanAllocator::class);
		$this->dunning = $this->createMock(DunningRunService::class);
		$this->transitions = $this->createMock(ObjectTransitionRunner::class);
		$this->mailer = $this->createMock(PaymentPlanMailer::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-10-15T09:00:00Z'));

		return new PaymentPlanService(
			$this->store,
			$this->createConfiguredMock(RegisterLocation::class, ['getRegisterSlug' => 'shillinq']),
			new PaymentPlanSchedule(),
			$this->allocator,
			$this->dunning,
			$this->transitions,
			$this->mailer,
			$time,
			$this->logger
		);

	}//end service()

	/**
	 * A stored plan's data.
	 *
	 * @param string $id The plan id.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(string $id): array {
		$entity = $this->store->find(id: $id, schema: 'PaymentPlan');
		$this->assertNotNull($entity);
		return $entity->getObject();

	}//end stored()

	/**
	 * A refused activate transition becomes a refusal naming the cause.
	 *
	 * @return void
	 */
	public function testARefusedActivateTransitionIsRefusedWithItsCause(): void {
		$service = $this->service([['id' => 'plan-1', 'lifecycleState' => 'draft', 'lastDueDate' => '2027-01-01', 'graceDays' => 14]]);
		$this->transitions->expects($this->once())->method('run')
			->with(objectId: 'plan-1', action: 'activate')
			->willThrowException(new RuntimeException('guard says no'));
		$this->dunning->expects($this->never())->method($this->anything());

		try {
			$service->activate('plan-1', 'admin');
			$this->fail('activate did not refuse');
		} catch (PaymentPlanRefusedException $e) {
			$this->assertSame('The plan was not activated: guard says no', $e->getMessage());
			$this->assertSame('The plan was not activated: %1$s', $e->getTemplate());
			$this->assertSame(['guard says no'], $e->getParameters());
		}

	}//end testARefusedActivateTransitionIsRefusedWithItsCause()

	/**
	 * A plan that is not a draft is refused before any transition runs.
	 *
	 * @return void
	 */
	public function testActivatingANonDraftPlanIsRefusedBeforeAnyTransition(): void {
		$service = $this->service([['id' => 'plan-1', 'lifecycleState' => 'active']]);
		$this->transitions->expects($this->never())->method('run');

		$this->expectException(PaymentPlanRefusedException::class);
		$this->expectExceptionMessage('Only a draft plan can be activated.');
		$service->activate('plan-1', 'admin');

	}//end testActivatingANonDraftPlanIsRefusedBeforeAnyTransition()

	/**
	 * A refused cancel transition is refused and the plan is not marked ended.
	 *
	 * @return void
	 */
	public function testARefusedCancelTransitionLeavesThePlanUnended(): void {
		$service = $this->service([['id' => 'plan-1', 'lifecycleState' => 'active', 'pauseIds' => ['pause-1']]]);
		$this->transitions->method('run')->willThrowException(new RuntimeException('locked'));
		$this->dunning->expects($this->never())->method('resumePause');

		try {
			$service->cancel('plan-1', 'Customer asked.');
			$this->fail('cancel did not refuse');
		} catch (PaymentPlanRefusedException $e) {
			$this->assertSame('The plan could not be ended: locked', $e->getMessage());
		}

		$this->assertArrayNotHasKey('endReason', $this->stored('plan-1'));

	}//end testARefusedCancelTransitionLeavesThePlanUnended()

	/**
	 * A pause that cannot be resumed is logged; the other pauses resume and the plan ends.
	 *
	 * @return void
	 */
	public function testAPauseThatCannotBeResumedIsLoggedAndThePlanStillEnds(): void {
		$service = $this->service([['id' => 'plan-1', 'lifecycleState' => 'active', 'administrationId' => 'adm-1', 'pauseIds' => ['pause-1', 'pause-2']]]);
		$this->transitions->expects($this->once())->method('run')->with(objectId: 'plan-1', action: 'cancel');
		$resumed = [];
		$this->dunning->expects($this->exactly(2))->method('resumePause')->willReturnCallback(
			static function (string $administrationId, string $pauseId) use (&$resumed): array {
				$resumed[] = $administrationId . '/' . $pauseId;
				if ($pauseId === 'pause-1') {
					throw new RuntimeException('pause gone');
				}

				return [];
			}
		);
		$this->logger->expects($this->once())->method('error')->with(
			'PaymentPlanService: a dunning pause could not be resumed',
			['pauseId' => 'pause-1', 'exception' => 'pause gone']
		);
		$this->mailer->expects($this->never())->method('send');

		$plan = $service->cancel('plan-1', 'Customer asked.');

		$this->assertSame(['adm-1/pause-1', 'adm-1/pause-2'], $resumed);
		$this->assertSame('Customer asked.', $plan['endReason']);
		$this->assertSame('2026-10-15', $plan['endedOn']);

	}//end testAPauseThatCannotBeResumedIsLoggedAndThePlanStillEnds()

	/**
	 * A plan the monitor cannot process is logged and the others are still processed.
	 *
	 * @return void
	 */
	public function testTheMonitorLogsAFailingPlanAndCarriesOn(): void {
		$service = $this->service([['id' => 'plan-bad', 'lifecycleState' => 'active'], ['id' => 'plan-ok', 'lifecycleState' => 'active']]);
		$this->allocator->method('instalments')->willReturnCallback(
			static function (string $planId): array {
				if ($planId === 'plan-bad') {
					throw new RuntimeException('store down');
				}

				return [['id' => 'i-1', 'instalmentNumber' => 1, 'amount' => 10.0, 'paidAmount' => 0.0, 'state' => 'pending', 'dueDate' => '2026-10-10']];
			}
		);
		$this->transitions->expects($this->once())->method('run')->with(objectId: 'i-1', action: 'fall-due');
		$this->logger->expects($this->once())->method('error')->with(
			'PaymentPlanService: monitor failed for a plan',
			['planId' => 'plan-bad', 'exception' => 'store down']
		);

		$counts = $service->monitor();

		$this->assertSame(['due' => 1, 'missed' => 0, 'broken' => 0, 'completed' => 0], $counts);
		$this->assertSame(10.0, $this->stored('plan-ok')['arrears']);

	}//end testTheMonitorLogsAFailingPlanAndCarriesOn()

	/**
	 * An instalment already missed breaks the plan, naming the first missed one, and mails the customer.
	 *
	 * @return void
	 */
	public function testAnAlreadyMissedInstalmentBreaksThePlan(): void {
		$service = $this->service([['id' => 'plan-1', 'lifecycleState' => 'active', 'customerEmail' => 'klant@example.test']]);
		$this->allocator->method('instalments')->willReturn(
			[
				['id' => 'i-1', 'instalmentNumber' => 1, 'amount' => 10.0, 'paidAmount' => 0.0, 'state' => 'missed', 'dueDate' => '2026-08-01'],
				['id' => 'i-2', 'instalmentNumber' => 2, 'amount' => 10.0, 'paidAmount' => 0.0, 'state' => 'missed', 'dueDate' => '2026-09-01'],
			]
		);
		$this->transitions->expects($this->once())->method('run')->with(objectId: 'plan-1', action: 'break');
		$this->mailer->expects($this->once())->method('ended')
			->with($this->callback(static fn (array $plan): bool => $plan['id'] === 'plan-1'), 'Instalment 1, due on 2026-08-01, was not paid.')
			->willReturn(['subject' => 's', 'body' => 'b']);
		$this->mailer->expects($this->once())->method('send')->with(recipient: 'klant@example.test', mail: ['subject' => 's', 'body' => 'b']);

		$counts = $service->monitor();

		$this->assertSame(['due' => 0, 'missed' => 0, 'broken' => 1, 'completed' => 0], $counts);
		$this->assertSame('Instalment 1, due on 2026-08-01, was not paid.', $this->stored('plan-1')['endReason']);
		$this->assertSame(20.0, $this->stored('plan-1')['arrears']);

	}//end testAnAlreadyMissedInstalmentBreaksThePlan()
}//end class
