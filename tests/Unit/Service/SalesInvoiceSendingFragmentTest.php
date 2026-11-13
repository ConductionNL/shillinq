<?php

/**
 * Unit tests for the sales-invoice-sending register fragment and the Customers list it feeds.
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
 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-a-customer-carries-a-payment-term-and-a-delivery-method-req-sis-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * The merged CustomerMaster declares a payment term, a delivery method and an
 * invoice address with their defaults, and every Customers column names a
 * property CustomerMaster declares.
 *
 * @coversNothing
 */
final class SalesInvoiceSendingFragmentTest extends TestCase {

	/**
	 * REQ-SIS-005: the three fields, with their defaults, after every fragment merged.
	 *
	 * @return void
	 */
	public function testTheMergedCustomerDeclaresItsTermsWithTheirDefaults(): void {
		$schema = RegisterSchema::schema(slug: 'CustomerMaster');
		$properties = $schema['properties'];

		self::assertSame('0.4.0', $schema['version']);
		self::assertSame(['type' => 'integer', 'minimum' => 0, 'maximum' => 365, 'default' => 30], array_intersect_key($properties['paymentTermDays'], array_flip(['type', 'minimum', 'maximum', 'default'])));
		self::assertSame(['email', 'peppol', 'post', 'none'], $properties['deliveryMethod']['enum']);
		self::assertSame('email', $properties['deliveryMethod']['default']);
		self::assertSame('email', $properties['invoiceEmail']['format']);
		self::assertTrue($properties['invoiceEmail']['nullable']);
		// The fields the earlier fragments added survive the merge.
		self::assertArrayHasKey('externalReference', $properties);
		self::assertArrayHasKey('peppolParticipantId', $properties);
	}//end testTheMergedCustomerDeclaresItsTermsWithTheirDefaults()

	/**
	 * A customer of the spec's example validates; a term out of range or an unknown method does not.
	 *
	 * @return void
	 */
	public function testACustomersTermsAreValidatedOnSave(): void {
		$customer = ['administrationId' => 'adm-1', 'customerId' => 'DEB-0001', 'legalName' => 'Bakkerij De Korenaar B.V.', 'email' => 'info@dekorenaar.example', 'paymentTermDays' => 14, 'deliveryMethod' => 'email', 'invoiceEmail' => 'facturen@dekorenaar.example'];
		self::assertSame([], RegisterSchema::errors(slug: 'CustomerMaster', object: $customer));

		self::assertArrayHasKey('/paymentTermDays', RegisterSchema::errors(slug: 'CustomerMaster', object: ['paymentTermDays' => 400] + $customer));
		self::assertArrayHasKey('/deliveryMethod', RegisterSchema::errors(slug: 'CustomerMaster', object: ['deliveryMethod' => 'fax'] + $customer));
	}//end testACustomersTermsAreValidatedOnSave()

	/**
	 * REQ-SIS-006: every Customers column names a declared property, and the list shows code, name, term, method, credit limit and status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-the-customer-list-shows-fields-the-customer-carries-req-sis-006
	 */
	public function testEveryCustomersColumnIsACustomerProperty(): void {
		$manifest = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/src/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
		$page = array_values(array_filter($manifest['pages'], static fn (array $page): bool => ($page['id'] ?? '') === 'Customers'))[0];
		$keys = array_map(static fn (array $column): string => $column['key'], $page['config']['columns']);

		$declared = array_keys(RegisterSchema::schema(slug: 'CustomerMaster')['properties']);
		self::assertSame([], array_values(array_diff($keys, $declared)));
		self::assertSame(['customerId', 'legalName', 'paymentTermDays', 'deliveryMethod', 'creditLimit', 'lifecycleState'], $keys);
	}//end testEveryCustomersColumnIsACustomerProperty()
}//end class
