<?php

/**
 * Unit tests for InvestmentAssetListener.
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
 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\InvestmentAssetListener;
use OCA\Shillinq\Service\InvestmentDeduction\InvestmentDeductionService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Only an active FixedAsset reaches the eligibility record, through the real event classes.
 *
 * @covers \OCA\Shillinq\Listener\InvestmentAssetListener
 * @uses   \OCA\Shillinq\Service\InvestmentDeduction\InvestmentDeductionService
 */
class InvestmentAssetListenerTest extends TestCase {

	/**
	 * A FixedAsset entity.
	 *
	 * @param array<string, mixed> $data The data.
	 * @param string               $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data, string $uuid = 'fa-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * Handle the event and return the calls made to the service.
	 *
	 * @param Event $event      The event.
	 * @param bool  $isFixedAsset Whether the schema matches.
	 * @param bool  $fails      Whether the service throws.
	 *
	 * @return list<array{0: string, 1: array<string, mixed>}>
	 */
	private function calls(Event $event, bool $isFixedAsset = true, bool $fails = false): array {
		$calls = [];
		$service = $this->createMock(InvestmentDeductionService::class);
		$service->method('recordEligibility')->willReturnCallback(
			function (string $fixedAssetId, array $fixedAsset) use (&$calls, $fails): ?array {
				$calls[] = [$fixedAssetId, $fixedAsset];
				if ($fails === true) {
					throw new \RuntimeException('register down');
				}

				return [];
			}
		);
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturnCallback(static fn (?object $entity, string $slug): bool => $isFixedAsset && $slug === 'FixedAsset');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($fails === true ? self::once() : self::never())->method('error')
			->with('InvestmentAssetListener: failed to record investment deduction eligibility', ['exception' => 'register down']);

		(new InvestmentAssetListener(deductions: $service, schemaResolver: $resolver, logger: $logger))->handle($event);

		return $calls;
	}//end calls()

	/**
	 * A created active asset, and an updated one read from the new object, are recorded.
	 *
	 * @return void
	 */
	public function testAnActiveAssetIsRecorded(): void {
		$active = ['lifecycleState' => 'active', 'administrationId' => 'adm-1'];

		self::assertSame([['fa-1', $active]], $this->calls(event: new ObjectCreatedEvent($this->entity(data: $active))));
		self::assertSame(
			[['fa-2', ['status' => 'active']]],
			$this->calls(event: new ObjectUpdatedEvent($this->entity(data: ['status' => 'active'], uuid: 'fa-2'), $this->entity(data: ['status' => 'inactive'], uuid: 'fa-2')))
		);
	}//end testAnActiveAssetIsRecorded()

	/**
	 * A proposed asset, another schema or another event records nothing; a
	 * failure is logged, not thrown.
	 *
	 * @return void
	 */
	public function testAnythingElseRecordsNothingAndAFailureIsLogged(): void {
		self::assertSame([], $this->calls(event: new ObjectCreatedEvent($this->entity(data: ['lifecycleState' => 'proposed']))));
		self::assertSame([], $this->calls(event: new ObjectCreatedEvent($this->entity(data: ['lifecycleState' => 'active'])), isFixedAsset: false));
		self::assertSame([], $this->calls(event: new Event()));
		self::assertCount(1, $this->calls(event: new ObjectCreatedEvent($this->entity(data: ['lifecycleState' => 'active'])), fails: true));
	}//end testAnythingElseRecordsNothingAndAFailureIsLogged()
}//end class
