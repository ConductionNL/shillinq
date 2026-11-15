<?php

/**
 * Edge-case unit tests for the GLLine backfill migrators.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Migration
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

namespace OCA\Shillinq\Tests\Unit\Service\Migration;

use OCA\Shillinq\Service\Migration\GlLineAdministrationBackfillMigrator;
use OCA\Shillinq\Service\Migration\GlLineFiscalYearBackfillMigrator;
use PHPUnit\Framework\TestCase;

/**
 * Rows that are not rows, blank values and unusable identities are skipped,
 * never stamped.
 *
 * @covers \OCA\Shillinq\Service\Migration\GlLineFiscalYearBackfillMigrator
 * @covers \OCA\Shillinq\Service\Migration\GlLineAdministrationBackfillMigrator
 */
final class GlLineBackfillEdgeCasesTest extends TestCase {

	/**
	 * A transaction's identities are its id, @self id, uuid and number;
	 * blank, null and non-scalar candidates index nothing.
	 *
	 * @return void
	 */
	public function testOnlyUsableIdentitiesAreIndexed(): void {
		$transactions = [
			'not a row',
			[
				'fiscalYearId' => 'fy-2026',
				'administrationId' => 'adm-1',
				'id' => '  ',
				'@self' => ['id' => ['nested']],
				'uuid' => 'uuid-1',
				'transactionNumber' => 42,
			],
			['fiscalYearId' => 'fy-2025', 'administrationId' => 'adm-2', 'id' => 7.5, 'uuid' => null],
		];

		$this->assertSame(
			['uuid-1' => 'fy-2026', '42' => 'fy-2026'],
			(new GlLineFiscalYearBackfillMigrator())->indexFiscalYearsByTransaction($transactions)
		);
		$this->assertSame(
			['uuid-1' => 'adm-1', '42' => 'adm-1'],
			(new GlLineAdministrationBackfillMigrator())->indexAdministrationsByTransaction(array_slice($transactions, 1))
		);
	}//end testOnlyUsableIdentitiesAreIndexed()

	/**
	 * A blank value stamps nothing; an existing value is never overwritten.
	 *
	 * @return void
	 */
	public function testBlankValuesStampNothing(): void {
		$years = new GlLineFiscalYearBackfillMigrator();
		$this->assertSame(['transactionId' => 't1'], $years->stampFiscalYearId(['transactionId' => 't1'], '  '));
		$this->assertSame(['fiscalYearId' => 'fy-1'], $years->stampFiscalYearId(['fiscalYearId' => 'fy-1'], 'fy-2'));
		$this->assertSame(['fiscalYearId' => 'fy-2'], $years->stampFiscalYearId(['fiscalYearId' => ' '], 'fy-2'));

		$administrations = new GlLineAdministrationBackfillMigrator();
		$this->assertSame(['transactionId' => 't1'], $administrations->stampAdministrationId(['transactionId' => 't1'], ''));
		$this->assertSame(['administrationId' => 'adm-1'], $administrations->stampAdministrationId(['administrationId' => 'adm-1'], 'adm-2'));
	}//end testBlankValuesStampNothing()

	/**
	 * Entries that are not rows are neither counted nor classified.
	 *
	 * @return void
	 */
	public function testNonRowsAreNotCounted(): void {
		$migrator = new GlLineFiscalYearBackfillMigrator();
		$lines = [
			'not a row',
			['transactionId' => 't1'],
			null,
			['transactionId' => 'unknown'],
			['transactionId' => 't1', 'fiscalYearId' => 'fy-2026'],
		];

		$result = $migrator->backfillBatch($lines, [['id' => 't1', 'fiscalYearId' => 'fy-2026']]);

		$this->assertSame(3, $result['seen']);
		$this->assertSame(1, $result['stamped']);
		$this->assertSame(1, $result['alreadyStamped']);
		$this->assertSame(1, $result['unresolvable']);
		$this->assertSame([1 => ['transactionId' => 't1', 'fiscalYearId' => 'fy-2026']], $result['lines']);
		$this->assertSame(2, $migrator->countMissingFiscalYearId($lines));
	}//end testNonRowsAreNotCounted()
}//end class
