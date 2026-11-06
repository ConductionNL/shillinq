<?php

/**
 * Unit tests for FidoQuarter.
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
 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-wet-fido-treasury/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;

use OCA\Shillinq\Service\PublicSector\FidoQuarter;
use OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../InMemoryObjectService.php';

/**
 * FidoQuarter unit tests (REQ-FDO-010, REQ-FDO-011).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class FidoQuarterTest extends TestCase {

	/**
	 * A store with the 2026 limits of a municipality with a EUR 100 million budget.
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
		$os->setSchema('Account')->saveObject(['administrationId' => 'gem-1', 'accountNumber' => '1100', 'accountType' => 'bank']);
		$os->setSchema('Account')->saveObject(['administrationId' => 'gem-1', 'accountNumber' => '4000', 'accountType' => 'expense']);

		return $os;

	}//end store()

	/**
	 * Add a loan.
	 *
	 * @param InMemoryObjectService $os       The store.
	 * @param string                $type     Loan type.
	 * @param float                 $amount   Principal.
	 * @param string                $issue    Issue date.
	 * @param string                $maturity Maturity date.
	 *
	 * @return void
	 */
	private function loan(InMemoryObjectService $os, string $type, float $amount, string $issue, string $maturity): void {
		$os->setSchema('Lening')->saveObject(
			[
				'organisationId' => 'gem-1',
				'type' => $type,
				'principal' => $amount,
				'issueDate' => $issue,
				'maturityDate' => $maturity,
				'status' => 'recorded',
			]
		);

	}//end loan()

	/**
	 * Build the service over a store.
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
	 * The spec scenario: EUR 100 million at 8.5 percent and EUR 6 million of
	 * net floating debt give a EUR 8.5 million limit and EUR 2.5 million headroom.
	 *
	 * @return void
	 */
	public function testATreasurerComputesTheThirdQuarter(): void {
		$os = $this->store();
		$this->loan($os, 'kasgeld', 6000000.0, '2026-06-01', '2026-12-01');
		// A draft loan and a loan that ended before July do not count.
		$os->setSchema('Lening')->saveObject(
			[
				'organisationId' => 'gem-1',
				'type' => 'kasgeld',
				'principal' => 9.0e6,
				'issueDate' => '2026-07-01',
				'maturityDate' => '2026-11-01',
				'status' => 'draft',
			]
		);
		$this->loan($os, 'kasgeld', 3000000.0, '2026-01-01', '2026-06-30');

		$result = $this->service($os)->compute(organisationId: 'gem-1', year: '2026', quarter: 3);

		self::assertSame(8500000.0, $result['cashStatus']['ceiling']);
		self::assertSame(6000000.0, $result['cashStatus']['exposure']);
		self::assertSame(2500000.0, $result['cashStatus']['headroom']);
		self::assertSame('binnen-norm', $result['cashStatus']['status']);

		$limit = $os->dump(schema: 'KasgeldLimiet')[0];
		self::assertSame(8500000.0, $limit['calculatedCeiling']);
		self::assertSame(6000000.0, $limit['currentExposure']);
		self::assertSame(2500000.0, $limit['headroom']);
		self::assertSame([], RegisterSchema::errors('KasgeldLimiet', $limit));

	}//end testATreasurerComputesTheThirdQuarter()

	/**
	 * Money on the bank lowers the net floating debt: EUR 1 million posted
	 * on the bank account in July is subtracted at every month end.
	 *
	 * @return void
	 */
	public function testBankBalanceLowersTheNetFloatingDebt(): void {
		$os = $this->store();
		$this->loan($os, 'kasgeld', 6000000.0, '2026-06-01', '2026-12-01');
		$os->setSchema('GLTransaction')->saveObject(
			['id' => 'tx-1', 'transactionNumber' => 'MEM-1', 'administrationId' => 'gem-1', 'state' => 'posted', 'postingDate' => '2026-07-15']
		);
		$os->setSchema('GLLine')->saveObject(['transactionId' => 'MEM-1', 'accountNumber' => '1100', 'side' => 'debit', 'amount' => 1000000.0]);
		$os->setSchema('GLLine')->saveObject(['transactionId' => 'MEM-1', 'accountNumber' => '4000', 'side' => 'credit', 'amount' => 1000000.0]);
		// A posting after the quarter does not count.
		$os->setSchema('GLTransaction')->saveObject(
			['id' => 'tx-2', 'administrationId' => 'gem-1', 'state' => 'posted', 'postingDate' => '2026-10-02']
		);
		$os->setSchema('GLLine')->saveObject(['transactionId' => 'tx-2', 'accountNumber' => '1100', 'side' => 'debit', 'amount' => 500000.0]);

		$result = $this->service($os)->compute(organisationId: 'gem-1', year: '2026', quarter: 3);

		self::assertSame(5000000.0, $result['cashStatus']['exposure']);
		self::assertSame(3500000.0, $result['cashStatus']['headroom']);

	}//end testBankBalanceLowersTheNetFloatingDebt()

	/**
	 * Above the limit for a second quarter in a row climbs the ladder.
	 *
	 * @return void
	 */
	public function testASecondQuarterAboveTheLimitClimbsTheLadder(): void {
		$os = $this->store();
		$this->loan($os, 'kasgeld', 9000000.0, '2026-06-01', '2026-12-01');
		$os->setSchema('QuartaalrapportageFido')->saveObject(
			['auditYear' => 2026, 'quarter' => 'Q2', 'organisationId' => 'gem-1', 'cashStatus' => ['status' => 'overschrijding-1-kwartaal']]
		);

		$result = $this->service($os)->compute(organisationId: 'gem-1', year: '2026', quarter: 3);

		self::assertSame(-500000.0, $result['cashStatus']['headroom']);
		self::assertSame('overschrijding-2-kwartalen', $result['cashStatus']['status']);

	}//end testASecondQuarterAboveTheLimitClimbsTheLadder()

	/**
	 * The spec scenario: EUR 12 million refinancing in 2026 against a EUR 20
	 * million norm (20 percent of the budget total) leaves EUR 8 million room.
	 *
	 * @return void
	 */
	public function testTheInterestRiskNormShowsTheRoomLeft(): void {
		$os = $this->store();
		$this->loan($os, 'onderhandse-lening', 12000000.0, '2016-10-01', '2026-10-01');
		$this->loan($os, 'onderhandse-lening', 4000000.0, '2018-01-01', '2028-01-01');

		$result = $this->service($os)->compute(organisationId: 'gem-1', year: '2026', quarter: 3);

		$risk = $result['renteRiskStatus'];
		self::assertSame(20000000.0, $risk['ceiling']);
		self::assertSame(12000000.0, $risk['exposure']);
		self::assertSame(8000000.0, $risk['headroom']);
		self::assertSame('binnen-norm', $risk['status']);

		$norm = $os->dump(schema: 'RenteRisicoNorm')[0];
		self::assertSame(20000000.0, $norm['calculatedCeiling']);
		self::assertSame([2026, 2027, 2028, 2029], array_column($norm['forwardLooking4Year'], 'year'));
		self::assertSame([8000000.0, 20000000.0, 16000000.0, 20000000.0], $norm['headroomPerYear']);
		self::assertSame([], RegisterSchema::errors('RenteRisicoNorm', $norm));

	}//end testTheInterestRiskNormShowsTheRoomLeft()

	/**
	 * A year without a cash limit record is refused: there is no budget to compute from.
	 *
	 * @return void
	 */
	public function testAYearWithoutALimitIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service($this->store())->compute(organisationId: 'gem-1', year: '2027', quarter: 1);

	}//end testAYearWithoutALimitIsRefused()
}//end class
