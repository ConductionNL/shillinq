<?php

/**
 * Unit tests for DualGaapPostedListener.
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
 * @spec openspec/changes/reporting-ifrs-calculations/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\Shillinq\Kernel\Contracts\DualGaapDerivationServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Listener\DualGaapPostedListener;
use OCP\EventDispatcher\GenericEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Posting a divergent transaction derives the other framework's entry.
 */
class DualGaapPostedListenerTest extends TestCase {

	/**
	 * A transition event on a transaction.
	 *
	 * @param string $to     The state moved to.
	 * @param string $reason The divergence reason on the transaction.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(string $to = 'posted', string $reason = 'ECL_IFRS9'): ObjectTransitionedEvent {
		$entity = (new ObjectEntity())->setSchema('12')->setUuid('tx-1')->setObject(['periodId' => '2026', 'divergenceReasonCode' => $reason, 'adjustmentCalculationId' => 'calc-ecl']);

		return new ObjectTransitionedEvent(
			object: $entity,
			move: new TransitionMove(action: 'post', from: 'draft', to: $to),
			scope: new RegisterSchemaRef(register: 'shillinq', schema: 'GLTransaction'),
			actor: new TransitionActor(userId: 'bookkeeper'),
		);
	}//end event()

	/**
	 * The listener over a derivation double and a resolver answering $isTransaction.
	 *
	 * @param DualGaapDerivationServiceInterface $derivation    The derivation double.
	 * @param bool                               $isTransaction What the resolver answers.
	 * @param LoggerInterface|null               $logger        A logger double.
	 *
	 * @return DualGaapPostedListener
	 */
	private function listener(DualGaapDerivationServiceInterface $derivation, bool $isTransaction = true, ?LoggerInterface $logger = null): DualGaapPostedListener {
		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesSchema')->willReturnCallback(static fn (?object $entity, string $expectedSlug): bool => $isTransaction && $expectedSlug === 'GLTransaction');

		return new DualGaapPostedListener($derivation, $resolver, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end listener()

	/**
	 * Posting derives with the transaction and its id.
	 *
	 * @return void
	 */
	public function testPostingADivergentTransactionDerivesIt(): void {
		$derivation = $this->createMock(DualGaapDerivationServiceInterface::class);
		$derivation->expects($this->once())->method('deriveOnPost')
			->with(['periodId' => '2026', 'divergenceReasonCode' => 'ECL_IFRS9', 'adjustmentCalculationId' => 'calc-ecl', 'id' => 'tx-1'])
			->willReturn(['derived' => true, 'message' => 'ok']);

		$this->listener($derivation)->handle($this->event());
	}//end testPostingADivergentTransactionDerivesIt()

	/**
	 * Other transitions, schemas, events and unmarked transactions derive nothing.
	 *
	 * @return void
	 */
	public function testAnythingElseDerivesNothing(): void {
		$derivation = $this->createMock(DualGaapDerivationServiceInterface::class);
		$derivation->expects($this->never())->method('deriveOnPost');

		$this->listener($derivation)->handle($this->event(to: 'reversed'));
		$this->listener($derivation)->handle($this->event(reason: 'NONE'));
		$this->listener($derivation)->handle($this->event(reason: ''));
		$this->listener($derivation, isTransaction: false)->handle($this->event());
		$this->listener($derivation)->handle(new GenericEvent());
	}//end testAnythingElseDerivesNothing()

	/**
	 * A derivation that throws is logged and swallowed; one that derives nothing is logged.
	 *
	 * @return void
	 */
	public function testAFailedOrEmptyDerivationIsLoggedNotThrown(): void {
		$derivation = $this->createMock(DualGaapDerivationServiceInterface::class);
		$derivation->method('deriveOnPost')->willReturnOnConsecutiveCalls(
			$this->throwException(new \RuntimeException('down')),
			['derived' => false, 'message' => 'This transaction is already derived.']
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['reason'] === 'This transaction is already derived.'));

		$listener = $this->listener($derivation, logger: $logger);
		$listener->handle($this->event());
		$listener->handle($this->event());
	}//end testAFailedOrEmptyDerivationIsLoggedNotThrown()
}//end class
