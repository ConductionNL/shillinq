<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\OssIossChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the threshold, per-country distribution and quarter branches of the
 * OSS checks.
 *
 * @covers \OCA\Shillinq\Standards\Checks\OssIossChecks
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class OssIossChecksBehaviourTest extends TestCase {

	/**
	 * Objects, the rule they are checked against and the expected verdict.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: bool}>
	 */
	public static function cases(): array {
		$tc = 'OssThresholdCounter';
		$rt = 'OssReturn';
		$rate = 'oss-rate-of-member-state-consumption';
		return [
			'threshold under limit' => [$tc, 'oss-distance-sales-threshold-10000', ['totalB2cEuTurnover' => 10000, 'calendarYear' => '2026'], true],
			'threshold over without breach date' => [$tc, 'oss-distance-sales-threshold-10000', ['totalB2cEuTurnover' => 10000.01, 'calendarYear' => '2026'], false],
			'threshold over with breach date' => [$tc, 'oss-distance-sales-threshold-10000', ['totalB2cEuTurnover' => 12000, 'calendarYear' => '2026', 'thresholdBreachedDate' => '2026-08-01'], true],
			'threshold without year' => [$tc, 'oss-distance-sales-threshold-10000', ['totalB2cEuTurnover' => 100, 'calendarYear' => ' '], false],
			'threshold turnover not numeric' => [$tc, 'oss-distance-sales-threshold-10000', ['totalB2cEuTurnover' => 'n/a', 'calendarYear' => '2026'], false],
			'distribution map' => [$rt, $rate, ['perCountryDistribution' => ['FR' => '20.00']], true],
			'distribution map without numeric amount falls to lines' => [$rt, $rate, ['perCountryDistribution' => ['FR' => 'x'], 'lineItems' => [['countryCode' => 'BE', 'vatAmount' => 21]]], true],
			'line items only' => [$rt, $rate, ['lineItems' => ['skip', ['countryCode' => ' ', 'vatAmount' => 5], ['countryCode' => 'DE', 'vatAmount' => '19']]], true],
			'line items without VAT amount' => [$rt, $rate, ['lineItems' => [['countryCode' => 'DE', 'vatAmount' => 'n/a']]], false],
			'line items not a list' => [$rt, $rate, ['perCountryDistribution' => 'DE', 'lineItems' => 'DE'], false],
			'nothing per country' => [$rt, $rate, [], false],
			'quarter Q4' => [$rt, 'oss-single-quarterly-return', ['periodQuarter' => 'Q4'], true],
			'quarter Q5' => [$rt, 'oss-single-quarterly-return', ['periodQuarter' => 'Q5'], false],
			'quarter lowercase' => [$rt, 'oss-single-quarterly-return', ['periodQuarter' => 'q1'], false],
		];

	}//end cases()

	/**
	 * Each check returns the pinned verdict.
	 *
	 * @param string               $type     The object type.
	 * @param string               $ruleId   The rule id.
	 * @param array<string, mixed> $object   The object checked.
	 * @param bool                 $expected The expected verdict.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testVerdict(string $type, string $ruleId, array $object, bool $expected): void {
		$check = OssIossChecks::checks()[$type][$ruleId];
		$this->assertSame($expected, $check($object, ['jurisdiction' => 'NL']));

	}//end testVerdict()
}//end class
