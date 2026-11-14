<?php

/**
 * Unit tests for DualGaapPostedTransactionListener.
 *
 * REQ-DGAAP-011: a GLTransaction moving to posted is derived under the
 * other framework; any other transition, schema or event is ignored, and a
 * derivation failure never breaks the posting.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-ifrs-rj-dual-gaap/spec.md
 */


declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Listener\DualGaapPostedTransactionListener;
use OCA\Shillinq\Service\DualGaapDerivationService;
use OCP\EventDispatcher\GenericEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The post transition derives the transaction.
 */
class DualGaapPostedTransactionListenerTest extends TestCase {

	/**
	 * A transition event on a transaction.
	 *
	 * @param string $to The state moved to.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(string $to = 'posted'): ObjectTransitionedEvent {
		$entity = (new ObjectEntity())->setSchema('12')->setUuid('tx-1')->setObject(['sourceReference' => 'PROV-DEB-2026', 'state' => 'draft']);

		return new ObjectTransitionedEvent(
			object: $entity,
			move: new TransitionMove(action: 'post', from: 'draft', to: $to),
			scope: new RegisterSchemaRef(register: 'shillinq', schema: 'GLTransaction'),
			actor: new TransitionActor(userId: 'controller'),
		);
	}//end event()

	/**
	 * The listener over a derivation double and a resolver answering $isTransaction.
	 *
	 * @param DualGaapDerivationService $derivation    The derivation double.
	 * @param bool                      $isTransaction What the resolver answers.
	 * @param LoggerInterface|null      $logger        A logger double.
	 *
	 * @return DualGaapPostedTransactionListener
	 */
	private function listener(DualGaapDerivationService $derivation, bool $isTransaction = true, ?LoggerInterface $logger = null): DualGaapPostedTransactionListener {
		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesSchema')->willReturnCallback(static fn (?object $entity, string $expectedSlug): bool => $isTransaction && $expectedSlug === 'GLTransaction');

		return new DualGaapPostedTransactionListener($derivation, $resolver, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end listener()

	/**
	 * Posting derives the transaction with its id and the posted state.
	 *
	 * @return void
	 */
	public function testThePostTransitionDerivesTheTransaction(): void {
		$derivation = $this->createMock(DualGaapDerivationService::class);
		$derivation->expects($this->once())->method('deriveForTransaction')
			->with(['sourceReference' => 'PROV-DEB-2026', 'state' => 'posted', 'id' => 'tx-1'])
			->willReturn(['derived' => true, 'message' => 'Derived entry and bridge line written.']);

		$this->listener($derivation)->handle($this->event());
	}//end testThePostTransitionDerivesTheTransaction()

	/**
	 * Other transitions, other schemas and other events derive nothing.
	 *
	 * @return void
	 */
	public function testAnythingElseDerivesNothing(): void {
		$derivation = $this->createMock(DualGaapDerivationService::class);
		$derivation->expects($this->never())->method('deriveForTransaction');

		$this->listener($derivation)->handle($this->event(to: 'reversed'));
		$this->listener($derivation, isTransaction: false)->handle($this->event());
		$this->listener($derivation)->handle(new GenericEvent());
	}//end testAnythingElseDerivesNothing()

	/**
	 * A derivation that throws is logged and swallowed; one that writes nothing is logged.
	 *
	 * @return void
	 */
	public function testAFailedOrEmptyDerivationIsLoggedNotThrown(): void {
		$derivation = $this->createMock(DualGaapDerivationService::class);
		$derivation->method('deriveForTransaction')->willReturnOnConsecutiveCalls(
			$this->throwException(new \RuntimeException('down')),
			['derived' => false, 'message' => 'No adjustment rule for this transaction: it is not divergent.']
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$logger->expects($this->once())->method('debug')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['reason'] === 'No adjustment rule for this transaction: it is not divergent.'));

		$listener = $this->listener($derivation, logger: $logger);
		$listener->handle($this->event());
		$listener->handle($this->event());
	}//end testAFailedOrEmptyDerivationIsLoggedNotThrown()
}//end class
