<?php

/**
 * Characterisation tests for OrderFulfilmentTransitionListener's dispatch and logging.
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
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCA\Shillinq\Listener\OrderFulfilmentTransitionListener;
use OCA\Shillinq\Service\BudgetImpactEmitter;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\TenderNedStatusSync;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the emit and sync calls and every log record per transition.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class OrderFulfilmentTransitionOutcomeTest extends TestCase {

	public function testOutcomes(): void {
		$final = ['id' => 'of-1', 'deliveryType' => 'eindoplevering', 'approved' => 1];
		$none = ['calls' => [], 'logs' => []];

		self::assertSame($none, $this->outcome('in-progress', 'OrderFulfilment', $final));
		self::assertSame($none, $this->outcome('completed', 'Commitment', $final));
		self::assertSame(['calls' => [['emit', $final], ['sync', $final]], 'logs' => []], $this->outcome('completed', 'shillinq/OrderFulfilment', $final));
		$unapproved = ['approved' => false] + $final;
		self::assertSame([['emit', $unapproved]], $this->outcome('completed', 'orderfulfilment', $unapproved)['calls']);
		$partial = ['deliveryType' => 'deel'] + $final;
		self::assertSame([['emit', $partial]], $this->outcome('completed', 'OPDRACHTUITVOERING', $partial)['calls']);
		self::assertSame(
			['calls' => [], 'logs' => [['warning', 'OrderFulfilmentTransitionListener: fail-soft on completion', ['exception' => 'emit down']]]],
			$this->outcome('completed', 'OrderFulfilment', ['id' => 'FAIL'] + $final)
		);
	}

	/**
	 * Dispatch one transition and capture the emit/sync calls and log records.
	 *
	 * @param string $to The target state.
	 * @param string $slug The resolved schema slug.
	 * @param array<string,mixed> $object The fulfilment payload.
	 *
	 * @return array<string,mixed>
	 */
	private function outcome(string $to, string $slug, array $object): array {
		$calls = [];
		$emitter = $this->createMock(BudgetImpactEmitter::class);
		$emitter->method('emitMilestoneCompleted')->willReturnCallback(
			function (array $oplevering) use (&$calls): void {
				if (($oplevering['id'] ?? '') === 'FAIL') {
					throw new \RuntimeException('emit down');
				}

				$calls[] = ['emit', $oplevering];
			}
		);
		$sync = $this->createMock(TenderNedStatusSync::class);
		$sync->method('syncCompletion')->willReturnCallback(
			function (array $oplevering) use (&$calls): bool {
				$calls[] = ['sync', $oplevering];
				return true;
			}
		);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('schemaSlug')->willReturn($slug);
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};
		$entity = new ObjectEntity();
		$entity->setObject($object);
		$event = new ObjectTransitionedEvent(object: $entity, move: new TransitionMove(action: 'complete', from: 'in-progress', to: $to), scope: new RegisterSchemaRef(register: 'shillinq', schema: 'OrderFulfilment'), actor: new TransitionActor(userId: 'alice'));

		(new OrderFulfilmentTransitionListener($emitter, $sync, $resolver, $logger))->handle($event);

		return ['calls' => $calls, 'logs' => $logger->records];
	}
}
