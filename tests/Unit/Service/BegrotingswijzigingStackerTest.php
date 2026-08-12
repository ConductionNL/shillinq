<?php

/**
 * Unit tests for BegrotingswijzigingStacker.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-26
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BegrotingswijzigingStacker;
use PHPUnit\Framework\TestCase;

/**
 * Tests the event-sourced delta stacking (REQ-009, D3).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BegrotingswijzigingStackerTest extends TestCase {

	/**
	 * The stacker under test.
	 *
	 * @var BegrotingswijzigingStacker
	 */
	private BegrotingswijzigingStacker $stacker;

	/**
	 * Set up the stacker.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->stacker = new BegrotingswijzigingStacker();

	}//end setUp()

	/**
	 * REQ-009 scenario: a vastgestelde wijziging delta stacks onto the basis.
	 *
	 * @return void
	 */
	public function testVastgesteldeWijzigingStacksOntoBasis(): void {
		$basis = [
			['taakveldCode' => '1.1', 'baten' => 100.0, 'lasten' => 500.0],
		];
		$wijzigingen = [
			[
				'status' => 'vastgesteld',
				'mutaties' => [
					['taakveldCode' => '1.1', 'baten_delta' => 50.0, 'lasten_delta' => -100.0],
				],
			],
		];

		$stand = $this->stacker->currentStand(basisTaakvelden: $basis, wijzigingen: $wijzigingen);
		self::assertSame(150.0, $stand['1.1']['baten']);
		self::assertSame(400.0, $stand['1.1']['lasten']);

	}//end testVastgesteldeWijzigingStacksOntoBasis()

	/**
	 * A draft wijziging has no effect on the stand (REQ-009 immutability).
	 *
	 * @return void
	 */
	public function testDraftWijzigingDoesNotStack(): void {
		$basis = [['taakveldCode' => '1.1', 'baten' => 100.0, 'lasten' => 500.0]];
		$wijzigingen = [
			[
				'status' => 'draft',
				'mutaties' => [['taakveldCode' => '1.1', 'baten_delta' => 999.0, 'lasten_delta' => 999.0]],
			],
		];

		$stand = $this->stacker->currentStand(basisTaakvelden: $basis, wijzigingen: $wijzigingen);
		self::assertSame(100.0, $stand['1.1']['baten']);
		self::assertSame(500.0, $stand['1.1']['lasten']);

	}//end testDraftWijzigingDoesNotStack()

	/**
	 * A reversal (negative delta) nets the stand back exactly (event-sourcing).
	 *
	 * @return void
	 */
	public function testReversalNetsBackExactly(): void {
		$basis = [['taakveldCode' => '1.1', 'baten' => 0.0, 'lasten' => 500.0]];
		$wijzigingen = [
			['status' => 'vastgesteld', 'mutaties' => [['taakveldCode' => '1.1', 'lasten_delta' => 100.0]]],
			['status' => 'vastgesteld', 'mutaties' => [['taakveldCode' => '1.1', 'lasten_delta' => -100.0]]],
		];

		$stand = $this->stacker->currentStand(basisTaakvelden: $basis, wijzigingen: $wijzigingen);
		self::assertSame(500.0, $stand['1.1']['lasten']);

	}//end testReversalNetsBackExactly()

	/**
	 * The authorizedLasten helper returns the stacked lasten for a taakveldCode (REQ-010).
	 *
	 * @return void
	 */
	public function testAuthorizedLastenStacked(): void {
		$basis = [['taakveldCode' => '6.1', 'baten' => 0.0, 'lasten' => 1000.0]];
		$wijzigingen = [
			['status' => 'vastgesteld', 'mutaties' => [['taakveldCode' => '6.1', 'lasten_delta' => 250.0]]],
		];

		self::assertSame(
			1250.0,
			$this->stacker->authorizedLasten(taakveldCode: '6.1', basisTaakvelden: $basis, wijzigingen: $wijzigingen)
		);
		self::assertSame(
			0.0,
			$this->stacker->authorizedLasten(taakveldCode: 'unknown', basisTaakvelden: $basis, wijzigingen: $wijzigingen)
		);

	}//end testAuthorizedLastenStacked()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
