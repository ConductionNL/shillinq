<?php

/**
 * Tests for AdministrationManagedWriteListener.
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
 * @spec openspec/specs/accounting-standards-policy/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\AdministrationManagedWriteListener;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\AdministrationManagementAccess;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A standards policy is written only by someone who manages its administration.
 *
 * @spec openspec/specs/accounting-standards-policy/spec.md
 */
final class AdministrationManagedWriteListenerTest extends TestCase {

	/**
	 * A user who does not manage the administration cannot write its policy through the object API.
	 *
	 * @return void
	 */
	public function testNonOwnerWriteIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->entity('adm-b'), $this->entity('adm-b'));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(AdministrationManagedWriteListener::DENY_MESSAGE, $event->getErrors()['message'] ?? null);

	}//end testNonOwnerWriteIsRefused()

	/**
	 * Moving another administration's policy into one's own is refused on the stored side.
	 *
	 * @return void
	 */
	public function testMovingAnotherAdministrationsPolicyIsRefused(): void {
		$event = new ObjectUpdatingEvent($this->entity('adm-a'), $this->entity('adm-b'));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());

	}//end testMovingAnotherAdministrationsPolicyIsRefused()

	/**
	 * A policy without an administration is the instance default, which a non-admin cannot write.
	 *
	 * @return void
	 */
	public function testDefaultPolicyWriteByNonAdminIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(null));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());

	}//end testDefaultPolicyWriteByNonAdminIsRefused()

	/**
	 * The owner writes their administration's policy.
	 *
	 * @return void
	 */
	public function testOwnerWriteIsAllowed(): void {
		$event = new ObjectUpdatingEvent($this->entity('adm-a'), $this->entity('adm-a'));

		$this->listener()->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testOwnerWriteIsAllowed()

	/**
	 * A write without a session user (seeding, repair) is not judged.
	 *
	 * @return void
	 */
	public function testWriteWithoutSessionUserIsNotJudged(): void {
		$event = new ObjectCreatingEvent($this->entity('adm-b'));

		$this->listener(userId: null)->handle($event);

		self::assertFalse($event->isPropagationStopped());

	}//end testWriteWithoutSessionUserIsNotJudged()

	/**
	 * Build the listener; the caller manages adm-a only.
	 *
	 * @param string|null $userId The session user.
	 *
	 * @return AdministrationManagedWriteListener
	 */
	private function listener(?string $userId='alice'): AdministrationManagedWriteListener {
		$context = $this->createMock(AdministrationContextService::class);
		$context->method('currentUserId')->willReturn($userId);
		$access = $this->createMock(AdministrationManagementAccess::class);
		$access->method('canManage')->willReturnCallback(static fn (string $administrationId): bool => $administrationId === 'adm-a');
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturnCallback(
			static fn (?object $entity, string $expectedSlug): bool => $expectedSlug === 'StandardsPolicy'
		);

		return new AdministrationManagedWriteListener(
			administrationContext: $context,
			managementAccess: $access,
			schemaResolver: $resolver,
			logger: new NullLogger(),
		);

	}//end listener()

	/**
	 * A StandardsPolicy entity for an administration.
	 *
	 * @param string|null $administrationId The administration, or null for the default policy.
	 *
	 * @return ObjectEntity
	 */
	private function entity(?string $administrationId): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject(['name' => 'Accounting standards policy', 'administrationId' => $administrationId, 'frameworks' => []]);
		$entity->setSchema('StandardsPolicy');
		$entity->setRegister('shillinq');

		return $entity;
	}//end entity()
}//end class
