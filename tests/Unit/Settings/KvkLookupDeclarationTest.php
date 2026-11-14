<?php

/**
 * Unit tests for the KvK lookup declared on the customer and supplier KvK numbers.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contacts-kvk-lookup/tasks.md#1-declarations
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * `CustomerMaster.kvkNumber` and `Payee.kvkNumber` declare the KvK lookup with
 * provider `kvk`, mode `default` and a fill map whose every target is a
 * property its schema declares (contacts-kvk-lookup REQ-KVKL-001, REQ-KVKL-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class KvkLookupDeclarationTest extends TestCase {

	/**
	 * The schemas that carry the lookup, with the fragment each lives in.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMAS = [
		'CustomerMaster' => 'add-shillinq-bookkeeping-compliance.json',
		'Payee' => 'bookkeeping-accounts-payable-core.json',
	];

	/**
	 * The properties each fill map must fill, per schema (design D2).
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FILLED = [
		'CustomerMaster' => ['legalName', 'tradeName'],
		'Payee' => ['name', 'tradingName', 'address.street', 'address.houseNumber', 'address.postcode', 'address.city', 'address.country'],
	];

	/**
	 * One schema's properties, read from its fragment.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function properties(string $schema): array {
		$path = __DIR__ . '/../../../lib/Settings/register.d/' . self::SCHEMAS[$schema];
		$fragment = json_decode((string)file_get_contents($path), true);
		self::assertIsArray($fragment, $path . ' is valid JSON');

		return $fragment['components']['schemas'][$schema]['properties'];
	}//end properties()

	/**
	 * Both KvK numbers declare the lookup with provider kvk and mode default.
	 *
	 * @return void
	 */
	public function testBothKvkNumbersDeclareTheKvkLookupAsADefault(): void {
		foreach (array_keys(self::SCHEMAS) as $schema) {
			$kvk = $this->properties($schema)['kvkNumber'];
			$source = ($kvk['x-openregister-property-source'] ?? null);

			self::assertIsArray($source, $schema . '.kvkNumber declares the lookup');
			self::assertSame('kvk', $source['provider'], $schema);
			// A copy, not a live read: a posted invoice keeps the name it had (design D1).
			self::assertSame('default', $source['mode'], $schema);
			self::assertSame('string', $kvk['type'], $schema . '.kvkNumber stays a plain string');
			self::assertTrue($kvk['nullable'], $schema . '.kvkNumber stays optional');
			self::assertArrayNotHasKey('pattern', $kvk, 'no pattern that would reject stored numbers');
		}
	}//end testBothKvkNumbersDeclareTheKvkLookupAsADefault()

	/**
	 * Every fill target is a property its schema declares, and the map fills
	 * what design D2 names.
	 *
	 * @return void
	 */
	public function testEveryFillTargetIsADeclaredProperty(): void {
		foreach (array_keys(self::SCHEMAS) as $schema) {
			$properties = $this->properties($schema);
			$fill = $properties['kvkNumber']['x-openregister-property-source']['config']['fill'];

			self::assertSame(self::FILLED[$schema], array_keys($fill), $schema . ' fills what the design names');
			foreach (array_keys($fill) as $target) {
				$path = explode('.', $target);
				$node = ['properties' => $properties];
				foreach ($path as $segment) {
					self::assertArrayHasKey($segment, ($node['properties'] ?? []), $schema . ': ' . $target . ' is a declared property');
					$node = $node['properties'][$segment];
				}

				self::assertNotSame('kvkNumber', $target, 'the lookup never fills its own field');
			}
		}
	}//end testEveryFillTargetIsADeclaredProperty()

	/**
	 * The customer fills names only; the supplier fills its address from the
	 * main branch and fixes the country.
	 *
	 * @return void
	 */
	public function testTheFillMapsReadTheKvkRecord(): void {
		$customer = $this->properties('CustomerMaster')['kvkNumber']['x-openregister-property-source']['config']['fill'];
		self::assertSame(['legalName' => 'naam', 'tradeName' => 'handelsnamen[0].naam'], $customer);

		$supplier = $this->properties('Payee')['kvkNumber']['x-openregister-property-source']['config']['fill'];
		self::assertSame('naam', $supplier['name']);
		self::assertSame('_embedded.hoofdvestiging.adressen[0].postcode', $supplier['address.postcode']);
		self::assertSame(
			[
				'_embedded.hoofdvestiging.adressen[0].huisnummer',
				'_embedded.hoofdvestiging.adressen[0].huisletter',
				'_embedded.hoofdvestiging.adressen[0].huisnummerToevoeging',
			],
			$supplier['address.houseNumber']['join']
		);
		self::assertSame(['value' => 'NL'], $supplier['address.country']);
	}//end testTheFillMapsReadTheKvkRecord()
}//end class
