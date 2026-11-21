<?php

/**
 * Unit tests for VatTariffStatutoryLockListener.
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
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Shillinq\Listener\PreSaveWrite;
use OCA\Shillinq\Listener\VatTariffStatutoryLockListener;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * A person cannot change a statutory tariff; an added one stays theirs (REQ-TVRD-001).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(VatTariffStatutoryLockListener::class)]
#[UsesClass(PreSaveWrite::class)]
final class VatTariffStatutoryLockListenerTest extends TestCase {

	/**
	 * The listener for a signed-in person, or for a system save.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 * @param bool $matches  Whether the entity is a VatTariff.
	 *
	 * @return VatTariffStatutoryLockListener
	 */
	private function listener(bool $signedIn = true, bool $matches = true): VatTariffStatutoryLockListener {
		$resolver = $this->createMock(ListenerSchemaResolver::class);
		$resolver->method('matchesSchema')->willReturnCallback(
			static fn (object $entity, string $expectedSlug): bool => ($matches === true && $expectedSlug === 'VatTariff')
		);
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
		}

		$session->method('getUser')->willReturn($user);

		return new VatTariffStatutoryLockListener(schemaResolver: $resolver, userSession: $session);
	}//end listener()

	/**
	 * A tariff entity.
	 *
	 * @param array<string,mixed> $data The tariff.
	 *
	 * @return ObjectEntity
	 */
	private static function tariff(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($data);
		$entity->setSchema('VatTariff');
		return $entity;
	}//end tariff()

	/**
	 * The seeded high tariff.
	 *
	 * @param array<string,mixed> $overrides Changed fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function high(array $overrides = []): array {
		return array_merge(['code' => 'high', 'ratePercentage' => 21, 'effectiveFrom' => '2019-01-01', 'statutory' => true], $overrides);
	}//end high()

	/**
	 * Editing the statutory high rate is refused, naming the tariff and the requirement.
	 *
	 * @return void
	 */
	public function testAPersonCannotEditTheStatutoryHighRate(): void {
		$event = new ObjectUpdatingEvent(self::tariff(self::high(['ratePercentage' => 22])), self::tariff(self::high()));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(
			['message' => sprintf(VatTariffStatutoryLockListener::MESSAGE_CHANGE, 'high'), 'requirement' => 'REQ-TVRD-001'],
			$event->getErrors()
		);
	}//end testAPersonCannotEditTheStatutoryHighRate()

	/**
	 * Clearing the statutory mark in the same write does not unlock it.
	 *
	 * @return void
	 */
	public function testClearingTheMarkDoesNotUnlockIt(): void {
		$event = new ObjectUpdatingEvent(self::tariff(self::high(['statutory' => false])), self::tariff(self::high()));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testClearingTheMarkDoesNotUnlockIt()

	/**
	 * An added tariff (mid-12) is created and edited freely.
	 *
	 * @return void
	 */
	public function testAnAddedTariffStaysEditable(): void {
		$added = ['code' => 'mid-12', 'ratePercentage' => 12, 'effectiveFrom' => '2027-01-01'];
		$create = new ObjectCreatingEvent(self::tariff($added));
		$update = new ObjectUpdatingEvent(self::tariff(array_merge($added, ['ratePercentage' => 13])), self::tariff($added));

		$this->listener()->handle($create);
		$this->listener()->handle($update);

		self::assertFalse($create->isPropagationStopped());
		self::assertFalse($update->isPropagationStopped());
	}//end testAnAddedTariffStaysEditable()

	/**
	 * A person cannot mint a statutory tariff.
	 *
	 * @return void
	 */
	public function testAPersonCannotCreateAStatutoryTariff(): void {
		$event = new ObjectCreatingEvent(self::tariff(['code' => 'mid-12', 'ratePercentage' => 12, 'statutory' => true]));

		$this->listener()->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertSame(sprintf(VatTariffStatutoryLockListener::MESSAGE_CREATE, 'mid-12'), $event->getErrors()['message']);
	}//end testAPersonCannotCreateAStatutoryTariff()

	/**
	 * The seeder and repair steps save without a user and pass.
	 *
	 * @return void
	 */
	public function testASystemSavePasses(): void {
		$create = new ObjectCreatingEvent(self::tariff(self::high()));
		$update = new ObjectUpdatingEvent(self::tariff(self::high(['label' => 'Hoog tarief'])), self::tariff(self::high()));

		$this->listener(signedIn: false)->handle($create);
		$this->listener(signedIn: false)->handle($update);

		self::assertFalse($create->isPropagationStopped());
		self::assertFalse($update->isPropagationStopped());
	}//end testASystemSavePasses()

	/**
	 * Another schema's write is untouched.
	 *
	 * @return void
	 */
	public function testOtherSchemasAreUntouched(): void {
		$event = new ObjectUpdatingEvent(self::tariff(self::high(['ratePercentage' => 22])), self::tariff(self::high()));

		$this->listener(matches: false)->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testOtherSchemasAreUntouched()
}//end class
