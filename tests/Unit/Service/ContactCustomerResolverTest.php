<?php

/**
 * ContactCustomerResolver unit tests: from a contact reference to its CustomerMaster.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/sales-cancellation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;


use OCA\Shillinq\Service\ContactCustomerResolver;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Contacts\IManager as IContactsManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A contact resolves to one customer, or to none.
 */
#[CoversClass(ContactCustomerResolver::class)]
#[UsesClass(ObjectIdentifier::class)]
class ContactCustomerResolverTest extends TestCase {

	/**
	 * Customers of the administration.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const CUSTOMERS = [
		['id' => 'cm-1', 'name' => 'Acme', 'externalReference' => 'contact:acme', 'email' => 'billing@acme.example'],
		['id' => 'cm-2', 'name' => 'Linde', 'email' => 'Info@Linde.example'],
		['id' => 'cm-3', 'name' => 'Twin A', 'email' => 'twin@example.org'],
		['id' => 'cm-4', 'name' => 'Twin B', 'email' => 'twin@example.org'],
	];

	/**
	 * A resolver over contacts keyed by UID.
	 *
	 * @param array<string, mixed> $emails E-mail (string or list) by contact UID.
	 *
	 * @return ContactCustomerResolver
	 */
	private function resolver(array $emails): ContactCustomerResolver {
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('search')->willReturnCallback(
			static function (string $pattern, array $properties) use ($emails): array {
				if ($properties !== ['UID'] || array_key_exists($pattern, $emails) === false) {
					return [];
				}

				return [['UID' => $pattern, 'EMAIL' => $emails[$pattern]]];
			}
		);

		return new ContactCustomerResolver(contacts: $contacts);
	}//end resolver()

	/**
	 * A customer carrying the contact reference wins, without a contact lookup.
	 *
	 * @return void
	 */
	public function testTheExternalReferenceWins(): void {
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->expects($this->never())->method('search');

		$this->assertSame('cm-1', (new ContactCustomerResolver(contacts: $contacts))->resolve(reference: ' contact:acme ', customers: self::CUSTOMERS));
	}//end testTheExternalReferenceWins()

	/**
	 * Otherwise the contact's e-mail address finds the customer, ignoring case.
	 *
	 * @return void
	 */
	public function testTheContactsEmailFindsTheCustomer(): void {
		$resolver = $this->resolver(['linde' => 'info@linde.EXAMPLE', 'linde-2' => ['INFO@linde.example', 'other@x.example']]);

		$this->assertSame('cm-2', $resolver->resolve(reference: 'contact:linde', customers: self::CUSTOMERS));
		$this->assertSame('cm-2', $resolver->resolve(reference: 'linde-2', customers: self::CUSTOMERS));
	}//end testTheContactsEmailFindsTheCustomer()

	/**
	 * Two customers with the address, an unknown contact, no address or no reference resolve to nothing.
	 *
	 * @return void
	 */
	public function testAnythingButOneMatchResolvesToNothing(): void {
		$resolver = $this->resolver(['twin' => 'twin@example.org', 'mute' => '', 'stranger' => 'nobody@example.org']);

		$this->assertNull($resolver->resolve(reference: 'contact:twin', customers: self::CUSTOMERS));
		$this->assertNull($resolver->resolve(reference: 'contact:mute', customers: self::CUSTOMERS));
		$this->assertNull($resolver->resolve(reference: 'contact:stranger', customers: self::CUSTOMERS));
		$this->assertNull($resolver->resolve(reference: 'contact:unknown', customers: self::CUSTOMERS));
		$this->assertNull($resolver->resolve(reference: '  ', customers: self::CUSTOMERS));
	}//end testAnythingButOneMatchResolvesToNothing()

	/**
	 * A failing contacts lookup resolves to nothing rather than failing the caller.
	 *
	 * @return void
	 */
	public function testAFailingContactsLookupResolvesToNothing(): void {
		$contacts = $this->createMock(IContactsManager::class);
		$contacts->method('search')->willThrowException(new RuntimeException('address book down'));

		$this->assertNull((new ContactCustomerResolver(contacts: $contacts))->resolve(reference: 'contact:linde', customers: self::CUSTOMERS));
	}//end testAFailingContactsLookupResolvesToNothing()
}//end class
