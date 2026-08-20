<?php

/**
 * Unit tests for ProgrammabegrotingExporter.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-28
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ProgrammabegrotingExporter;
use PHPUnit\Framework\TestCase;

/**
 * Tests the iv3 / EMU / JSON exports (REQ-012).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ProgrammabegrotingExporterTest extends TestCase {

	/**
	 * The exporter under test.
	 *
	 * @var ProgrammabegrotingExporter
	 */
	private ProgrammabegrotingExporter $exporter;

	/**
	 * Set up the exporter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->exporter = new ProgrammabegrotingExporter();

	}//end setUp()

	/**
	 * REQ-012 scenario: iv3 aggregates baten/lasten per taakveldCode across programma's.
	 *
	 * @return void
	 */
	public function testIv3AggregatesPerTaakveld(): void {
		$rows = $this->exporter->iv3Rows(
			taskFields: [
				['taskFieldCode' => '1.1', 'revenue' => 50.0, 'expenses' => 450.0],
				['taskFieldCode' => '1.1', 'revenue' => 10.0, 'expenses' => 50.0],
				['taskFieldCode' => '6.1', 'revenue' => 120.0, 'expenses' => 1300.0],
			]
		);

		self::assertCount(2, $rows);
		// Sorted by code: 1.1 first, aggregated 60 / 500.
		self::assertSame('1.1', $rows[0]['taskFieldCode']);
		self::assertSame(60.0, $rows[0]['revenue']);
		self::assertSame(500.0, $rows[0]['expenses']);
		self::assertSame('6.1', $rows[1]['taskFieldCode']);

	}//end testIv3AggregatesPerTaakveld()

	/**
	 * REQ-012: EMU-saldo adds back capitalised investeringen and reserve mutations.
	 *
	 * @return void
	 */
	public function testEmuSaldoAppliesCorrections(): void {
		// Σbaten - Σlasten = 100 - 600 = -500; + investering 400 + reserve 50 = -50.
		$balance = $this->exporter->emuSaldo(
			taskFields: [['revenue' => 100.0, 'expenses' => 600.0]],
			investeringen: [['gross' => 400.0]],
			reserveMovements: 50.0
		);
		self::assertSame(-50.0, $balance);

	}//end testEmuSaldoAppliesCorrections()

	/**
	 * REQ-012 scenario: the JSON export carries metadata, programma's, taakvelden, paragrafen.
	 *
	 * @return void
	 */
	public function testJsonExportShapeIsComplete(): void {
		$export = $this->exporter->jsonExport(
			budget: [
				'budgetYear' => 2027,
				'organisationType' => 'municipality',
				'status' => 'determined',
				'determinationDate' => '2026-11-09',
				'structurallyBalanced' => true,
				'sluitendReëel' => true,
				'supervisionRegime' => 'repressief',
			],
			programmas: [['number' => '1', 'name' => 'Veiligheid', 'doelstellingen' => 'x', 'revenueTotal' => 80.0, 'expensesTotal' => 650.0]],
			taskFields: [['taskFieldCode' => '1.1', 'revenue' => 50.0, 'expenses' => 450.0]],
			paragrafen: [['type' => 'lokaleHeffingen', 'narrative' => 'text', 'keyFigures' => []]]
		);

		self::assertSame(2027, $export['metadata']['budgetYear']);
		self::assertTrue($export['metadata']['structurallyBalanced']);
		self::assertCount(1, $export['programmas']);
		self::assertSame('Veiligheid', $export['programmas'][0]['name']);
		self::assertCount(1, $export['taskFields']);
		self::assertSame('1.1', $export['taskFields'][0]['taskFieldCode']);
		self::assertCount(1, $export['paragrafen']);
		self::assertSame('lokaleHeffingen', $export['paragrafen'][0]['type']);
		// The whole shape must be json-encodable.
		self::assertIsString(json_encode($export));

	}//end testJsonExportShapeIsComplete()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
