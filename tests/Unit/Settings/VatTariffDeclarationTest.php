<?php

/**
 * The VatTariff schema is declared once, in its register.d fragment, and the
 * seeded tariffs are marked statutory (tax-vat-rates-and-deductibility
 * REQ-TVRD-001, tasks 1.1 and 1.2).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * One VatTariff declaration, with every field the seed and the posting path use.
 *
 * @coversNothing
 */
final class VatTariffDeclarationTest extends TestCase {
	/**
	 * Read a JSON file of the repository.
	 *
	 * @param string $path Path from the repository root.
	 *
	 * @return array<string,mixed>
	 */
	private static function json(string $path): array {
		$data = json_decode((string)file_get_contents(__DIR__.'/../../../'.$path), true);
		self::assertIsArray($data, $path.' must be valid JSON');

		return $data;
	}//end json()

	public function testVatTariffIsDeclaredOnceInItsFragment(): void {
		$monolith = self::json(path: 'lib/Settings/shillinq_register.json');
		self::assertArrayNotHasKey('VatTariff', $monolith['components']['schemas']);

		$declaring = [];
		foreach (glob(__DIR__.'/../../../lib/Settings/register.d/*.json') as $file) {
			$schema = (json_decode((string)file_get_contents($file), true)['components']['schemas']['VatTariff'] ?? null);
			if (is_array($schema) === true && isset($schema['properties']) === true) {
				$declaring[] = basename($file);
			}
		}

		self::assertSame(['add-shillinq-bookkeeping-operations.json'], $declaring);
	}//end testVatTariffIsDeclaredOnceInItsFragment()

	public function testTheDeclarationKeepsEveryFieldAndRuleOfBothFormerCopies(): void {
		$schema = self::json(path: 'lib/Settings/register.d/add-shillinq-bookkeeping-operations.json')['components']['schemas']['VatTariff'];

		self::assertSame(
			['category', 'code', 'defaultAccounts', 'description', 'effectiveFrom', 'effectiveTo', 'label', 'legalBasis', 'rate', 'ratePercentage', 'reverseCharge', 'rgsAccountHint', 'section', 'statutory'],
			self::sorted(values: array_keys($schema['properties']))
		);
		self::assertSame(['code', 'rate', 'description', 'category', 'effectiveFrom', 'ratePercentage'], $schema['required']);
		self::assertSame(['shillinq_vat_administrator'], $schema['authorization']['update']);
		self::assertTrue($schema['properties']['statutory']['readOnly']);
		self::assertFalse($schema['properties']['statutory']['default']);
	}//end testTheDeclarationKeepsEveryFieldAndRuleOfBothFormerCopies()

	public function testEverySeededTariffIsStatutoryAndCarriesWhatTheSchemaRequires(): void {
		$schema = self::json(path: 'lib/Settings/register.d/add-shillinq-bookkeeping-operations.json')['components']['schemas']['VatTariff'];
		$tariffs = self::json(path: 'lib/Settings/seeds/btw-tariffs-2026.json')['tariffs'];

		self::assertSame(['high', 'low', 'zero', 'exempt', 'reverse-charge'], array_slice(array_column($tariffs, 'code'), 0, 5));
		foreach ($tariffs as $tariff) {
			self::assertTrue($tariff['statutory'], $tariff['code'].' must be statutory');
			foreach ($schema['required'] as $field) {
				self::assertArrayHasKey($field, $tariff, $tariff['code'].' lacks required '.$field);
			}
		}
	}//end testEverySeededTariffIsStatutoryAndCarriesWhatTheSchemaRequires()

	/**
	 * Values sorted.
	 *
	 * @param list<string> $values The values.
	 *
	 * @return list<string>
	 */
	private static function sorted(array $values): array {
		sort($values);

		return $values;
	}//end sorted()
}//end class
