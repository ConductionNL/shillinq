<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The dialect edges of the package import profiles.
 *
 * ImportProfileTest checks that each profile names its system and has column
 * maps. This pins the edges: an Exact ledger code that is all zeros keeps one
 * zero instead of becoming empty, an empty code is left alone, and Exact's
 * dialect pass normalises the ledger in place; e-Boekhouden and Moneybird
 * pass the parser's output through untouched; every profile maps the relation
 * columns of its own export, and an artifact it does not know maps to nothing.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/administration-import-migration/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Import;

use OCA\Shillinq\Service\Import\ImportProfile\EBoekhoudenProfile;
use OCA\Shillinq\Service\Import\ImportProfile\ExactOnlineProfile;
use OCA\Shillinq\Service\Import\ImportProfile\MoneybirdProfile;
use OCA\Shillinq\Service\Import\ImportProfile\SnelstartProfile;
use PHPUnit\Framework\TestCase;

/**
 * Dialect edges of the package profiles.
 *
 * @covers \OCA\Shillinq\Service\Import\ImportProfile\EBoekhoudenProfile
 * @covers \OCA\Shillinq\Service\Import\ImportProfile\ExactOnlineProfile
 * @covers \OCA\Shillinq\Service\Import\ImportProfile\MoneybirdProfile
 * @covers \OCA\Shillinq\Service\Import\ImportProfile\SnelstartProfile
 */
final class ImportProfileDialectsTest extends TestCase {

	/**
	 * An all-zero Exact code keeps one zero; an empty or missing code is left alone.
	 *
	 * @return void
	 */
	public function testExactKeepsOneZeroAndLeavesEmptyCodes(): void {
		$parsed = [
			'ledgerAccounts' => [
				['code' => '0000', 'name' => 'Kas'],
				['code' => '', 'name' => 'Leeg'],
				['name' => 'Geen code'],
				['code' => '0080', 'name' => 'Bank'],
			],
		];

		$normalised = (new ExactOnlineProfile())->normalizeLedgerAccounts($parsed);

		self::assertSame(['0', '', null, '80'], array_map(static fn (array $a): ?string => $a['code'] ?? null, $normalised));
		self::assertSame('Kas', $normalised[0]['name']);
		self::assertSame([], (new ExactOnlineProfile())->normalizeLedgerAccounts([]));
	}//end testExactKeepsOneZeroAndLeavesEmptyCodes()

	/**
	 * Exact's dialect pass normalises the ledger and keeps the rest of the parse.
	 *
	 * @return void
	 */
	public function testExactDialectPassNormalisesTheLedgerInPlace(): void {
		$parsed = ['ledgerAccounts' => [['code' => '001300']], 'transactions' => [['id' => 't1']]];

		$quirked = (new ExactOnlineProfile())->applyDialectQuirks($parsed);

		self::assertSame([['code' => '1300']], $quirked['ledgerAccounts']);
		self::assertSame([['id' => 't1']], $quirked['transactions']);
	}//end testExactDialectPassNormalisesTheLedgerInPlace()

	/**
	 * e-Boekhouden and Moneybird pass the parser's output through untouched.
	 *
	 * @return void
	 */
	public function testEBoekhoudenAndMoneybirdPassThrough(): void {
		$parsed = ['ledgerAccounts' => [['code' => '0001300', 'name' => 'Debiteuren']], 'transactions' => []];

		foreach ([new EBoekhoudenProfile(), new MoneybirdProfile()] as $profile) {
			self::assertSame($parsed['ledgerAccounts'], $profile->normalizeLedgerAccounts($parsed), $profile->sourceSystem());
			self::assertSame([], $profile->normalizeLedgerAccounts([]), $profile->sourceSystem());
			self::assertSame($parsed, $profile->applyDialectQuirks($parsed), $profile->sourceSystem());
		}
	}//end testEBoekhoudenAndMoneybirdPassThrough()

	/**
	 * Each profile maps the relation columns of its own export.
	 *
	 * @return void
	 */
	public function testRelationColumnsFollowEachExport(): void {
		$expected = [
			'e-boekhouden' => [new EBoekhoudenProfile(), ['name' => 'Bedrijf', 'kvk' => 'KvK-nummer', 'vat' => 'BTW-nummer']],
			'exact-online' => [new ExactOnlineProfile(), ['name' => 'Name', 'kvk' => 'ChamberOfCommerce', 'vat' => 'VATNumber']],
			'moneybird' => [new MoneybirdProfile(), ['name' => 'CompanyName', 'kvk' => 'ChamberOfCommerceID', 'vat' => 'TaxNumber']],
			'snelstart' => [new SnelstartProfile(), ['kvk' => 'KvKnummer', 'vat' => 'BTWnummer']],
		];

		foreach ($expected as $system => [$profile, $columns]) {
			$relations = $profile->mapCsvColumns('relations');
			self::assertSame(['code', 'name', 'kvk', 'vat', 'email', 'phone'], array_keys($relations), $system);
			foreach ($columns as $field => $column) {
				self::assertSame($column, $relations[$field], $system . ' ' . $field);
			}
		}
	}//end testRelationColumnsFollowEachExport()

	/**
	 * An artifact a profile does not know maps to no columns.
	 *
	 * @return void
	 */
	public function testAnUnknownArtifactMapsToNothing(): void {
		foreach ([new EBoekhoudenProfile(), new ExactOnlineProfile(), new MoneybirdProfile(), new SnelstartProfile()] as $profile) {
			self::assertSame([], $profile->mapCsvColumns('ledger'), $profile->sourceSystem());
		}
	}//end testAnUnknownArtifactMapsToNothing()
}//end class
