<?php

/**
 * Which bank-feed CloudEvents BankfeedSyncedListener takes in.
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
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\Shillinq\Listener\BankfeedSyncedListener;
use OCA\Shillinq\Service\Bank\BankfeedIntakeService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only a created `transactions.synced` CloudEvent in integriq's event schema
 * with array data is ingested, and the outcome is logged; a failing intake
 * is logged as a warning and swallowed.
 *
 * @covers \OCA\Shillinq\Listener\BankfeedSyncedListener
 */
final class BankfeedSyncedListenerTest extends TestCase {
	/**
	 * The schema resolver.
	 *
	 * @var ListenerSchemaResolver&MockObject
	 */
	private ListenerSchemaResolver&MockObject $resolver;

	/**
	 * The intake service.
	 *
	 * @var BankfeedIntakeService&MockObject
	 */
	private BankfeedIntakeService&MockObject $intake;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var BankfeedSyncedListener
	 */
	private BankfeedSyncedListener $listener;

	/**
	 * Build the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->resolver = $this->createMock(originalClassName: ListenerSchemaResolver::class);
		$this->intake = $this->createMock(originalClassName: BankfeedIntakeService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new BankfeedSyncedListener(
			schemaResolver: $this->resolver,
			intake: $this->intake,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A CloudEvent entity.
	 *
	 * @param string $type The CloudEvent type.
	 * @param mixed  $data The CloudEvent data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function cloudEvent(string $type = BankfeedSyncedListener::TYPE_SYNCED, mixed $data = ['accountIban' => 'NL91ABNA0417164300']): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('4101');
		$entity->setObject(['type' => $type, 'data' => $data]);
		return $entity;
	}//end cloudEvent()

	/**
	 * A synced batch in the integriq event schema is ingested and its outcome logged.
	 *
	 * @return void
	 */
	public function testASyncedBatchIsIngestedAndLogged(): void {
		$entity = $this->cloudEvent();
		$this->resolver->expects($this->once())->method('matchesRegisterAndSchema')
			->with($this->identicalTo($entity), 'integriq', 'event')
			->willReturn(true);
		$this->intake->expects($this->once())->method('ingestSynced')
			->with(['accountIban' => 'NL91ABNA0417164300'])
			->willReturn(['created' => 3, 'skipped' => 1]);
		$this->logger->expects($this->once())->method('info')
			->with('BankfeedSyncedListener: bank feed batch taken in', ['created' => 3, 'skipped' => 1]);

		$this->listener->handle(event: new ObjectCreatedEvent($entity));
	}//end testASyncedBatchIsIngestedAndLogged()

	/**
	 * A deleted object is not a new CloudEvent.
	 *
	 * @return void
	 */
	public function testANonCreatedEventIsIgnored(): void {
		$this->resolver->expects($this->never())->method('matchesRegisterAndSchema');
		$this->intake->expects($this->never())->method('ingestSynced');

		$this->listener->handle(event: new ObjectDeletedEvent($this->cloudEvent()));
	}//end testANonCreatedEventIsIgnored()

	/**
	 * Another CloudEvent type is not checked or ingested.
	 *
	 * @return void
	 */
	public function testAnotherTypeIsIgnored(): void {
		$this->resolver->expects($this->never())->method('matchesRegisterAndSchema');
		$this->intake->expects($this->never())->method('ingestSynced');

		$this->listener->handle(event: new ObjectCreatedEvent($this->cloudEvent(type: 'nl.conduction.payment.status')));
	}//end testAnotherTypeIsIgnored()

	/**
	 * A synced event outside integriq's event schema is not ingested.
	 *
	 * @return void
	 */
	public function testAnEventInAnotherSchemaIsNotIngested(): void {
		$this->resolver->method('matchesRegisterAndSchema')->willReturn(false);
		$this->intake->expects($this->never())->method('ingestSynced');

		$this->listener->handle(event: new ObjectCreatedEvent($this->cloudEvent()));
	}//end testAnEventInAnotherSchemaIsNotIngested()

	/**
	 * A synced event without array data is not ingested.
	 *
	 * @return void
	 */
	public function testAnEventWithoutDataIsNotIngested(): void {
		$this->resolver->method('matchesRegisterAndSchema')->willReturn(true);
		$this->intake->expects($this->never())->method('ingestSynced');
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('warning');

		$this->listener->handle(event: new ObjectCreatedEvent($this->cloudEvent(data: 'batch-1')));
	}//end testAnEventWithoutDataIsNotIngested()

	/**
	 * A failing intake is logged as a warning and swallowed.
	 *
	 * @return void
	 */
	public function testAFailingIntakeIsLoggedAndSwallowed(): void {
		$this->resolver->method('matchesRegisterAndSchema')->willReturn(true);
		$this->intake->method('ingestSynced')->willThrowException(new \RuntimeException('unknown account'));
		$this->logger->expects($this->once())->method('warning')
			->with('BankfeedSyncedListener: bank feed batch skipped', ['exception' => 'unknown account']);

		$this->listener->handle(event: new ObjectCreatedEvent($this->cloudEvent()));
	}//end testAFailingIntakeIsLoggedAndSwallowed()
}//end class
