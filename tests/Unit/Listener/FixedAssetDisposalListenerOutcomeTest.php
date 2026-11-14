<?php

/**
 * Characterisation tests for FixedAssetDisposalListener's dispatch and logging.
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
use OCA\Shillinq\Listener\FixedAssetDisposalListener;
use OCA\Shillinq\Service\FixedAssetDisposalService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins which transitions post a disposal journal, with what arguments, and every log record.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class FixedAssetDisposalListenerOutcomeTest extends TestCase {

	public function testOutcomes(): void {
		$asset = ['assetNumber' => 'FA-1', 'administrationId' => ' adm-1 '];
		$none = ['posted' => [], 'logs' => []];

		self::assertSame($none, $this->outcome(new Event()));
		self::assertSame($none, $this->outcome($this->transition('Vehicle', 'retired', $asset)));
		self::assertSame($none, $this->outcome($this->transition('FixedAsset', 'active', $asset)));
		foreach ([' FixedAsset ', 'fixed-asset', 'FIXED_ASSET'] as $schema) {
			self::assertSame(
				['posted' => [['adm-1', $asset]], 'logs' => []],
				$this->outcome($this->transition($schema, 'retired', $asset)),
				$schema
			);
		}

		self::assertSame(
			[
				'posted' => [],
				'logs' => [
					[
						'warning',
						'FixedAssetDisposalListener: retired FixedAsset carries no administrationId; disposal journal skipped',
						['assetNumber' => 'FA-1'],
					],
				],
			],
			$this->outcome($this->transition('fixedasset', 'retired', ['administrationId' => ''] + $asset))
		);
		self::assertSame(
			[['error', 'FixedAssetDisposalListener: failed to post the fixed-asset disposal journal', ['exception' => 'ledger down']]],
			$this->outcome($this->transition('fixedasset', 'retired', ['assetNumber' => 'FAIL'] + $asset))['logs']
		);
	}

	private function transition(string $schema, string $to, array $object): ObjectTransitionedEvent {
		$entity = new ObjectEntity();
		$entity->setObject($object);
		return new ObjectTransitionedEvent(object: $entity, move: new TransitionMove(action: 'retire', from: 'active', to: $to), scope: new RegisterSchemaRef(register: 'shillinq', schema: $schema), actor: new TransitionActor(userId: 'alice'));
	}

	/**
	 * Dispatch one event and capture the posting calls and log records.
	 *
	 * @param Event $event The event.
	 *
	 * @return array<string,mixed>
	 */
	private function outcome(Event $event): array {
		$posted = [];
		$service = $this->createMock(FixedAssetDisposalService::class);
		$service->method('postDisposalJournal')->willReturnCallback(
			function (string $administrationId, array $asset) use (&$posted): array {
				if (($asset['assetNumber'] ?? '') === 'FAIL') {
					throw new \RuntimeException('ledger down');
				}

				$posted[] = [$administrationId, $asset];
				return [];
			}
		);
		$logger = new class extends AbstractLogger {

			public array $records = [];

			public function log($level, \Stringable|string $message, array $context = []): void {
				$this->records[] = [(string) $level, (string) $message, $context];
			}
		};

		(new FixedAssetDisposalListener($service, $logger))->handle($event);

		return ['posted' => $posted, 'logs' => $logger->records];
	}
}
