<?php

/**
 * BookingLifecycleTransitionListener never lets a publish failure escape.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\Shillinq\AppInfo\AppIdentity;
use OCA\Shillinq\Listener\BookingLifecycleTransitionListener;
use OCA\Shillinq\Service\Pipelinq\PipelinqAdminNotifier;
use OCA\Shillinq\Service\Pipelinq\PipelinqContactAdapter;
use OCA\Shillinq\Service\Pipelinq\TimelineRetryQueue;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only a transition event is handled, and an adapter that throws while
 * publishing is logged as an error and swallowed so the booking commits.
 *
 * @covers \OCA\Shillinq\Listener\BookingLifecycleTransitionListener
 * @uses   \OCA\Shillinq\Listener\BookingTimelineFields
 * @uses   \OCA\Shillinq\Service\Pipelinq\TimelineEventDto
 */
final class BookingLifecycleTransitionListenerFailSoftTest extends TestCase {
	/**
	 * The pipelinq adapter.
	 *
	 * @var PipelinqContactAdapter&MockObject
	 */
	private PipelinqContactAdapter&MockObject $adapter;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var BookingLifecycleTransitionListener
	 */
	private BookingLifecycleTransitionListener $listener;

	/**
	 * Build the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->adapter = $this->createMock(originalClassName: PipelinqContactAdapter::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new BookingLifecycleTransitionListener(
			pipelinq: $this->adapter,
			retryQueue: $this->createMock(originalClassName: TimelineRetryQueue::class),
			adminAlerts: $this->createMock(originalClassName: PipelinqAdminNotifier::class),
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A linked appointment entity.
	 *
	 * @return ObjectEntity The appointment.
	 */
	private function appointment(): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('appointment');
		$entity->setObject(['appointmentId' => 'apt-1', 'pipelinqContactId' => 'c-1']);
		return $entity;
	}//end appointment()

	/**
	 * A created appointment is not a transition and publishes nothing.
	 *
	 * @return void
	 */
	public function testANonTransitionEventPublishesNothing(): void {
		$this->adapter->expects($this->never())->method('publishWithOutcome');
		$this->logger->expects($this->never())->method('error');

		$this->listener->handle(event: new ObjectCreatedEvent($this->appointment()));
	}//end testANonTransitionEventPublishesNothing()

	/**
	 * A throwing publish is logged as an error with the app id and swallowed.
	 *
	 * @return void
	 */
	public function testAThrowingPublishIsLoggedAndSwallowed(): void {
		$this->adapter->expects($this->once())->method('publishWithOutcome')
			->willThrowException(new \RuntimeException('pipelinq down'));
		$this->logger->expects($this->once())->method('error')->with(
			'BookingLifecycleTransitionListener: publish errored',
			['app' => AppIdentity::APP_ID, 'exception' => 'pipelinq down']
		);

		$this->listener->handle(
			event: new ObjectTransitionedEvent(
				object: $this->appointment(),
				move: new TransitionMove(action: 'transition', from: 'pending_confirmation', to: 'confirmed'),
				scope: new RegisterSchemaRef(register: 'shillinq', schema: 'appointment'),
			)
		);
	}//end testAThrowingPublishIsLoggedAndSwallowed()
}//end class
