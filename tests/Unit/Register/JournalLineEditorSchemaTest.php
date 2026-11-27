<?php

/**
 * Unit tests for the journal entry line editor declared on the JournalEntry schema.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Register
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-25-ledger-journal-line-dimensions/tasks.md#task-1.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Register;

use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;

/**
 * JournalDetail edits `JournalEntry.lines` as a table of lines (the library's
 * sub-objects editor). The cost centre, cost carrier and project codes are
 * table columns and each is a picker over the active analytical dimensions of
 * its own type that stores the dimension's code, the value posting checks
 * (REQ-LJD-001, REQ-LJD-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class JournalLineEditorSchemaTest extends TestCase {

	/**
	 * The editor shows at most this many line properties as table columns.
	 */
	private const TABLE_COLUMNS = 6;

	/**
	 * Each line code and the dimension type its picker offers.
	 */
	private const PICKERS = [
		'costCenterCode'  => 'cost-center',
		'costCarrierCode' => 'cost-object',
		'projectCode'     => 'project',
	];

	/**
	 * The lines property of the merged JournalEntry schema.
	 *
	 * @return array<string,mixed>
	 */
	private function lines(): array {
		return RegisterSchema::schema('JournalEntry')['properties']['lines'];
	}//end lines()

	/**
	 * The lines are edited as a table of line objects.
	 *
	 * @return void
	 */
	public function testLinesAreEditedAsATable(): void {
		$lines = $this->lines();

		self::assertSame('array', $lines['type']);
		self::assertSame('sub-objects', $lines['x-widget']);
		self::assertSame('object', $lines['items']['type']);
	}//end testLinesAreEditedAsATable()

	/**
	 * Account, side, amount and the three codes are the table's columns.
	 *
	 * @return void
	 */
	public function testTheCodesAreTableColumns(): void {
		$columns = array_slice(array_keys($this->lines()['items']['properties']), 0, self::TABLE_COLUMNS);

		self::assertSame(['accountNumber', 'side', 'amount', 'costCenterCode', 'costCarrierCode', 'projectCode'], $columns);
	}//end testTheCodesAreTableColumns()

	/**
	 * Each code picks from the active dimensions of its type and stores the code.
	 *
	 * @return void
	 */
	public function testEachCodePicksAnActiveDimensionOfItsType(): void {
		$properties = $this->lines()['items']['properties'];

		foreach (self::PICKERS as $code => $dimensionType) {
			$from = $properties[$code]['x-options-from'] ?? null;
			self::assertIsArray($from, $code . ' has no picker');
			self::assertSame('shillinq', $from['register'], $code);
			self::assertSame('AnalyticalDimension', $from['schema'], $code);
			self::assertSame(['dimensionType' => $dimensionType, 'lifecycleState' => 'active'], $from['filter'], $code);
			self::assertSame('code', $from['valueField'], $code);
			self::assertSame('name', $from['labelField'], $code);
		}
	}//end testEachCodePicksAnActiveDimensionOfItsType()

	/**
	 * The picker filters on fields the dimension schema declares, with values it allows.
	 *
	 * @return void
	 */
	public function testThePickerFilterMatchesTheDimensionSchema(): void {
		$dimension = RegisterSchema::schema('AnalyticalDimension')['properties'];

		self::assertArrayHasKey('code', $dimension);
		self::assertArrayHasKey('name', $dimension);
		foreach (self::PICKERS as $dimensionType) {
			self::assertContains($dimensionType, $dimension['dimensionType']['enum']);
		}

		self::assertContains('active', $dimension['lifecycleState']['enum']);
	}//end testThePickerFilterMatchesTheDimensionSchema()

	/**
	 * A line booked on CC-100 through the editor still saves; a line without codes too.
	 *
	 * @return void
	 */
	public function testALineOnCostCentreCc100StillValidates(): void {
		$entry = [
			'journalNumber'    => 'MEM-2026-0001',
			'entryDate'        => '2026-11-25',
			'description'      => 'Office rent split',
			'journalType'      => 'memorial',
			'state'            => 'draft',
			'administrationId' => 'adm-1',
			'lines'            => [
				['accountNumber' => '4500', 'side' => 'debit', 'amount' => 100.0, 'costCenterCode' => 'CC-100'],
				['accountNumber' => '1100', 'side' => 'credit', 'amount' => 100.0],
			],
		];

		$errors = array_filter(
			RegisterSchema::errors('JournalEntry', $entry),
			static fn (string $path): bool => str_starts_with($path, '/lines'),
			ARRAY_FILTER_USE_KEY
		);

		self::assertSame([], $errors);
	}//end testALineOnCostCentreCc100StillValidates()
}//end class
