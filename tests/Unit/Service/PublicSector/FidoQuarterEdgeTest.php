<?php

/**
 * Unit tests for FidoQuarter's edge cases: refused input, the ladder carried
 * over the year boundary to its last step, a loan without dates, and an
 * interest-risk breach in a later year.
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

use InvalidArgumentException;
use OCA\Shillinq\Service\PublicSector\FidoQuarter;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../InMemoryObjectService.php';

/**
 * Tests for FidoQuarter's edge cases.
 */
#[CoversClass(FidoQuarter::class)]
final class FidoQuarterEdgeTest extends TestCase {

	/**
	 * A store with the 2026 cash limit (8.5M ceiling) and interest-risk norm (20M).
	 *
	 * @return InMemoryObjectService
	 */
	private function store(): InMemoryObjectService {
		$os = new InMemoryObjectService();
		$os->setSchema('KasgeldLimiet')->saveObject(
			['id' => 'kl-2026', 'auditYear' => 2026, 'organisationId' => 'gem-1', 'baseBudget' => 100000000.0, 'percentage' => 8.5]
		);
		$os->setSchema('RenteRisicoNorm')->saveObject(
			['id' => 'rrn-2026', 'auditYear' => 2026, 'organisationId' => 'gem-1', 'baseFixedDebt' => 60000000.0, 'percentage' => 20.0]
		);
		return $os;
	}//end store()

	/**
	 * Record a loan.
	 *
	 * @param InMemoryObjectService $os       The store.
	 * @param string                $type     The loan type.
	 * @param float                 $amount   The principal.
	 * @param string                $issue    The issue date.
	 * @param string                $maturity The maturity date.
	 *
	 * @return void
	 */
	private function loan(InMemoryObjectService $os, string $type, float $amount, string $issue, string $maturity): void {
		$os->setSchema('Lening')->saveObject(
			[
				'organisationId' => 'gem-1',
				'type'           => $type,
				'principal'      => $amount,
				'issueDate'      => $issue,
				'maturityDate'   => $maturity,
				'status'         => 'recorded',
			]
		);
	}//end loan()

	/**
	 * The service over a store.
	 *
	 * @param InMemoryObjectService $os The store.
	 *
	 * @return FidoQuarter
	 */
	private function service(InMemoryObjectService $os): FidoQuarter {
		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		return new FidoQuarter(appConfig: $appConfig, objectService: new DuckObjectServiceAdapter($os));
	}//end service()

	/**
	 * Input that names no organisation, an impossible quarter or year.
	 *
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function refusedInput(): array {
		return [
			'quarter zero'    => ['gem-1', '2026', 0],
			'quarter five'    => ['gem-1', '2026', 5],
			'year before 1900' => ['gem-1', '1899', 1],
			'not a year'      => ['gem-1', 'twee', 1],
			'no organisation' => ['', '2026', 1],
		];
	}//end refusedInput()

	/**
	 * Refused input is refused before anything is read.
	 *
	 * @param string $organisation The organisation.
	 * @param string $year         The year.
	 * @param int    $quarter      The quarter.
	 *
	 * @return void
	 */
	#[DataProvider('refusedInput')]
	public function testImpossibleInputIsRefused(string $organisation, string $year, int $quarter): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A Fido quarter needs an organisation, a year and a quarter from 1 to 4.');

		$this->service($this->store())->compute(organisationId: $organisation, year: $year, quarter: $quarter);
	}//end testImpossibleInputIsRefused()

	/**
	 * A first quarter reads the previous year's fourth quarter; a breach after
	 * two breached quarters reaches the last step and stays there.
	 *
	 * @return void
	 */
	public function testTheLadderCrossesTheYearAndStopsAtItsLastStep(): void {
		foreach (['overschrijding-2-kwartalen', 'sanering-verplicht'] as $before) {
			$os = $this->store();
			$this->loan($os, 'kasgeld', 9000000.0, '2025-12-01', '2026-06-01');
			$os->setSchema('QuartaalrapportageFido')->saveObject(
				['auditYear' => 2025, 'quarter' => 'Q4', 'organisationId' => 'gem-1', 'cashStatus' => ['status' => $before]]
			);
			// The same year's Q4 must not be taken for the quarter before Q1.
			$os->setSchema('QuartaalrapportageFido')->saveObject(
				['auditYear' => 2026, 'quarter' => 'Q4', 'organisationId' => 'gem-1', 'cashStatus' => ['status' => 'binnen-norm']]
			);

			$result = $this->service($os)->compute(organisationId: 'gem-1', year: '2026', quarter: 1);

			self::assertSame(-500000.0, $result['cashStatus']['headroom'], $before);
			self::assertSame('sanering-verplicht', $result['cashStatus']['status'], $before);
		}
	}//end testTheLadderCrossesTheYearAndStopsAtItsLastStep()

	/**
	 * A loan without an issue or maturity date is not counted as floating debt.
	 *
	 * @return void
	 */
	public function testALoanWithoutDatesIsNotFloatingDebt(): void {
		$os = $this->store();
		$this->loan($os, 'kasgeld', 9000000.0, '', '2026-12-01');
		$this->loan($os, 'kasgeld', 9000000.0, '2026-06-01', '');

		$result = $this->service($os)->compute(organisationId: 'gem-1', year: '2026', quarter: 3);

		self::assertSame(0.0, $result['cashStatus']['exposure']);
		self::assertSame('binnen-norm', $result['cashStatus']['status']);
	}//end testALoanWithoutDatesIsNotFloatingDebt()

	/**
	 * A refinancing peak in a later year breaches the norm for that year.
	 *
	 * @return void
	 */
	public function testALaterYearAboveTheNormIsReportedByYear(): void {
		$os = $this->store();
		$this->loan($os, 'onderhandse-lening', 25000000.0, '2017-06-01', '2027-06-01');
		$this->loan($os, 'onderhandse-lening', 30000000.0, '2018-06-01', '2028-06-01');

		$risk = $this->service($os)->compute(organisationId: 'gem-1', year: '2026', quarter: 3)['renteRiskStatus'];

		self::assertSame('overschrijding-jaar-2027', $risk['status']);
		self::assertSame(20000000.0, $risk['headroom']);
		$norm = $os->dump(schema: 'RenteRisicoNorm')[0];
		self::assertSame('overschrijding-jaar-2027', $norm['status']);
		self::assertSame([20000000.0, -5000000.0, -10000000.0, 20000000.0], $norm['headroomPerYear']);
	}//end testALaterYearAboveTheNormIsReportedByYear()
}//end class
