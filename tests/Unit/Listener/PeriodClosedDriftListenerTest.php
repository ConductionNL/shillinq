<?php

/**
 * Unit tests for PeriodClosedDriftListener.
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
 * @spec openspec/changes/tax-vat-position-and-suppletie/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Shillinq\Listener\PeriodClosedDriftListener;
use OCA\Shillinq\Service\Tax\FiledReturnDriftCheck;
use OCA\Shillinq\Service\VatSuppletieDetectionService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Closing a period checks its fiscal year's filed returns for drift (REQ-VPS-003).
 *
 * The drift check is real; only the detection that compares one return with
 * the books is doubled, so the test sees which returns it was asked about.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(PeriodClosedDriftListener::class)]
#[UsesClass(FiledReturnDriftCheck::class)]
#[UsesClass(ObjectIdentifier::class)]
final class PeriodClosedDriftListenerTest extends TestCase {

	/**
	 * The detection service.
	 *
	 * @var VatSuppletieDetectionService&MockObject
	 */
	private VatSuppletieDetectionService&MockObject $suppletie;

	/**
	 * The ids detect() was called with.
	 *
	 * @var list<string>
	 */
	private array $detected = [];

	/**
	 * The listener over a store with the Q2 and Q3 2026 returns filed and a 2025 one.
	 *
	 * @return PeriodClosedDriftListener
	 */
	private function listener(): PeriodClosedDriftListener {
		$this->detected = [];
		$this->suppletie = $this->createMock(VatSuppletieDetectionService::class);
		$this->suppletie->method('detect')->willReturnCallback(
			function (string $vatReturnId): ?array {
				$this->detected[] = $vatReturnId;
				return null;
			}
		);

		$store = new InMemoryObjectServiceStub(
			[
				'BtwAangifte' => [
					['id' => 'q2-2026', 'administrationId' => 'adm-1', 'periodYear' => 2026, 'statusCode' => 'filed'],
					['id' => 'q3-2026', 'administrationId' => 'adm-1', 'periodYear' => 2026, 'statusCode' => 'verified'],
					['id' => 'q4-2025', 'administrationId' => 'adm-1', 'periodYear' => 2025, 'statusCode' => 'filed'],
				],
				'VatCorrection' => [],
			]
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$check = new FiledReturnDriftCheck($store, $this->suppletie, $appConfig, $this->createStub(LoggerInterface::class));

		$resolver = $this->createStub(ListenerSchemaResolverInterface::class);
		$resolver->method('schemaSlug')->willReturnCallback(
			static fn (?object $entity): string => (string)($entity?->getSchema() ?? '')
		);

		return new PeriodClosedDriftListener($check, $resolver, $this->createStub(LoggerInterface::class));
	}//end listener()

	/**
	 * An object entity of a schema.
	 *
	 * @param string              $schema The schema slug.
	 * @param array<string,mixed> $data   The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * September 2026 as a fiscal period in a state.
	 *
	 * @param string $state The state.
	 *
	 * @return array<string,mixed>
	 */
	private function september(string $state): array {
		return [
			'periodId' => '2026-09',
			'administrationId' => 'adm-1',
			'fiscalYear' => 2026,
			'startDate' => '2026-09-01',
			'state' => $state,
		];
	}//end september()

	/**
	 * The update of September 2026 from one state into another.
	 *
	 * @param string $new The state after.
	 * @param string $old The state before.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function periodUpdate(string $new, string $old): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent(
			$this->entity('FiscalPeriod', $this->september(state: $new)),
			$this->entity('FiscalPeriod', $this->september(state: $old))
		);
	}//end periodUpdate()

	/**
	 * Closing September 2026 checks the filed 2026 returns, not the 2025 one.
	 *
	 * @return void
	 */
	public function testClosingAPeriodChecksTheFiledReturnsOfItsYear(): void {
		$listener = $this->listener();

		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity('FiscalPeriod', $this->september(state: 'closed')),
				$this->entity('FiscalPeriod', $this->september(state: 'closing'))
			)
		);

		$this->assertSame(['q2-2026', 'q3-2026'], $this->detected);
	}//end testClosingAPeriodChecksTheFiledReturnsOfItsYear()

	/**
	 * Without a fiscal year on the period, the year of its start date is used.
	 *
	 * @return void
	 */
	public function testTheYearComesFromTheStartDateWhenTheFieldIsEmpty(): void {
		$listener = $this->listener();
		$closed = array_merge($this->september(state: 'closed'), ['fiscalYear' => null, 'startDate' => '2025-12-01']);

		$listener->handle(new ObjectUpdatedEvent($this->entity('FiscalPeriod', $closed), $this->entity('FiscalPeriod', $this->september(state: 'open'))));

		$this->assertSame(['q4-2025'], $this->detected);
	}//end testTheYearComesFromTheStartDateWhenTheFieldIsEmpty()

	/**
	 * Saving a period that was already closed, or one that is not closed, checks nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheChangeIntoClosedChecks(): void {
		$listener = $this->listener();

		foreach ([['closed', 'closed'], ['audit-locked', 'closed'], ['closing', 'open']] as [$new, $old]) {
			$listener->handle($this->periodUpdate(new: $new, old: $old));
		}

		$this->assertSame([], $this->detected);
	}//end testOnlyTheChangeIntoClosedChecks()

	/**
	 * Another schema's update, or a create, checks nothing.
	 *
	 * @return void
	 */
	public function testOtherSchemasAndOtherEventsAreIgnored(): void {
		$listener = $this->listener();

		$invoice = $this->entity('Invoice', $this->september(state: 'closed'));
		$listener->handle(new ObjectUpdatedEvent($invoice, $this->entity('Invoice', $this->september(state: 'open'))));
		$listener->handle(new ObjectCreatedEvent($this->entity('FiscalPeriod', $this->september(state: 'closed'))));

		$this->assertSame([], $this->detected);
	}//end testOtherSchemasAndOtherEventsAreIgnored()
}//end class
