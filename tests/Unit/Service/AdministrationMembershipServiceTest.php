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
use OCA\Shillinq\Service\AdministrationMembershipService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

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
	 * Build the service.
	 *
	 * @param object $store The ObjectService double.
	 *
	 * @return AdministrationMembershipService
	 */
	private function service(object $store): AdministrationMembershipService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		return new AdministrationMembershipService(container: $container, appConfig: $config);

	}//end service()

	/**
	 * An ObjectService double that records saves.
	 *
	 * @param array<int,array<string,mixed>> $existing Rows findAll answers with.
	 *
	 * @return object
	 */
	private function store(array $existing): object {
		return new class($existing) {

			/**
			 * Saved calls.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $saved = [];

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $existing Rows.
			 */
			public function __construct(private array $existing) {
			}

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}

			/**
			 * The existing rows.
			 *
			 * @param array<string,mixed> $params Query.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params): array {
				return $this->existing;
			}

			/**
			 * Record the save.
			 *
			 * @param mixed ...$args Named arguments.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(mixed ...$args): array {
				$this->saved[] = $args;
				return $args['object'];
			}
		};

	}//end store()
}//end class
