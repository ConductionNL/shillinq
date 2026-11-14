<?php

/**
 * GLTransactionSealListener unit tests (ledger-sealed-entries REQ-LSE-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\Shillinq\Listener\GLTransactionSealListener;
use OCA\Shillinq\Service\Ledger\LedgerSealService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A GLTransaction reaching posted is sealed; nothing else is.
 */
#[CoversClass(GLTransactionSealListener::class)]
final class GLTransactionSealListenerTest extends TestCase {
	/**
	 * A transition event of a GLTransaction.
	 *
	 * @param string $to   The target state.
	 * @param string $uuid The entity uuid.
	 *
	 * @return ObjectTransitionedEvent
	 */
	private static function event(string $to = 'posted', string $uuid = 'tx-uuid'): ObjectTransitionedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema('42');
		$entity->setRegister('7');
		$entity->setObject(['administrationId' => 'adm-voorbeeld', 'transactionNumber' => '2026-0904', 'state' => $to]);

		return new ObjectTransitionedEvent(
			object: $entity,
			move: new TransitionMove(action: 'post', from: 'draft', to: $to),
			scope: new RegisterSchemaRef(register: '7', schema: '42'),
			actor: new TransitionActor(userId: 'boekhouder')
		);
	}//end event()

	/**
	 * The listener with the given seal service, the schema resolving to the slug.
	 *
	 * @param LedgerSealService $seals The seal service double.
	 * @param string            $slug  The slug the event's schema resolves to.
	 * @param LoggerInterface|null $logger Logger.
	 *
	 * @return GLTransactionSealListener
	 */
	private function listener(LedgerSealService $seals, string $slug = 'GLTransaction', ?LoggerInterface $logger = null): GLTransactionSealListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn($slug);

		return new GLTransactionSealListener($seals, $resolver, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end listener()

	public function testAPostedTransactionIsSealedWithItsUuidAsId(): void {
		$seals = $this->createMock(LedgerSealService::class);
		$seals->expects(self::once())->method('seal')->with([
			'administrationId' => 'adm-voorbeeld',
			'transactionNumber' => '2026-0904',
			'state' => 'posted',
			'id' => 'tx-uuid',
		])->willReturn(['sealSequence' => 3, 'previousSealHash' => 'a', 'sealHash' => 'b', 'sealedAt' => 'c']);

		$this->listener(seals: $seals)->handle(self::event());
	}//end testAPostedTransactionIsSealedWithItsUuidAsId()

	public function testAnotherStateOrSchemaOrEventIsLeftAlone(): void {
		$seals = $this->createMock(LedgerSealService::class);
		$seals->expects(self::never())->method('seal');

		$this->listener(seals: $seals)->handle(self::event(to: 'reversed'));
		$this->listener(seals: $seals, slug: 'StockMove')->handle(self::event());
		$this->listener(seals: $seals)->handle(new Event());
	}//end testAnotherStateOrSchemaOrEventIsLeftAlone()

	public function testASealThatFailsFailsThePostAndIsLogged(): void {
		$seals = $this->createMock(LedgerSealService::class);
		$seals->method('seal')->willThrowException(new RuntimeException('ledger_seal_locked: busy'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'Shillinq: a posted transaction could not be sealed',
			['transactionId' => 'tx-uuid', 'exception' => 'ledger_seal_locked: busy']
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('ledger_seal_locked: busy');

		$this->listener(seals: $seals, logger: $logger)->handle(self::event());
	}//end testASealThatFailsFailsThePostAndIsLogged()
}//end class
