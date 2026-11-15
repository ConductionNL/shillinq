<?php

/**
 * Golden test for ReinvestmentReserves::releaseExpired.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Asset
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Asset;

use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Asset\ReinvestmentReserves;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Pins the released count, every posted journal and every patch, in order.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ReinvestmentReserveReleaseGoldenTest extends TestCase {

	/**
	 * Recorded calls.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Every scenario matches the golden fixture.
	 *
	 * @return void
	 */
	public function testReleaseMatchesGolden(): void {
		$reserves = [
			[
				'id' => 'r-1',
				'administrationId' => 'adm-1',
				'amount' => 1000.50,
				'appliedAmount' => 200.25,
				'expiresOn' => '2026-12-31',
				'disposedAssetNumber' => 'FA-1',
			],
			['id' => 'r-2', 'amount' => 500, 'appliedAmount' => 500, 'expiresOn' => '2026-06-30'],
			['id' => 'r-3', 'amount' => 100, 'expiresOn' => '2027-01-01', 'reserveAccountNumber' => '0641'],
			['id' => 'r-4', 'amount' => 100, 'appliedAmount' => 150, 'expiresOn' => '2025-01-01'],
			['id' => 'r-5', 'amount' => '75.10', 'reserveAccountNumber' => '0642', 'expiresOn' => '2026-12-31', 'administrationId' => 'adm-2'],
			['id' => 'r-6', 'amount' => 10],
		];
		$result = [];
		$configs = [
			'configured' => [
				'shillinq' => [
					'fixed_asset_reinvestment_release_account' => ' 8100 ',
					'fixed_asset_disposal_gain_account' => '8200',
					'fixed_asset_reinvestment_reserve_account' => '0650',
				],
			],
			'fallbackGain' => ['shillinq' => ['fixed_asset_disposal_gain_account' => '8200']],
			'defaults' => [],
		];
		foreach ($configs as $name => $config) {
			foreach (['2027-01-01', '2026-12-31'] as $today) {
				$this->calls = [];
				$count = $this->reserves(rows: $reserves, config: $config)->releaseExpired($today);
				$result[$name][$today] = ['released' => $count, 'calls' => $this->calls];
			}
		}

		$file = __DIR__.'/fixtures/reinvestment-reserve-release.golden.json';
		$json = json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
		if (getenv('RESERVE_RELEASE_GOLDEN_WRITE') === '1') {
			file_put_contents($file, $json."\n");
		}

		$this->assertSame(file_get_contents($file), $json."\n");

	}//end testReleaseMatchesGolden()

	/**
	 * The service over recording records and a keyed app config.
	 *
	 * @param list<array<string, mixed>> $rows   The open reserves.
	 * @param array                      $config App => key => value.
	 *
	 * @return ReinvestmentReserves
	 */
	private function reserves(array $rows, array $config): ReinvestmentReserves {
		$records = $this->createMock(AssetRecords::class);
		$records->method('records')->willReturnCallback(
			function (string $schema, array $filters) use ($rows) {
				$this->calls[] = ['records', $schema, $filters];
				return $rows;
			}
		);
		$records->method('postJournal')->willReturnCallback(
			function (array $journal) {
				$this->calls[] = ['postJournal', $journal];
				return 'je-'.count($this->calls);
			}
		);
		$records->method('patch')->willReturnCallback(
			function (string $schema, string $id, array $fields) {
				$this->calls[] = ['patch', $schema, $id, $fields];
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($config[$app][$key] ?? $default)
		);
		return new ReinvestmentReserves(records: $records, appConfig: $appConfig);

	}//end reserves()
}//end class
