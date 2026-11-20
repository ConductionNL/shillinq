<?php

/**
 * Unit tests for ReconciliationMatchSettlementListener: only a confirmed
 * ReconciliationMatch transition settles, the outcome is logged, and a
 * failing settlement is logged rather than thrown.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Listener
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

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Listener\ReconciliationMatchSettlementListener;
use OCA\Shillinq\Service\Bank\InvoiceSettlementService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ReconciliationMatchSettlementListener.
 */
#[CoversClass(ReconciliationMatchSettlementListener::class)]
class ReconciliationMatchSettlementListenerTest extends TestCase {

	/**
	 * A transition event double for a match payload.
	 *
	 * @param string               $action The transition action.
	 * @param array<string, mixed> $match  The match payload.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private function transition(string $action, array $match): ObjectTransitionedEvent {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('getObject')->willReturn($match);

		return $this->createConfiguredMock(ObjectTransitionedEvent::class, ['getObject' => $entity, 'getAction' => $action]);
	}//end transition()

	/**
	 * A schema resolver that says whether the entity is a ReconciliationMatch.
	 *
	 * @param bool $isMatch The answer.
	 *
	 * @return ListenerSchemaResolver
	 */
	private function schemas(bool $isMatch): ListenerSchemaResolver {
		$schemas = $this->createMock(ListenerSchemaResolver::class);
		$schemas->method('matchesSchema')->willReturnCallback(
			static fn (?object $entity, string $expectedSlug): bool => $isMatch && $expectedSlug === 'ReconciliationMatch'
		);
		return $schemas;
	}//end schemas()

	/**
	 * A confirmed match is settled and its outcomes are logged.
	 *
	 * @return void
	 */
	public function testAConfirmedMatchIsSettled(): void {
		$match = ['id' => 'm-1', 'bankLineId' => 'bl-1', 'invoiceIds' => ['i-1']];
		$settlement = $this->createMock(InvoiceSettlementService::class);
		$settlement->expects($this->once())->method('settle')->with($match)->willReturn(['i-1' => 'paid']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with(
			'ReconciliationMatchSettlementListener: match settled',
			['outcomes' => ['i-1' => 'paid']]
		);
		$logger->expects($this->never())->method('error');

		(new ReconciliationMatchSettlementListener($settlement, $this->schemas(true), $logger))
			->handle($this->transition('confirm', $match));
	}//end testAConfirmedMatchIsSettled()

	/**
	 * Other events, other actions and other schemas do not settle.
	 *
	 * @return void
	 */
	public function testOnlyAConfirmedMatchSettles(): void {
		$settlement = $this->createMock(InvoiceSettlementService::class);
		$settlement->expects($this->never())->method('settle');

		(new ReconciliationMatchSettlementListener($settlement, $this->schemas(true), $this->createMock(LoggerInterface::class)))
			->handle(new Event());
		(new ReconciliationMatchSettlementListener($settlement, $this->schemas(true), $this->createMock(LoggerInterface::class)))
			->handle($this->transition('reject', ['id' => 'm-1']));
		(new ReconciliationMatchSettlementListener($settlement, $this->schemas(false), $this->createMock(LoggerInterface::class)))
			->handle($this->transition('confirm', ['id' => 'm-1']));
	}//end testOnlyAConfirmedMatchSettles()

	/**
	 * A failing settlement is logged as an error and not rethrown.
	 *
	 * @return void
	 */
	public function testAFailingSettlementIsLogged(): void {
		$settlement = $this->createMock(InvoiceSettlementService::class);
		$settlement->method('settle')->willThrowException(new RuntimeException('invoice locked'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$logger->expects($this->once())->method('error')->with(
			'ReconciliationMatchSettlementListener: settlement failed (fail-soft)',
			['exception' => 'invoice locked']
		);

		(new ReconciliationMatchSettlementListener($settlement, $this->schemas(true), $logger))
			->handle($this->transition('confirm', ['id' => 'm-1']));
	}//end testAFailingSettlementIsLogged()
}//end class
