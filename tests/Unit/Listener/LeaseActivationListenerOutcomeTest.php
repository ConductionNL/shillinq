<?php

/**
 * Characterisation tests for LeaseActivationListener's dispatch and logging.
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
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\LeaseActivationListener;
use OCA\Shillinq\Service\LeasePaymentScheduleService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins which saves generate a schedule, with what arguments, and every log record.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 * phpcs:disable Generic.Commenting.DocComment,Squiz.Commenting,PEAR.Commenting
 */
final class LeaseActivationListenerOutcomeTest extends TestCase {

	public function testOutcomes(): void {
		$active = ['id' => 'l-1', 'administrationId' => ' adm-1 ', 'status' => 'active'];
		$cases = [
			'otherEvent' => ['event', 'LeaseContract', $active, null, null],
			'otherSchema' => ['created', 'Lease', $active, null, null],
			'createdDraft' => ['created', 'leasecontract', ['status' => 'draft'] + $active, null, null],
			'createdActive' => ['created', 'shillinq/LeaseContract', $active, null, null],
			'updatedFromDraft' => ['updated', 'LeaseContract', $active, ['status' => 'draft'], null],
			'updatedStillActive' => ['updated', 'LeaseContract', $active, ['status' => 'active'], null],
			'updatedNoOldStatus' => ['updated', 'LeaseContract', $active, [], null],
			'selfSlug' => ['created', 'LeaseContract', ['@self' => ['slug' => ' l-slug ', 'id' => 'x']] + $active, null, null],
			'selfId' => ['created', 'LeaseContract', ['@self' => ['slug' => '', 'id' => 'l-self']] + $active, null, null],
			'noAdministration' => ['created', 'LeaseContract', ['administrationId' => ' ', 'leaseNumber' => 'LN-1'] + $active, null, null],
			'noId' => ['created', 'LeaseContract', ['status' => 'active', 'administrationId' => 'adm-1'], null, null],
			'serviceFails' => ['created', 'LeaseContract', $active, null, 'schedule down'],
		];

		$actual = [];
		foreach ($cases as $name => [$kind, $slug, $new, $old, $failure]) {
			$actual[$name] = $this->outcome($kind, $slug, $new, $old, $failure);
		}

		$none = ['generated' => [], 'logs' => []];
		foreach (['otherEvent', 'otherSchema', 'createdDraft', 'updatedStillActive'] as $name) {
			self::assertSame($none, $actual[$name], $name);
		}

		self::assertSame(['generated' => [['l-1', 'adm-1']], 'logs' => []], $actual['createdActive']);
		self::assertSame(['generated' => [['l-1', 'adm-1']], 'logs' => []], $actual['updatedFromDraft']);
		self::assertSame(['generated' => [['l-1', 'adm-1']], 'logs' => []], $actual['updatedNoOldStatus']);
		self::assertSame([['l-slug', 'adm-1']], $actual['selfSlug']['generated']);
		self::assertSame([['l-self', 'adm-1']], $actual['selfId']['generated']);
		self::assertSame(
			[
				'generated' => [],
				'logs' => [
					[
						'warning',
						'LeaseActivationListener: activated lease carries no id/administrationId; schedule generation skipped',
						['leaseNumber' => 'LN-1'],
					],
				],
			],
			$actual['noAdministration']
		);
		self::assertSame(['leaseNumber' => null], $actual['noId']['logs'][0][2]);
		self::assertSame(
			[['error', 'LeaseActivationListener: failed to generate the lease amortization schedule', ['exception' => 'schedule down']]],
			$actual['serviceFails']['logs']
		);
	}

	/**
	 * Dispatch one save and capture the schedule calls and log records.
	 *
	 * @param string $kind 'created', 'updated' or 'event'.
	 * @param string $slug The resolved schema slug.
	 * @param array<string,mixed> $new The saved lease.
	 * @param array<string,mixed>|null $old The previous lease (updates only).
	 * @param string|null $failure The schedule service's exception message, if it throws.
	 *
	 * @return array<string,mixed>
	 */
	private function outcome(string $kind, string $slug, array $new, array|null $old, string|null $failure): array {
		$generated = [];
		$service = $this->createMock(LeasePaymentScheduleService::class);
		$service->method('generateSchedule')->willReturnCallback(
			function (string $leaseContractId, string $administrationId) use (&$generated, $failure): int {
				$generated[] = [$leaseContractId, $administrationId];
				if ($failure !== null) {
					throw new \RuntimeException($failure);
				}

				return 0;
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
		$entity->setObject($new);
		$oldEntity = new ObjectEntity();
		$oldEntity->setObject($old ?? []);
		$event = match ($kind) {
			'created' => new ObjectCreatedEvent($entity),
			'updated' => new ObjectUpdatedEvent($entity, $oldEntity),
			default => new Event(),
		};

		(new LeaseActivationListener($service, $resolver, $logger))->handle($event);

		return ['generated' => $generated, 'logs' => $logger->records];
	}
}
