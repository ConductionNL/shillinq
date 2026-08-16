<?php

/**
 * Unit tests for the import profiles.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use OCA\Shillinq\Service\Import\ImportProfile\EBoekhoudenProfile;
use OCA\Shillinq\Service\Import\ImportProfile\ExactOnlineProfile;
use OCA\Shillinq\Service\Import\ImportProfile\MoneybirdProfile;
use OCA\Shillinq\Service\Import\ImportProfile\SnelstartProfile;
use OCA\Shillinq\Service\Import\ImportProfile\XafGenericProfile;
use OCA\Shillinq\Service\Import\ImportProfileInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies each profile reports its sourceSystem and supplies CSV column maps (REQ-AIM-003).
 */
final class ImportProfileTest extends TestCase {

	/**
	 * The four package profiles return their sourceSystem and a non-empty open-items map.
	 *
	 * @return void
	 */
	public function testPackageProfilesExposeSourceAndColumnMaps(): void {
		$cases = [
			'e-boekhouden' => new EBoekhoudenProfile(),
			'exact-online' => new ExactOnlineProfile(),
			'moneybird' => new MoneybirdProfile(),
			'snelstart' => new SnelstartProfile(),
		];

		foreach ($cases as $expectedSystem => $profile) {
			self::assertInstanceOf(ImportProfileInterface::class, $profile);
			self::assertSame($expectedSystem, $profile->sourceSystem());

			$openItems = $profile->mapCsvColumns('open-items');
			self::assertNotEmpty($openItems, "$expectedSystem must supply an open-items column map");
			self::assertArrayHasKey('invoiceNumber', $openItems);
			self::assertArrayHasKey('outstandingAmount', $openItems);

			$relations = $profile->mapCsvColumns('relations');
			self::assertNotEmpty($relations, "$expectedSystem must supply a relations column map");
		}
	}//end testPackageProfilesExposeSourceAndColumnMaps()

	/**
	 * The xaf-generic profile is the pass-through baseline (no CSV maps, no quirks).
	 *
	 * @return void
	 */
	public function testXafGenericIsPassThrough(): void {
		$profile = new XafGenericProfile();
		self::assertSame('xaf-generic', $profile->sourceSystem());
		self::assertSame([], $profile->mapCsvColumns('open-items'));

		$parsed = ['ledgerAccounts' => [['code' => '1300', 'name' => 'Debiteuren', 'rgsCode' => 'BVorDebHad', 'type' => 'B']]];
		self::assertSame($parsed, $profile->applyDialectQuirks($parsed));
		self::assertSame($parsed['ledgerAccounts'], $profile->normalizeLedgerAccounts($parsed));
	}//end testXafGenericIsPassThrough()

	/**
	 * Exact strips zero-padding from ledger codes.
	 *
	 * @return void
	 */
	public function testExactStripsZeroPadding(): void {
		$profile = new ExactOnlineProfile();
		$parsed = ['ledgerAccounts' => [['code' => '0001300', 'name' => 'Debiteuren', 'rgsCode' => '', 'type' => 'B']]];
		$normalised = $profile->normalizeLedgerAccounts($parsed);
		self::assertSame('1300', $normalised[0]['code']);
	}//end testExactStripsZeroPadding()

	/**
	 * SnelStart strips a leading rubriek letter from ledger codes.
	 *
	 * @return void
	 */
	public function testSnelstartStripsRubriekLetter(): void {
		$profile = new SnelstartProfile();
		$parsed = ['ledgerAccounts' => [['code' => 'G1300', 'name' => 'Debiteuren', 'rgsCode' => '', 'type' => 'B']]];
		$normalised = $profile->normalizeLedgerAccounts($parsed);
		self::assertSame('1300', $normalised[0]['code']);
	}//end testSnelstartStripsRubriekLetter()

}//end class
