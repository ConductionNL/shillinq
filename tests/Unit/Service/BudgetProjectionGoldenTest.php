<?php

/**
 * Golden test for BudgetProjectionService envelopes over the real calculator.
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
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetProjectionReader;
use OCA\Shillinq\Service\BudgetProjectionService;
use PHPUnit\Framework\TestCase;

/**
 * Pins the full account and group envelopes — actual, gap, projected,
 * beyond-horizon and history-less months — against a recorded golden.
 *
 * Regenerate (only for an intended change) with
 * `BUDGET_PROJECTION_GOLDEN_WRITE=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/BudgetProjectionGoldenTest.php`.
 */
final class BudgetProjectionGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/budget-projection-golden.json';

	/**
	 * Accounts of every type, with steady, flat, short and missing histories.
	 *
	 * @return array<string,mixed>
	 */
	private static function context(): array {
		return [
			'accounts' => [
				'4000' => ['accountNumber' => '4000', 'accountType' => 'expenses'],
				'8000' => ['accountNumber' => '8000', 'accountType' => 'revenue'],
				'1100' => ['accountNumber' => '1100', 'accountType' => 'assets'],
				'4100' => ['accountNumber' => '4100', 'accountType' => 'expenses'],
				'4200' => ['accountNumber' => '4200', 'accountType' => 'expenses'],
			],
			'windowByAccount' => [
				'4000' => ['months' => ['2027-01', '2027-02', '2027-03', '2027-04'], 'values' => [10000, 11000, 12100, 13310]],
				'8000' => ['months' => ['2027-01', '2027-03', '2027-04'], 'values' => [-50000, -52000, -54080]],
				'1100' => ['months' => ['2027-01', '2027-02', '2027-03', '2027-04'], 'values' => [500, 0, -200, 300]],
				'4100' => ['months' => ['2027-04'], 'values' => [700]],
			],
			'lastActualMonthByAccount' => ['4000' => '2027-04', '8000' => '2027-04', '1100' => '2027-04', '4100' => '2027-04'],
			'ledgerGroupEntries' => [
				['id' => 'lg-1', 'slug' => 'kosten', 'memberAccountNumbers' => ['4000', '4100', '4200', '9999']],
			],
			'ledgerGroupKeyToIndex' => ['lg-1' => 0, 'kosten' => 0],
		];

	}//end context()

	/**
	 * Every envelope of the fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function envelopes(): array {
		$reader = $this->createMock(BudgetProjectionReader::class);
		$reader->method('loadContext')->willReturn(self::context());
		$service = new BudgetProjectionService(reader: $reader);

		$months = ['2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06', '2028-04', '2028-05'];
		$envelopes = [];
		foreach (['4000', '8000', '1100', '4100', '4200'] as $account) {
			$envelopes['account:' . $account] = $service->projectAccount('adm-1', $account, $months);
		}

		$envelopes['group:kosten'] = $service->projectGroup('adm-1', 'kosten', $months);

		return $envelopes;

	}//end envelopes()

	/**
	 * The envelopes match the golden byte for byte.
	 *
	 * @return void
	 */
	public function testEnvelopesMatchTheGolden(): void {
		$actual = json_encode($this->envelopes(), JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n";
		if (getenv('BUDGET_PROJECTION_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, $actual);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $actual);

	}//end testEnvelopesMatchTheGolden()
}//end class
