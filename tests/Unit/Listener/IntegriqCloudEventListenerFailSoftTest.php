<?php

/**
 * IntegriqCloudEventListener skips what it cannot apply instead of failing the write.
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
 *
 * @spec openspec/changes/receivables-payment-links/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Listener\IntegriqCloudEventListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\PaymentReconciliationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A payment-status CloudEvent without data is skipped with an info log, a
 * failing schema check or reconciliation is logged as a warning and
 * swallowed, and an event in another register and schema is not reconciled.
 *
 * @covers \OCA\Shillinq\Listener\IntegriqCloudEventListener
 */
final class IntegriqCloudEventListenerFailSoftTest extends TestCase {
	/**
	 * The schema resolver.
	 *
	 * @var ListenerSchemaResolver&MockObject
	 */
	private ListenerSchemaResolver&MockObject $resolver;

	/**
	 * The reconciliation service.
	 *
	 * @var PaymentReconciliationService&MockObject
	 */
	private PaymentReconciliationService&MockObject $reconciliation;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var IntegriqCloudEventListener
	 */
	private IntegriqCloudEventListener $listener;

	/**
	 * Build the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->resolver = $this->createMock(originalClassName: ListenerSchemaResolver::class);
		$this->reconciliation = $this->createMock(originalClassName: PaymentReconciliationService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new IntegriqCloudEventListener(
			schemaResolver: $this->resolver,
			reconciliation: $this->reconciliation,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A created payment-status CloudEvent.
	 *
	 * @param mixed $data The CloudEvent data.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private function paymentStatus(mixed $data): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setRegister('7');
		$entity->setSchema('4101');
		$entity->setObject(['type' => IntegriqCloudEventListener::TYPE_PAYMENT_STATUS, 'data' => $data]);
		return new ObjectCreatedEvent($entity);
	}//end paymentStatus()

	/**
	 * A payment-status event without array data is skipped with an info log.
	 *
	 * @return void
	 */
	public function testAnEventWithoutDataIsSkipped(): void {
		$this->resolver->method('matchesRegisterAndSchema')->willReturn(true);
		$this->reconciliation->expects($this->never())->method('reconcile');
		$this->logger->expects($this->once())->method('info')
			->with('IntegriqCloudEventListener: payment status CloudEvent without data; skipped');

		$this->listener->handle(event: $this->paymentStatus(data: 'tr_1'));
	}//end testAnEventWithoutDataIsSkipped()

	/**
	 * An event outside the integriq event schema is not reconciled.
	 *
	 * @return void
	 */
	public function testAnEventInAnotherSchemaIsNotReconciled(): void {
		$this->resolver->expects($this->once())->method('matchesRegisterAndSchema')
			->with($this->isInstanceOf(ObjectEntity::class), 'integriq', 'event')
			->willReturn(false);
		$this->reconciliation->expects($this->never())->method('reconcile');

		$this->listener->handle(event: $this->paymentStatus(data: ['paymentIntentId' => 'tr_1']));
	}//end testAnEventInAnotherSchemaIsNotReconciled()

	/**
	 * A failing reconciliation is logged as a warning and swallowed.
	 *
	 * @return void
	 */
	public function testAFailingReconciliationIsLoggedAndSwallowed(): void {
		$this->resolver->method('matchesRegisterAndSchema')->willReturn(true);
		$this->reconciliation->expects($this->once())->method('reconcile')
			->with('mollie', ['paymentIntentId' => 'tr_1', 'outcome' => 'paid'])
			->willThrowException(new \RuntimeException('ledger locked'));
		$this->logger->expects($this->once())->method('warning')->with(
			'IntegriqCloudEventListener: failed to apply an integriq CloudEvent; skipped',
			['exception' => 'ledger locked']
		);

		$this->listener->handle(event: $this->paymentStatus(data: ['paymentIntentId' => 'tr_1', 'outcome' => 'paid']));
	}//end testAFailingReconciliationIsLoggedAndSwallowed()

	/**
	 * A failing schema check is logged as a warning and nothing is reconciled.
	 *
	 * @return void
	 */
	public function testAFailingSchemaCheckIsLoggedAndSwallowed(): void {
		$this->resolver->method('matchesRegisterAndSchema')->willThrowException(new \RuntimeException('mapper down'));
		$this->reconciliation->expects($this->never())->method('reconcile');
		$this->logger->expects($this->once())->method('warning')->with(
			'IntegriqCloudEventListener: failed to apply an integriq CloudEvent; skipped',
			['exception' => 'mapper down']
		);

		$this->listener->handle(event: $this->paymentStatus(data: ['paymentIntentId' => 'tr_1']));
	}//end testAFailingSchemaCheckIsLoggedAndSwallowed()
}//end class
