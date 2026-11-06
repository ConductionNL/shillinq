<?php

/**
 * Tests for AdministrationMembershipWriteListener.
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
 * @spec openspec/changes/administration-import-migration/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\AdministrationMembershipWriteListener;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An import batch or mapping is written only into an administration the writer belongs to.
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-3
 */
final class AdministrationMembershipWriteListenerTest extends TestCase {

	/**
	 * Creating a batch for another administration is refused.
	 *
	 * @return void
	 */
	public function testCreateIntoAnotherAdministrationIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(['administrationId' => 'adm-other', 'status' => 'draft']));

		$this->listener(userId: 'alice')->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame('administrationId', $event->getErrors()['field'] ?? null);
		self::assertSame(AdministrationMembershipWriteListener::DENY_MESSAGE, $event->getErrors()['message'] ?? null);

	}//end testCreateIntoAnotherAdministrationIsRefused()

	/**
	 * A parse or post transition saves the batch, and is refused for a non-member.
	 *
	 * @return void
	 */
	public function testTransitionOfAnotherAdministrationsBatchIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->entity(['administrationId' => 'adm-other', 'status' => 'posting']));

		$this->listener(userId: 'alice')->handle($event);

		self::assertTrue($event->isPropagationStopped());

	}//end testTransitionOfAnotherAdministrationsBatchIsRefused()

	/**
	 * A member writes their own administration's batch.
	 *
	 * @return void
	 */
	public function testMemberWriteIsAllowed(): void {
		$event = new ObjectUpdatingEvent($this->entity(['administrationId' => 'adm-1', 'status' => 'parsing']));

		$this->listener(userId: 'alice')->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testMemberWriteIsAllowed()

	/**
	 * A background step runs without a session user and is not judged here.
	 *
	 * @return void
	 */
	public function testWriteWithoutSessionUserIsNotJudged(): void {
		$event = new ObjectUpdatingEvent($this->entity(['administrationId' => 'adm-other', 'status' => 'posted']));

		$this->listener(userId: null)->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testWriteWithoutSessionUserIsNotJudged()

	/**
	 * Another schema is never touched.
	 *
	 * @return void
	 */
	public function testOtherSchemaIsIgnored(): void {
		$event = new ObjectCreatingEvent($this->entity(['administrationId' => 'adm-other']));

		$this->listener(userId: 'alice', matches: false)->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testOtherSchemaIsIgnored()

	/**
	 * Build the listener; the caller is a member of adm-1 only.
	 *
	 * @param string|null $userId  The session user, or null for none.
	 * @param bool        $matches Whether the entity is an import schema.
	 *
	 * @return AdministrationMembershipWriteListener
	 */
	private function listener(?string $userId, bool $matches=true): AdministrationMembershipWriteListener {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($userId);
		$context->method('canAccess')->willReturnCallback(static fn (string $administrationId): bool => $administrationId === 'adm-1');

		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturnCallback(
			static fn (?object $entity, string $expectedSlug): bool => $matches === true && $expectedSlug === 'ImportBatch'
		);

		return new AdministrationMembershipWriteListener(
			administrationContext: $context,
			schemaResolver: $resolver,
			logger: new NullLogger(),
		);

	}//end listener()

	/**
	 * An ImportBatch entity carrying the given data.
	 *
	 * @param array<string,mixed> $data The object data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema('ImportBatch');
		$entity->setRegister('shillinq');

		return $entity;
	}//end entity()
}//end class
