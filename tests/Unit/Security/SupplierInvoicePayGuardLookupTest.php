<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\Shillinq\Lifecycle\SupplierInvoicePayGuard;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The pay guard finds the invoice's AP transaction the way OpenRegister answers:
 * find() by uuid within the caller's organisation, filters on JSON properties
 * only (the entity uuid is not one of them).
 *
 * @covers \OCA\Shillinq\Lifecycle\SupplierInvoicePayGuard
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class SupplierInvoicePayGuardLookupTest extends TestCase {

	private const OWN = '11111111-1111-4111-8111-111111111111';

	private const OPEN = '22222222-2222-4222-8222-222222222222';

	private const FOREIGN = '33333333-3333-4333-8333-333333333333';

	private const OTHER_ADMIN = '44444444-4444-4444-8444-444444444444';

	/** @var array<int, array<string, mixed>> */
	public array $calls = [];

	/**
	 * The AP transactions OpenRegister holds: uuid => [organisation, object data].
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	private static function store(): array {
		return [
			self::OWN => ['org-a', ['state' => 'paid', 'administrationId' => 'adm-1']],
			self::OPEN => ['org-a', ['state' => 'issued', 'administrationId' => 'adm-1']],
			self::FOREIGN => ['org-b', ['state' => 'paid', 'administrationId' => 'adm-1']],
			self::OTHER_ADMIN => ['org-a', ['state' => 'paid', 'administrationId' => 'adm-2']],
		];
	}

	/**
	 * The guard over an OpenRegister double for a caller in organisation org-a.
	 */
	private function guard(): SupplierInvoicePayGuard {
		$test = $this;
		$objectService = new class($test, self::store()) {
			public function __construct(private SupplierInvoicePayGuardLookupTest $test, private array $store) {
			}
			public function setRegister($register): self {
				return $this;
			}
			public function setSchema($schema): self {
				return $this;
			}
			public function find(string $id, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->calls[] = ['find', $id, $_rbac, $_multitenancy];
				$hit = $this->store[$id] ?? null;
				if ($hit === null || ($_multitenancy === true && $hit[0] !== 'org-a')) {
					throw new RuntimeException('DoesNotExist');
				}
				return $hit[1];
			}
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->calls[] = ['findAll', $config, $_rbac, $_multitenancy];
				$rows = [];
				foreach ($this->store as [$organisation, $data]) {
					if ($_multitenancy === true && $organisation !== 'org-a') {
						continue;
					}
					foreach (($config['filters'] ?? []) as $property => $value) {
						if (($data[$property] ?? null) !== $value) {
							continue 2;
						}
					}
					$rows[] = $data;
				}
				return $rows;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		return new SupplierInvoicePayGuard($container, $settings, $this->createMock(LoggerInterface::class));
	}

	public function testThePaidTransactionOfTheInvoiceAllowsPay(): void {
		$result = $this->guard()->check(['apTransactionId' => self::OWN, 'administrationId' => 'adm-1'], 'pay', 'ann');
		self::assertTrue($result->isAllowed());
	}

	public function testAnUnpaidTransactionRefusesPay(): void {
		self::assertFalse($this->guard()->check(['apTransactionId' => self::OPEN, 'administrationId' => 'adm-1'], 'pay', 'ann')->isAllowed());
	}

	public function testAPaidTransactionOfAnotherOrganisationRefusesPay(): void {
		self::assertFalse($this->guard()->check(['apTransactionId' => self::FOREIGN, 'administrationId' => 'adm-1'], 'pay', 'ann')->isAllowed());
	}

	public function testAPaidTransactionOfAnotherAdministrationRefusesPay(): void {
		self::assertFalse($this->guard()->check(['apTransactionId' => self::OTHER_ADMIN, 'administrationId' => 'adm-1'], 'pay', 'ann')->isAllowed());
	}

	public function testUnknownOrMissingTransactionRefusesPay(): void {
		self::assertFalse($this->guard()->check(['apTransactionId' => 'nope', 'administrationId' => 'adm-1'], 'pay', 'ann')->isAllowed());
		self::assertFalse($this->guard()->check(['administrationId' => 'adm-1'], 'pay', 'ann')->isAllowed());
	}

	public function testTheLookupReadsByUuidWithoutRbacInTheCallersOrganisation(): void {
		$this->calls = [];
		$this->guard()->check(['apTransactionId' => self::OWN, 'administrationId' => 'adm-1'], 'pay', 'ann');
		self::assertSame([['find', self::OWN, false, true]], $this->calls);
	}
}
