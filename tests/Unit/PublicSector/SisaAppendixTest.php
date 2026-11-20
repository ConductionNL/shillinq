<?php

/**
 * REQ-SISA-012 / REQ-SISA-013: the SiSa appendix of a year, summed from the
 * posted ledger lines tagged with each specific grant, with the output
 * indicators as entered, and its CSV export.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PublicSector;

use InvalidArgumentException;
use OCA\Shillinq\Service\LedgerActuals;
use OCA\Shillinq\Service\PublicSector\SisaAppendix;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests for SisaAppendix.
 *
 * @covers \OCA\Shillinq\Service\PublicSector\SisaAppendix
 * @uses   \OCA\Shillinq\Service\LedgerActuals
 * @uses   \OCA\Shillinq\Support\RegisterSlug
 */
final class SisaAppendixTest extends TestCase {

	/**
	 * The in-memory store.
	 *
	 * @var InMemoryObjectService
	 */
	private InMemoryObjectService $store;

	/**
	 * Seed a municipality with one specific grant (D8) and the ledger around it.
	 *
	 * @return SisaAppendix
	 */
	private function appendix(): SisaAppendix {
		$this->store = new InMemoryObjectService();
		$this->store->seed(
			'Subsidie',
			[
				['id' => 'grant-d8', 'administrationId' => 'gem-1', 'direction' => 'incoming', 'subsidyNumber' => 'SUB-2026-D8', 'schemeName' => 'Volkshuisvesting', 'sisaRegulationCode' => ' D8 '],
				['id' => 'grant-plain', 'administrationId' => 'gem-1', 'direction' => 'incoming', 'subsidyNumber' => 'SUB-2026-2', 'sisaRegulationCode' => ''],
				['id' => 'grant-given', 'administrationId' => 'gem-1', 'direction' => 'outgoing', 'subsidyNumber' => 'SUB-2026-3', 'sisaRegulationCode' => 'D9'],
				['id' => 'grant-other', 'administrationId' => 'gem-2', 'direction' => 'incoming', 'subsidyNumber' => 'SUB-2026-4', 'sisaRegulationCode' => 'D8'],
			]
		);
		$this->store->seed(
			'GLTransaction',
			[
				['id' => 'tx-1', 'administrationId' => 'gem-1', 'state' => 'posted', 'postingDate' => '2026-03-31'],
				['id' => 'tx-2', 'administrationId' => 'gem-1', 'state' => 'posted', 'postingDate' => '2026-11-02T10:00:00+00:00'],
				['id' => 'tx-2025', 'administrationId' => 'gem-1', 'state' => 'posted', 'postingDate' => '2025-12-31'],
				['id' => 'tx-draft', 'administrationId' => 'gem-1', 'state' => 'draft', 'postingDate' => '2026-05-01'],
			]
		);
		$this->store->seed(
			'GLLine',
			[
				['transactionId' => 'tx-1', 'subsidieId' => 'grant-d8', 'accountClass' => 'pnl', 'side' => 'debit', 'amount' => 100000.00],
				['transactionId' => 'tx-1', 'subsidieId' => 'grant-d8', 'accountClass' => 'balance', 'side' => 'credit', 'amount' => 100000.00],
				['transactionId' => 'tx-2', 'subsidieId' => 'grant-d8', 'accountClass' => 'pnl', 'side' => 'debit', 'amount' => 20000.00],
				['transactionId' => 'tx-2', 'subsidieId' => 'grant-d8', 'accountClass' => 'pnl', 'side' => 'credit', 'amount' => 95000.50],
				['transactionId' => 'tx-2025', 'subsidieId' => 'grant-d8', 'accountClass' => 'pnl', 'side' => 'debit', 'amount' => 5000.00],
				['transactionId' => 'tx-draft', 'subsidieId' => 'grant-d8', 'accountClass' => 'pnl', 'side' => 'debit', 'amount' => 7000.00],
				['transactionId' => 'tx-1', 'subsidieId' => 'grant-plain', 'accountClass' => 'pnl', 'side' => 'debit', 'amount' => 3000.00],
			]
		);
		$this->store->seed(
			'SisaIndicator',
			[
				['administrationId' => 'gem-1', 'grantId' => 'grant-d8', 'fiscalYear' => 2026, 'kind' => 'spending', 'indicatorCode' => 'D8/01', 'description' => 'Besteding (jaar T)'],
				['administrationId' => 'gem-1', 'grantId' => 'grant-d8', 'fiscalYear' => 2026, 'kind' => 'output', 'indicatorCode' => 'D8/03', 'description' => 'Aantal woningen', 'value' => 42, 'unit' => 'aantal'],
				['administrationId' => 'gem-1', 'grantId' => 'grant-d8', 'fiscalYear' => 2026, 'kind' => 'output', 'indicatorCode' => 'D8/04', 'description' => 'Aantal locaties', 'value' => null, 'unit' => 'aantal'],
				['administrationId' => 'gem-1', 'grantId' => 'grant-d8', 'fiscalYear' => 2025, 'kind' => 'output', 'indicatorCode' => 'D8/03', 'value' => 10],
			]
		);

		$config = $this->createStub(IAppConfig::class);
		$config->method('getValueString')->willReturn('shillinq');

		return new SisaAppendix(appConfig: $config, objectService: new DuckObjectServiceAdapter($this->store), ledger: new LedgerActuals());

	}//end appendix()

	/**
	 * The scenario: D8 with EUR 120,000 of posted spending in 2026 reads EUR
	 * 120,000, and the output indicator without a value is marked missing.
	 *
	 * @return void
	 */
	public function testTheSpendingOfASpecificGrantIsSummedFromTheBooksAndAnEmptyOutputIsMissing(): void {
		$rows = $this->appendix()->forYear(administrationId: 'gem-1', fiscalYear: '2026');

		self::assertSame(['D8/01', 'Baten (jaar T)', 'D8/03', 'D8/04'], array_column($rows, 'indicatorCode'));
		self::assertSame(['grant-d8'], array_values(array_unique(array_column($rows, 'grantId'))));
		self::assertSame(['D8'], array_values(array_unique(array_column($rows, 'regulationCode'))));

		self::assertSame('spending', $rows[0]['kind']);
		self::assertSame(120000.0, $rows[0]['value']);
		self::assertSame('books', $rows[0]['source']);
		self::assertFalse($rows[0]['missing']);

		self::assertSame('income', $rows[1]['kind']);
		self::assertSame(95000.5, $rows[1]['value']);

		self::assertSame(42.0, $rows[2]['value']);
		self::assertSame('entered', $rows[2]['source']);
		self::assertFalse($rows[2]['missing']);

		self::assertNull($rows[3]['value']);
		self::assertTrue($rows[3]['missing']);

	}//end testTheSpendingOfASpecificGrantIsSummedFromTheBooksAndAnEmptyOutputIsMissing()

	/**
	 * An administration without specific grants has an empty appendix; a
	 * missing administration or year is refused.
	 *
	 * @return void
	 */
	public function testAnAdministrationWithoutSpecificGrantsIsEmptyAndABadYearIsRefused(): void {
		$appendix = $this->appendix();
		self::assertSame([], $appendix->forYear(administrationId: 'gem-3', fiscalYear: '2026'));

		$this->expectException(InvalidArgumentException::class);
		$appendix->forYear(administrationId: 'gem-1', fiscalYear: '26');

	}//end testAnAdministrationWithoutSpecificGrantsIsEmptyAndABadYearIsRefused()

	/**
	 * The export: the template's columns in order, one line per indicator, a
	 * missing value empty, and a formula-shaped cell defused.
	 *
	 * @return void
	 */
	public function testTheExportHasOneLinePerIndicatorInTheTemplateColumnOrder(): void {
		$appendix = $this->appendix();
		$rows = $appendix->forYear(administrationId: 'gem-1', fiscalYear: '2026');
		$rows[2]['description'] = '=HYPERLINK("x")';

		$lines = explode("\r\n", rtrim($appendix->toCsv(rows: $rows), "\r\n"));

		self::assertCount(5, $lines);
		self::assertSame('"Code";"Specifieke uitkering";"Beschikking";"Indicator";"Omschrijving";"Waarde";"Eenheid"', $lines[0]);
		self::assertSame('"D8";"Volkshuisvesting";"SUB-2026-D8";"D8/01";"Besteding (jaar T)";"120000.00";"EUR"', $lines[1]);
		self::assertSame('"D8";"Volkshuisvesting";"SUB-2026-D8";"D8/03";"\'=HYPERLINK(""x"")";"42.00";"aantal"', $lines[3]);
		self::assertSame('"D8";"Volkshuisvesting";"SUB-2026-D8";"D8/04";"Aantal locaties";"";"aantal"', $lines[4]);

	}//end testTheExportHasOneLinePerIndicatorInTheTemplateColumnOrder()

	/**
	 * The entered indicators are valid SisaIndicator records, and the grant and
	 * ledger fields the appendix reads exist on the merged register.
	 *
	 * @return void
	 */
	public function testTheFieldsTheAppendixReadsAreDeclared(): void {
		$this->appendix();
		foreach ($this->store->dump('SisaIndicator') as $indicator) {
			self::assertSame([], RegisterSchema::errors('SisaIndicator', array_diff_key($indicator, ['id' => true])));
		}

		self::assertArrayHasKey('sisaRegulationCode', RegisterSchema::schema('Subsidie')['properties']);
		self::assertArrayHasKey('subsidieId', RegisterSchema::schema('GLLine')['properties']);

	}//end testTheFieldsTheAppendixReadsAreDeclared()
}//end class
