<?php

/**
 * Unit tests for ReserveBalances::balanceOnFirstJanuary: only realised
 * mutations of the years before count; planned ones and other years do not.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PublicSector
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

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;


use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\PublicSector\ReserveBalances;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the realised balance on 1 January.
 */
#[CoversClass(ReserveBalances::class)]
final class ReserveBalancesRealisedTest extends TestCase {

	/**
	 * Planned mutations are left out of the realised balance; realised
	 * additions and withdrawals of earlier years are counted.
	 *
	 * @return void
	 */
	public function testPlannedMutationsDoNotMoveTheRealisedBalance(): void {
		$mutations = [
			['reserveId' => 'r-1', 'year' => 2025, 'kind' => 'addition', 'status' => 'realised', 'amount' => 100.0],
			['reserveId' => 'r-1', 'year' => 2025, 'kind' => 'addition', 'status' => 'planned', 'amount' => 1000.0],
			['reserveId' => 'r-1', 'year' => 2025, 'kind' => 'withdrawal', 'status' => 'realised', 'amount' => 30.0],
			['reserveId' => 'r-1', 'year' => 2025, 'kind' => 'withdrawal', 'amount' => 7.0],
			['reserveId' => 'r-1', 'year' => 2026, 'kind' => 'addition', 'status' => 'realised', 'amount' => 50.0],
		];
		$records = $this->createMock(PublicSectorRecords::class);
		$records->method('records')->willReturnCallback(
			static fn (string $schema, array $filters): array => ($schema === 'ReserveMutation' && ($filters['reserveId'] ?? '') === 'r-1') ? $mutations : []
		);

		$balances = new ReserveBalances($records);
		$reserve = ['id' => 'r-1', 'openingBalanceYear' => 2025, 'openingBalance' => 500.0];

		self::assertSame(570.0, $balances->balanceOnFirstJanuary($reserve, 2026));
		self::assertSame(620.0, $balances->balanceOnFirstJanuary($reserve, 2027));
		self::assertSame(500.0, $balances->balanceOnFirstJanuary($reserve, 2025));

	}//end testPlannedMutationsDoNotMoveTheRealisedBalance()
}//end class
