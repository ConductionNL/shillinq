<?php

/**
 * Unit tests for ExpenseCategoryAccountListener.
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
 * @spec openspec/changes/expenses-category-mapping/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Kernel\Contracts\ListenerSchemaResolverInterface;
use OCA\Shillinq\Listener\ExpenseCategoryAccountListener;
use OCA\Shillinq\Listener\PreSaveWrite;
use OCA\Shillinq\Service\ExpenseCategoryAccountCheck;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The account rule of REQ-ECM-001 on OpenRegister's pre-save veto.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(ExpenseCategoryAccountListener::class)]
#[UsesClass(ExpenseCategoryAccountCheck::class)]
#[UsesClass(PreSaveWrite::class)]
#[UsesClass(ObjectIdentifier::class)]
final class ExpenseCategoryAccountListenerTest extends TestCase {
	/**
	 * A category set to a balance sheet account is refused on create, with
	 * the account named and the requirement.
	 *
	 * @return void
	 */
	public function testABalanceSheetAccountIsRefusedOnCreate(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Shillinq: refused an ExpenseCategory whose account is not an expense account',
			['code' => 'software', 'reason' => "Account 1100 Debiteuren is not an expense account (its type is 'assets')."]
		);
		$event = new ObjectCreatingEvent($this->entity(data: ['code' => 'software', 'expenseAccount' => '1100', 'administrationId' => 'adm-1']));

		$this->listener(matches: true, logger: $logger)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			['message' => "Account 1100 Debiteuren is not an expense account (its type is 'assets').", 'requirement' => 'REQ-ECM-001'],
			$event->getErrors()
		);
	}//end testABalanceSheetAccountIsRefusedOnCreate()

	/**
	 * The same rule holds when a bookkeeper changes the account.
	 *
	 * @return void
	 */
	public function testABalanceSheetAccountIsRefusedOnUpdate(): void {
		$event = new ObjectUpdatingEvent($this->entity(data: ['code' => 'software', 'expenseAccount' => '1100', 'administrationId' => 'adm-1']));

		$this->listener(matches: true)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testABalanceSheetAccountIsRefusedOnUpdate()

	/**
	 * An expense account passes.
	 *
	 * @return void
	 */
	public function testAnExpenseAccountPasses(): void {
		$event = new ObjectCreatingEvent($this->entity(data: ['code' => 'software', 'expenseAccount' => '4340', 'administrationId' => 'adm-1']));

		$this->listener(matches: true)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAnExpenseAccountPasses()

	/**
	 * Another schema is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsLeftAlone(): void {
		$event = new ObjectCreatingEvent($this->entity(data: ['expenseAccount' => '1100', 'administrationId' => 'adm-1']));

		$this->listener(matches: false)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsLeftAlone()

	/**
	 * The listener over a two-account chart.
	 *
	 * @param bool                 $matches Whether the entity is an ExpenseCategory.
	 * @param LoggerInterface|null $logger  The logger, a mock when null.
	 *
	 * @return ExpenseCategoryAccountListener
	 */
	private function listener(bool $matches, ?LoggerInterface $logger = null): ExpenseCategoryAccountListener {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$store = new InMemoryObjectServiceStub(
			data: [
				'Account' => [
					['id' => 'a-1', 'accountNumber' => '4340', 'name' => 'Software en SaaS', 'accountType' => 'expenses', 'administrationId' => 'adm-1'],
					['id' => 'a-2', 'accountNumber' => '1100', 'name' => 'Debiteuren', 'accountType' => 'assets', 'administrationId' => 'adm-1'],
				],
			]
		);

		$resolver = $this->createMock(ListenerSchemaResolverInterface::class);
		$resolver->expects($this->once())->method('matchesSchema')
			->with($this->isInstanceOf(ObjectEntity::class), ExpenseCategoryAccountListener::SCHEMA_SLUG)
			->willReturn($matches);

		return new ExpenseCategoryAccountListener(
			accountCheck: new ExpenseCategoryAccountCheck(objectService: $store, registerLocation: $location),
			schemaResolver: $resolver,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end listener()

	/**
	 * An ObjectEntity carrying the payload.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema('ExpenseCategory');
		$entity->setRegister('shillinq');

		return $entity;
	}//end entity()
}//end class
