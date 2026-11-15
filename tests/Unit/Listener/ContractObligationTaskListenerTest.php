<?php

/**
 * Characterisation tests for ContractObligationTaskListener.
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
 * @spec openspec/specs/compliance-deadline-calendar/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\ContractObligationTaskListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\ObligationTaskBridge;
use OCA\Shillinq\Service\RegisterLocation;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins which obligations get a task, what is written back and what is logged.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class ContractObligationTaskListenerTest extends TestCase {

	public function testOutcomes(): void {
		$obligation = ['title' => 'Renew licence'];
		$cases = [
			'otherEvent' => ['event', 'ContractObligation', $obligation, 7, ['taskLinkStatus' => 'linked']],
			'otherSchema' => ['created', 'Contract', $obligation, 7, ['taskLinkStatus' => 'linked']],
			'suffixSchema' => ['created', ' Shillinq_ContractObligation ', $obligation, 7, ['taskLinkStatus' => 'linked', 'taskUri' => 'tasks://1']],
			'alreadyLinked' => ['updated', 'ContractObligation', ['taskLinkStatus' => 'linked'], 7, []],
			'noId' => ['updated', 'contractobligation', $obligation, null, []],
			'ownId' => ['updated', 'ContractObligation', ['id' => 'ob-1'] + $obligation, 7, ['taskLinkStatus' => 'failed', 'taskUri' => null]],
			'entityId' => ['created', 'ContractObligation', $obligation, 42, []],
			'bridgeFails' => ['created', 'ContractObligation', $obligation, 7, '*throw*'],
		];

		$actual = [];
		foreach ($cases as $name => [$kind, $slug, $object, $entityId, $bridgeResult]) {
			$actual[$name] = $this->outcome($kind, $slug, $object, $entityId, $bridgeResult);
		}

		$none = ['bridge' => [], 'updates' => [], 'logs' => []];
		self::assertSame($none, $actual['otherEvent']);
		self::assertSame($none, $actual['otherSchema']);
		self::assertSame($none, $actual['alreadyLinked']);
		self::assertSame($none, $actual['noId']);
		self::assertSame(
			[
				'bridge' => [['title' => 'Renew licence', 'id' => '7']],
				'updates' => [['shillinq', 'ContractObligation', '7', ['taskLinkStatus' => 'linked', 'taskUri' => 'tasks://1']]],
				'logs' => [
					[
						'info',
						'ContractObligationTaskListener: obligation task link attempted',
						[
							'obligationId' => '7',
							'taskLinkStatus' => 'linked',
						],
					],
				],
			],
			$actual['suffixSchema']
		);
		self::assertSame([['id' => 'ob-1', 'title' => 'Renew licence']], $actual['ownId']['bridge']);
		self::assertSame([['shillinq', 'ContractObligation', 'ob-1', ['taskLinkStatus' => 'failed']]], $actual['ownId']['updates']);
		self::assertSame(
			[['shillinq', 'ContractObligation', '42', ['taskLinkStatus' => 'failed']]],
			$actual['entityId']['updates']
		);
		self::assertSame('unknown', $actual['entityId']['logs'][0][2]['taskLinkStatus']);
		self::assertSame([], $actual['bridgeFails']['updates']);
		self::assertSame(
			[['warning', 'ContractObligationTaskListener: task creation failed (fail-soft)', ['exception' => 'bridge down']]],
			$actual['bridgeFails']['logs']
		);
	}

	/**
	 * Dispatch one event and capture the bridge calls, write-backs and logs.
	 *
	 * @param string $kind 'created', 'updated' or 'event'.
	 * @param string $slug The resolved schema slug.
	 * @param array<string,mixed> $object The obligation payload.
	 * @param int|null $entityId The entity id.
	 * @param mixed $bridgeResult The bridge answer, or '*throw*'.
	 *
	 * @return array<string,mixed>
	 */
	private function outcome(string $kind, string $slug, array $object, int|null $entityId, mixed $bridgeResult): array {
		$bridgeCalls = [];
		$bridge = $this->createMock(ObligationTaskBridge::class);
		$bridge->method('createTaskForObligation')->willReturnCallback(
			function (array $obligation) use (&$bridgeCalls, $bridgeResult): array {
				$bridgeCalls[] = $obligation;
				if ($bridgeResult === '*throw*') {
					throw new \RuntimeException('bridge down');
				}

				return $bridgeResult;
			}
		);
		$updates = [];
		$scope = ['', ''];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->willReturnCallback(
			function (string $register) use (&$scope, $objectService): ObjectServiceInterface {
				$scope[0] = $register;
				return $objectService;
			}
		);
		$objectService->method('setSchema')->willReturnCallback(
			function (string $schema) use (&$scope, $objectService): ObjectServiceInterface {
				$scope[1] = $schema;
				return $objectService;
			}
		);
		$objectService->method('updateObject')->willReturnCallback(
			function (string $id, array $data) use (&$updates, &$scope): ObjectEntityInterface {
				$updates[] = [$scope[0], $scope[1], $id, $data];
				return new ObjectEntity();
			}
		);
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
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
		$entity->setId($entityId);
		$event = match ($kind) {
			'created' => new ObjectCreatedEvent($entity),
			'updated' => new ObjectUpdatedEvent($entity, $entity),
			default => new Event(),
		};

		(new ContractObligationTaskListener($settings, $bridge, $resolver, $logger, $objectService))->handle($event);

		return ['bridge' => $bridgeCalls, 'updates' => $updates, 'logs' => $logger->records];
	}
}
