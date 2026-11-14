<?php

/**
 * Unit tests for ActuarialValuationApprovedListener.
 *
 * REQ-PEN-011: the approve transition of an ActuarialValuation books it;
 * any other transition, schema or event is ignored, and a posting failure
 * never breaks the approval.
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
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Listener\ActuarialValuationApprovedListener;
use OCA\Shillinq\Service\PensionValuationPostingService;
use OCP\EventDispatcher\GenericEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The approve transition books the valuation.
 */
class ActuarialValuationApprovedListenerTest extends TestCase {

	/**
	 * A transition event on an object.
	 *
	 * @param string $to The state moved to.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function event(string $to = 'approved'): ObjectTransitionedEvent {
		$entity = (new ObjectEntity())->setSchema('77')->setUuid('val-1')->setObject(['plan' => 'plan-db', 'valuationDate' => '2026-12-31', 'approvalStatus' => 'draft']);

		return new ObjectTransitionedEvent(
			object: $entity,
			move: new TransitionMove(action: 'approve', from: 'draft', to: $to),
			scope: new RegisterSchemaRef(register: 'shillinq', schema: 'ActuarialValuation'),
			actor: new TransitionActor(userId: 'controller'),
		);
	}//end event()

	/**
	 * The listener over a posting double and a resolver answering $isValuation.
	 *
	 * @param PensionValuationPostingService $posting     The posting double.
	 * @param bool                           $isValuation What the resolver answers.
	 * @param LoggerInterface|null           $logger      A logger double.
	 *
	 * @return ActuarialValuationApprovedListener
	 */
	private function listener(PensionValuationPostingService $posting, bool $isValuation = true, ?LoggerInterface $logger = null): ActuarialValuationApprovedListener {
		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->method('matchesSchema')->willReturnCallback(static fn (?object $entity, string $expectedSlug): bool => $isValuation && $expectedSlug === 'ActuarialValuation');

		return new ActuarialValuationApprovedListener($posting, $resolver, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end listener()

	/**
	 * Approving posts the valuation with its id and the approved status.
	 *
	 * @return void
	 */
	public function testTheApproveTransitionPostsTheValuation(): void {
		$posting = $this->createMock(PensionValuationPostingService::class);
		$posting->expects($this->once())->method('postForValuation')
			->with(['plan' => 'plan-db', 'valuationDate' => '2026-12-31', 'approvalStatus' => 'approved', 'id' => 'val-1'])
			->willReturn(['posted' => true, 'message' => 'Pension entry posted.']);

		$this->listener($posting)->handle($this->event());
	}//end testTheApproveTransitionPostsTheValuation()

	/**
	 * Other transitions, other schemas and other events post nothing.
	 *
	 * @return void
	 */
	public function testAnythingElsePostsNothing(): void {
		$posting = $this->createMock(PensionValuationPostingService::class);
		$posting->expects($this->never())->method('postForValuation');

		$this->listener($posting)->handle($this->event(to: 'locked'));
		$this->listener($posting, isValuation: false)->handle($this->event());
		$this->listener($posting)->handle(new GenericEvent());
	}//end testAnythingElsePostsNothing()

	/**
	 * A posting that throws is logged and swallowed; one that books nothing is logged.
	 *
	 * @return void
	 */
	public function testAFailedOrEmptyPostingIsLoggedNotThrown(): void {
		$posting = $this->createMock(PensionValuationPostingService::class);
		$posting->method('postForValuation')->willReturnOnConsecutiveCalls(
			$this->throwException(new \RuntimeException('down')),
			['posted' => false, 'message' => 'No pension movement for this plan in 2026.']
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$logger->expects($this->once())->method('info')->with($this->anything(), $this->callback(static fn (array $context): bool => $context['reason'] === 'No pension movement for this plan in 2026.'));

		$listener = $this->listener($posting, logger: $logger);
		$listener->handle($this->event());
		$listener->handle($this->event());
	}//end testAFailedOrEmptyPostingIsLoggedNotThrown()
}//end class
