<?php

/**
 * Tests for AdministrationMembershipService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DomainException;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationMembershipService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Membership rows are written with a valid role, once per user and administration.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-12
 */
final class AdministrationMembershipServiceTest extends TestCase {

	/**
	 * A new member is saved with the role, the granter and the options.
	 *
	 * @return void
	 */
	public function testAddsAMembership(): void {
		$store = $this->store(existing: []);

		$this->service($store)->addMember('adm-a', 'bert', 'boekhouder', 'anna', ['validFrom' => '2026-11-01', 'mayPostJournalEntries' => true]);

		self::assertCount(1, $store->saved);
		self::assertSame(
			['userId' => 'bert', 'administrationId' => 'adm-a', 'role' => 'boekhouder', 'grantedBy' => 'anna', 'validFrom' => '2026-11-01', 'mayPostJournalEntries' => true],
			$store->saved[0]['object']
		);
		self::assertSame('AdministrationMembership', $store->saved[0]['schema']);

	}//end testAddsAMembership()

	/**
	 * An unknown role is refused.
	 *
	 * @return void
	 */
	public function testUnknownRoleIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service($this->store(existing: []))->addMember('adm-a', 'bert', 'superuser', 'anna');

	}//end testUnknownRoleIsRefused()

	/**
	 * A user who is already a member is not added twice.
	 *
	 * @return void
	 */
	public function testExistingMemberIsNotAddedAgain(): void {
		$store = $this->store(existing: [['userId' => 'bert', 'administrationId' => 'adm-a']]);
		$this->expectException(DomainException::class);

		try {
			$this->service($store)->addMember('adm-a', 'bert', 'inkijker', 'anna');
		} finally {
			self::assertSame([], $store->saved);
		}

	}//end testExistingMemberIsNotAddedAgain()

	/**
	 * Build the service over a recording store.
	 *
	 * @param object $store The recording store: `existing` rows and a `saved` list.
	 *
	 * @return AdministrationMembershipService
	 */
	private function service(object $store): AdministrationMembershipService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(static fn (): array => $store->existing);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, $register, $schema) use ($store): ObjectEntityInterface {
				$store->saved[] = ['object' => $object, 'schema' => $schema];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);
				return $entity;
			}
		);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		return new AdministrationMembershipService(objectService: $objects, appConfig: $config);

	}//end service()

	/**
	 * A recording store.
	 *
	 * @param array<int,array<string,mixed>> $existing Rows findAll answers with.
	 *
	 * @return object
	 */
	private function store(array $existing): object {
		$store = new \stdClass();
		$store->existing = $existing;
		$store->saved = [];

		return $store;

	}//end store()
}//end class
