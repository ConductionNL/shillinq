<?php

/**
 * CommitmentTransitionListener never lets an emission failure escape.
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
use OCA\Shillinq\Listener\CommitmentTransitionListener;
use OCA\Shillinq\Service\BudgetImpactEmitter;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An emitter or schema resolver that throws is logged as a warning and
 * swallowed; a non-TenderNed commitment never reaches the emitter.
 *
 * @covers \OCA\Shillinq\Listener\CommitmentTransitionListener
 */
final class CommitmentTransitionListenerFailSoftTest extends TestCase {
	/**
	 * The budget emitter.
	 *
	 * @var BudgetImpactEmitter&MockObject
	 */
	private BudgetImpactEmitter&MockObject $emitter;

	/**
	 * The schema resolver.
	 *
	 * @var ListenerSchemaResolver&MockObject
	 */
	private ListenerSchemaResolver&MockObject $resolver;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The listener under test.
	 *
	 * @var CommitmentTransitionListener
	 */
	private CommitmentTransitionListener $listener;

	/**
	 * Build the listener.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->emitter = $this->createMock(originalClassName: BudgetImpactEmitter::class);
		$this->resolver = $this->createMock(originalClassName: ListenerSchemaResolver::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->listener = new CommitmentTransitionListener(
			emitter: $this->emitter,
			schemaResolver: $this->resolver,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A created, active commitment event from the given source.
	 *
	 * @param string $source The commitment source.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private function created(string $source): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setSchema('1089');
		$entity->setObject(['source' => $source, 'status' => 'active', 'amount' => 100.0]);
		return new ObjectCreatedEvent($entity);
	}//end created()

	/**
	 * A throwing emitter is logged as a warning and swallowed.
	 *
	 * @return void
	 */
	public function testAThrowingEmitterIsLoggedAndSwallowed(): void {
		$this->resolver->method('schemaSlug')->willReturn('Commitment');
		$this->emitter->expects($this->once())->method('emitActivated')
			->willThrowException(new \RuntimeException('bus down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'CommitmentTransitionListener: emission failed — fail-soft',
			['exception' => 'bus down']
		);

		$this->listener->handle(event: $this->created(source: 'tenderned'));
	}//end testAThrowingEmitterIsLoggedAndSwallowed()

	/**
	 * A throwing schema resolver is logged as a warning and nothing is emitted.
	 *
	 * @return void
	 */
	public function testAThrowingResolverIsLoggedAndNothingEmitted(): void {
		$this->resolver->method('schemaSlug')->willThrowException(new \RuntimeException('mapper down'));
		$this->emitter->expects($this->never())->method('emitActivated');
		$this->logger->expects($this->once())->method('warning')->with(
			'CommitmentTransitionListener: emission failed — fail-soft',
			['exception' => 'mapper down']
		);

		$this->listener->handle(event: $this->created(source: 'tenderned'));
	}//end testAThrowingResolverIsLoggedAndNothingEmitted()

	/**
	 * A commitment from another source never reaches the emitter.
	 *
	 * @return void
	 */
	public function testANonTenderNedCommitmentIsNotEmitted(): void {
		$this->resolver->method('schemaSlug')->willReturn('Commitment');
		$this->emitter->expects($this->never())->method('emitActivated');
		$this->logger->expects($this->never())->method('warning');

		$this->listener->handle(event: $this->created(source: 'manual'));
	}//end testANonTenderNedCommitmentIsNotEmitted()
}//end class
