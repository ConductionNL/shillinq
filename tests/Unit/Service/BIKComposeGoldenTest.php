<?php

/**
 * Golden test for the full incasso-cost record BIKStaffelCalculator composes.
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Shillinq\Service\BIKStaffelCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pins compose() — staffel, rente periods and totals — byte for byte.
 *
 * Regenerate (only for an intended change) with
 * `BIK_COMPOSE_GOLDEN_WRITE=1 vendor/bin/phpunit -c phpunit-unit.xml tests/Unit/Service/BIKComposeGoldenTest.php`.
 */
final class BIKComposeGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/fixtures/bik-compose-golden.json';

	/**
	 * Records for B2B, B2C and government parties, across a rate boundary, with overrides and VAT.
	 *
	 * @return array<string,mixed>
	 */
	private static function records(): array {
		$calc = new BIKStaffelCalculator();
		$records = [];
		$records['b2b-table'] = $calc->compose(
			invoiceId: 'inv-1',
			administrationId: 'adm-1',
			partyType: 'B2B',
			principal: 8400.00,
			effectiveDate: new DateTimeImmutable('2026-05-30'),
			calculatedOn: new DateTimeImmutable('2026-06-21')
		);
		$records['b2c-boundary'] = $calc->compose(
			invoiceId: 'inv-2',
			administrationId: 'adm-1',
			partyType: 'B2C',
			principal: 10000.00,
			effectiveDate: new DateTimeImmutable('2025-12-17'),
			calculatedOn: new DateTimeImmutable('2026-01-16')
		);
		$records['b2c-override-vat'] = $calc->compose(
			invoiceId: 'inv-3',
			administrationId: 'adm-2',
			partyType: 'B2C',
			principal: 820.00,
			effectiveDate: new DateTimeImmutable('2026-05-30'),
			calculatedOn: new DateTimeImmutable('2026-06-30'),
			rateB2B: 0.5,
			rateB2C: 0.07,
			vatOffsettable: false
		);
		$records['government-b2b-override'] = $calc->compose(
			invoiceId: 'inv-4',
			administrationId: 'adm-2',
			partyType: 'GOVERNMENT',
			principal: 250000.00,
			effectiveDate: new DateTimeImmutable('2024-11-01'),
			calculatedOn: new DateTimeImmutable('2026-02-01'),
			rateB2B: 0.12,
			vatPercentage: 0.09
		);
		$records['same-day'] = $calc->compose(
			invoiceId: 'inv-5',
			administrationId: 'adm-1',
			partyType: 'B2B',
			principal: 0.0,
			effectiveDate: new DateTimeImmutable('2026-03-01'),
			calculatedOn: new DateTimeImmutable('2026-03-01')
		);

		return $records;

	}//end records()

	/**
	 * The records match the golden byte for byte.
	 *
	 * @return void
	 */
	public function testRecordsMatchTheGolden(): void {
		$actual = json_encode(self::records(), JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION) . "\n";
		if (getenv('BIK_COMPOSE_GOLDEN_WRITE') === '1') {
			file_put_contents(self::GOLDEN, $actual);
		}

		self::assertSame(file_get_contents(self::GOLDEN), $actual);

	}//end testRecordsMatchTheGolden()

	/**
	 * A negative principal and a calculation date before the start are refused with their messages.
	 *
	 * @return void
	 */
	public function testInvalidAccrualsAreRefused(): void {
		$calc = new BIKStaffelCalculator();
		foreach ([[-1.0, '2026-06-01', 'BIK staffel principal must be non-negative.'], [10.0, '2026-04-01', 'calculatedOn must not be before ingangsdatum.']] as [$principal, $on, $message]) {
			try {
				$calc->compose(
					invoiceId: 'inv-x',
					administrationId: 'adm-1',
					partyType: 'B2B',
					principal: $principal,
					effectiveDate: new DateTimeImmutable('2026-05-01'),
					calculatedOn: new DateTimeImmutable($on)
				);
				self::fail('expected a refusal');
			} catch (InvalidArgumentException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}

	}//end testInvalidAccrualsAreRefused()
}//end class
