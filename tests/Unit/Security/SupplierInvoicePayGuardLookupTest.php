<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Security;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Lifecycle\SupplierInvoicePayGuard;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The pay guard accepts only the invoice's own paid AP transaction: the
 * object found must be an APTransaction of this register, in the invoice's
 * administration, with the invoice's number, supplier and total.
 *
 * @covers \OCA\Shillinq\Lifecycle\SupplierInvoicePayGuard
 */
final class SupplierInvoicePayGuardLookupTest extends TestCase {

	private const TX = '11111111-1111-4111-8111-111111111111';

	private const INVOICE = [
		'apTransactionId' => self::TX,
		'administrationId' => 'adm-1',
		'invoiceNumber' => 'INK-7',
		'supplierId' => 'payee-1',
		'totalInclVat' => 12100,
	];

	private const PAID = [
		'state' => 'paid',
		'administrationId' => 'adm-1',
		'invoiceNumber' => 'INK-7',
		'vendorId' => 'payee-1',
		'totalAmount' => 121.0,
	];

	/**
	 * The guard over a find() that answers with the given entity, or throws.
	 *
	 * @param ObjectEntity|null $entity   What find() returns; null throws DoesNotExist.
	 * @param string            $failing  'container' or 'settings' to make that lookup throw.
	 */
	private function guard(?ObjectEntity $entity, string $failing = ''): SupplierInvoicePayGuard {
		$objectService = new class($entity) {
			public function __construct(private ?ObjectEntity $entity) {
			}
			public function find(string $id, ...$named): ObjectEntity {
				if ($this->entity === null) {
					throw new RuntimeException('DoesNotExist');
				}
				return $this->entity;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		if ($failing === 'container') {
			$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		} else {
			$container->method('get')->willReturn($objectService);
		}
		$settings = $this->createMock(SettingsService::class);
		if ($failing === 'settings') {
			$settings->method('getRegisterSlug')->willThrowException(new \LogicException('config down'));
		} else {
			$settings->method('getRegisterSlug')->willReturn('shillinq');
		}
		$schemas = $this->createMock(ListenerSchemaResolver::class);
		$schemas->method('matchesRegisterAndSchema')->willReturnCallback(
			static fn (?object $e, string $register, string $schema): bool => $e->getRegister() === $register && $e->getSchema() === $schema
		);
		return new SupplierInvoicePayGuard($container, $settings, $this->createMock(LoggerInterface::class), $schemas);
	}

	/**
	 * An entity in the given register and schema with the given data.
	 *
	 * @param array<string, mixed> $data     The object.
	 * @param string               $schema   The schema slug.
	 * @param string               $register The register slug.
	 */
	private static function entity(array $data, string $schema = 'APTransaction', string $register = 'shillinq'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::TX);
		$entity->setRegister($register);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}

	private function allowed(?ObjectEntity $entity, array $invoice = self::INVOICE, string $failing = ''): bool {
		return $this->guard($entity, $failing)->check($invoice, 'pay', 'ann')->isAllowed();
	}

	public function testTheInvoicesOwnPaidTransactionAllowsPay(): void {
		self::assertTrue($this->allowed(self::entity(self::PAID)));
	}

	public function testAnUnpaidTransactionRefusesPay(): void {
		self::assertFalse($this->allowed(self::entity(['state' => 'issued'] + self::PAID)));
	}

	public function testAnObjectOfAnotherSchemaOrRegisterRefusesPay(): void {
		self::assertFalse($this->allowed(self::entity(self::PAID, 'Invoice')));
		self::assertFalse($this->allowed(self::entity(self::PAID, 'APTransaction', 'otherapp')));
	}

	public function testATransactionOfAnotherInvoiceRefusesPay(): void {
		self::assertFalse($this->allowed(self::entity(['invoiceNumber' => 'INK-8'] + self::PAID)));
		self::assertFalse($this->allowed(self::entity(['vendorId' => 'payee-2'] + self::PAID)));
		self::assertFalse($this->allowed(self::entity(['totalAmount' => 120.99] + self::PAID)));
		self::assertFalse($this->allowed(self::entity(['administrationId' => 'adm-2'] + self::PAID)));
	}

	public function testABlankAdministrationRefusesPay(): void {
		self::assertFalse($this->allowed(self::entity(['administrationId' => ''] + self::PAID), ['administrationId' => ''] + self::INVOICE));
		self::assertFalse($this->allowed(self::entity(['administrationId' => ''] + self::PAID), ['administrationId' => '  '] + self::INVOICE));
	}

	public function testAMissingTransactionRefusesPay(): void {
		self::assertFalse($this->allowed(null));
		self::assertFalse($this->allowed(self::entity(self::PAID), ['apTransactionId' => ''] + self::INVOICE));
	}

	public function testAFailingLookupRefusesPay(): void {
		self::assertFalse($this->allowed(self::entity(self::PAID), self::INVOICE, 'container'));
		self::assertFalse($this->allowed(self::entity(self::PAID), self::INVOICE, 'settings'));
	}
}
