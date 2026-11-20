<?php

/**
 * LegislationPackSeeder tests (ledger-foreign-legislation)
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Jurisdiction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Jurisdiction;

use OCA\Shillinq\Service\Jurisdiction\LegislationPackSeeder;
use OCA\Shillinq\Service\Seed\SeedImport;
use OCA\Shillinq\Service\Seed\SeedImporter;
use OCA\Shillinq\Service\Seed\SeedMessages;
use PHPUnit\Framework\TestCase;

/**
 * The Belgian pack as shipped, and seeding an administration from it.
 *
 * @covers \OCA\Shillinq\Service\Jurisdiction\LegislationPackSeeder
 * @uses   \OCA\Shillinq\Service\Seed\SeedImport
 * @uses   \OCA\Shillinq\Service\Seed\SeedMessages
 *
 * @spec openspec/changes/ledger-foreign-legislation/specs/bookkeeping-multi-administratie/spec.md
 */
final class LegislationPackSeederTest extends TestCase {

	/**
	 * The imports the seeder asked for, as [method, SeedImport].
	 *
	 * @var list<array{0: string, 1: SeedImport}>
	 */
	private array $imports = [];

	/**
	 * A seeder whose importer reads the real seed files and records the imports.
	 *
	 * @param array<string, string> $files Seed files to answer instead of the real ones, by name.
	 *
	 * @return LegislationPackSeeder
	 */
	private function seeder(array $files=[]): LegislationPackSeeder {
		$importer = $this->createMock(SeedImporter::class);
		$importer->method('read')->willReturnCallback(
			static function (string $fileName) use ($files): array {
				$content = ($files[$fileName] ?? @file_get_contents(SeedImporter::SEED_DIR . $fileName));
				if ($content === false) {
					return ['error' => ['success' => false, 'message' => 'missing ' . $fileName]];
				}

				return ['data' => json_decode($content, true)];
			}
		);
		foreach (['import', 'importAsSystem'] as $method) {
			$importer->method($method)->willReturnCallback(
				function (SeedImport $import, SeedMessages $messages) use ($method): array {
					$this->imports[] = [$method, $import];
					return ['success' => true, 'message' => $messages->seededMessage, 'seeded' => count($import->rows), 'skipped' => 0];
				}
			);
		}

		return new LegislationPackSeeder(importer: $importer);
	}//end seeder()

	/**
	 * The wizard's template value names a pack's country; the RGS variants name none.
	 *
	 * @return void
	 */
	public function testATemplateValueNamesItsCountry(): void {
		$this->assertSame('BE', LegislationPackSeeder::countryOfTemplate(template: 'legislation-be'));
		$this->assertNull(LegislationPackSeeder::countryOfTemplate(template: 'mkb'));
		$this->assertNull(LegislationPackSeeder::countryOfTemplate(template: 'legislation-bel'));
		$this->assertNull(LegislationPackSeeder::countryOfTemplate(template: 'legislation-'));
	}//end testATemplateValueNamesItsCountry()

	/**
	 * The shipped Belgian pack: classes 1 to 7, the named accounts, rates 0, 6, 12 and 21 percent,
	 * and the Dutch outputs it disables.
	 *
	 * @return void
	 */
	public function testTheBelgianPackHoldsTheMinimumChartAndRates(): void {
		$pack = $this->seeder()->pack(country: 'be')['pack'];
		$this->assertSame('BE', $pack['country']);
		$this->assertSame(['dutch-vat-return', 'sbr', 'icp'], $pack['disables']);

		$chart = json_decode((string)file_get_contents(SeedImporter::SEED_DIR . 'legislation/be/' . $pack['chart']), true);
		$names = array_column($chart['accounts'], 'name', 'accountNumber');
		$this->assertSame('Handelsdebiteuren', $names['400000']);
		$this->assertSame('Leveranciers', $names['440000']);
		$this->assertSame('Te betalen btw', $names['451000']);
		$this->assertSame('Omzet', $names['700000']);
		$classes = array_values(array_unique(array_map(static fn (string $number): string => $number[0], array_keys($names))));
		$this->assertSame(['1', '2', '3', '4', '5', '6', '7'], $classes);
		foreach ($chart['accounts'] as $account) {
			$this->assertMatchesRegularExpression('/^\d{6}$/', $account['accountNumber']);
			$this->assertContains($account['accountType'], ['assets', 'liabilities', 'equity', 'revenue', 'expenses']);
			$this->assertSame('template', $account['administrationId']);
			if (isset($account['parentAccountNumber']) === true) {
				$this->assertArrayHasKey($account['parentAccountNumber'], $names);
			}
		}

		$rates = json_decode((string)file_get_contents(SeedImporter::SEED_DIR . 'legislation/be/' . $pack['vatRates']), true);
		$this->assertSame([21, 12, 6, 0], array_column($rates['tariffs'], 'ratePercentage'));
	}//end testTheBelgianPackHoldsTheMinimumChartAndRates()

	/**
	 * Seeding a Belgian administration imports the chart under it and the rates tagged BE.
	 *
	 * @return void
	 */
	public function testABelgianAdministrationIsSeededFromThePack(): void {
		$result = $this->seeder()->seed(country: 'BE', administrationId: 'VDS');

		$this->assertTrue($result['success']);
		$this->assertSame('BE', $result['country']);
		$this->assertCount(2, $this->imports);

		[$method, $chart] = $this->imports[0];
		$this->assertSame('import', $method);
		$this->assertSame('Account', $chart->schema);
		$this->assertSame('VDS', $chart->administrationId);
		$this->assertSame(['accountNumber' => '400000', 'administrationId' => 'VDS'], ($chart->match)(['accountNumber' => '400000']));

		[$method, $rates] = $this->imports[1];
		$this->assertSame('importAsSystem', $method);
		$this->assertSame('VatTariff', $rates->schema);
		$this->assertSame(['BE'], array_values(array_unique(array_column($rates->rows, 'jurisdiction'))));
		$this->assertSame(['code' => 'be-standard'], ($rates->match)($rates->rows[0]));
	}//end testABelgianAdministrationIsSeededFromThePack()

	/**
	 * No administration, no pack, a malformed pack or an empty file: nothing is imported.
	 *
	 * @return void
	 */
	public function testNothingIsImportedWithoutAUsablePack(): void {
		$this->assertSame('administrationId must not be empty.', $this->seeder()->seed(country: 'BE', administrationId: '')['message']);
		$this->assertSame('No legislation pack for FR.', $this->seeder()->seed(country: 'FR', administrationId: 'X')['message']);
		$this->assertSame('Not a country code: Belgium', $this->seeder()->seed(country: 'Belgium', administrationId: 'X')['message']);

		$malformed = $this->seeder(files: ['legislation/de/pack.json' => '{"country": "AT", "chart": "chart.json", "vatRates": "vat-rates.json"}']);
		$this->assertSame('The legislation pack for DE is malformed.', $malformed->seed(country: 'DE', administrationId: 'X')['message']);

		$escaping = $this->seeder(
			files: ['legislation/de/pack.json' => '{"country": "DE", "chart": "../../rgs-3.5-mkb.json", "vatRates": "vat-rates.json"}']
		);
		$this->assertSame('The DE legislation pack names an invalid file.', $escaping->seed(country: 'DE', administrationId: 'X')['message']);

		$empty = $this->seeder(
			files: [
				'legislation/de/pack.json' => '{"country": "DE", "chart": "chart.json", "vatRates": "vat-rates.json"}',
				'legislation/de/chart.json' => '{"accounts": []}',
			]
		);
		$this->assertSame('The DE legislation pack file chart.json has no accounts.', $empty->seed(country: 'DE', administrationId: 'X')['message']);
		$this->assertSame([], $this->imports);
	}//end testNothingIsImportedWithoutAUsablePack()
}//end class
