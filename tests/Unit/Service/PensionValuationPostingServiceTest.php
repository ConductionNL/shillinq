<?php

/**
 * Unit tests for PensionValuationPostingService.
 *
 * REQ-PEN-011: approving an actuarial valuation books the service cost and
 * net interest to the profit and loss and the remeasurement to other
 * comprehensive income, in one balanced journal entry. OpenRegister is
 * doubled at the object-service boundary, recording every save.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-ifrs-calculations/specs/bookkeeping-pension-ias19/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\PensionValuationPostingService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The plan of the scenario: service cost 80,000, net interest 12,000, actuarial loss 30,000.
 */
class PensionValuationPostingServiceTest extends TestCase {

	/**
	 * Saved objects, in order, as [schema, data].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Stored rows by schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The service over an in-memory object service.
	 *
	 * @param array<string, string> $config App-config values.
	 * @param bool                  $failSave Whether every save throws.
	 *
	 * @return PensionValuationPostingService
	 */
	private function service(array $config = [], bool $failSave = false): PensionValuationPostingService {
		$schema = '';
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $value) use (&$schema, $objects): ObjectServiceInterface {
				$schema = (string)$value;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $query) use (&$schema): array {
				return array_values(
					array_filter(
						($this->rows[$schema] ?? []),
						static fn (array $row): bool => array_intersect_assoc(array_map('strval', $query['filters']), array_map(static fn ($v) => is_scalar($v) ? (string)$v : '', $row)) === array_map('strval', $query['filters'])
					)
				);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use (&$schema, $failSave): ObjectEntityInterface {
				if ($failSave === true) {
					throw new \RuntimeException('store down');
				}

				$object['id'] = ($object['id'] ?? ($schema . '-' . count($this->saved)));
				$this->saved[] = [$schema, $object];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);
				return $entity;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default));

		return new PensionValuationPostingService($appConfig, $this->createMock(LoggerInterface::class), $objects);
	}//end service()

	/**
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function valuation(array $overrides = []): array {
		return array_merge(['id' => 'val-1', 'plan' => 'plan-db', 'valuationDate' => '2026-12-31', 'approvalStatus' => 'approved', 'discountRate' => 3.0, 'administrationId' => 'adm-1'], $overrides);
	}//end valuation()

	/**
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function movement(array $overrides = []): array {
		return array_merge(['id' => 'mov-1', 'plan' => 'plan-db', 'period' => '2026', 'serviceCostCurrent' => 80000, 'netInterestCost' => 12000, 'actuarialLossGainDBO' => 30000, 'actuarialGainLossAssets' => 0, 'administrationId' => 'adm-1'], $overrides);
	}//end movement()

	/**
	 * The saved objects of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function savedOf(string $schema): array {
		return array_values(array_map(static fn (array $save): array => $save[1], array_filter($this->saved, static fn (array $save): bool => $save[0] === $schema)));
	}//end savedOf()

	/**
	 * The scenario: one entry, 92,000 to P&L, 30,000 to OCI, balanced against the liability.
	 *
	 * @return void
	 */
	public function testApprovingTheValuationPostsOneBalancedEntry(): void {
		$this->rows['PensionMovement'] = [$this->movement()];

		$result = $this->service()->postForValuation($this->valuation());

		$this->assertTrue($result['posted']);
		$this->assertSame(9200000, $result['plCents']);
		$this->assertSame(3000000, $result['ociCents']);
		$this->assertCount(1, $this->savedOf('GLTransaction'));
		$this->assertSame('val-1', $this->savedOf('GLTransaction')[0]['sourceReference']);
		$this->assertSame('2026-12-31', $this->savedOf('GLTransaction')[0]['postingDate']);
		$lines = array_map(static fn (array $l): array => [$l['accountNumber'], $l['side'], $l['amount']], $this->savedOf('GLLine'));
		$this->assertSame([['4130', 'debit', 92000.0], ['0560', 'debit', 30000.0], ['0810', 'credit', 122000.0]], $lines);
		$debits = array_sum(array_map(static fn (array $l): float => ($l['side'] === 'debit' ? $l['amount'] : -$l['amount']), $this->savedOf('GLLine')));
		$this->assertEqualsWithDelta(0.0, $debits, 0.001, 'the entry balances');
	}//end testApprovingTheValuationPostsOneBalancedEntry()

	/**
	 * The movement gets the computed figures and the transaction it was booked in.
	 *
	 * @return void
	 */
	public function testTheMovementRecordsTheFiguresAndTheEntry(): void {
		$this->rows['PensionMovement'] = [$this->movement()];

		$this->service()->postForValuation($this->valuation());

		$movement = $this->savedOf('PensionMovement')[0];
		$this->assertSame('mov-1', $movement['id']);
		$this->assertSame(92000.0, $movement['netPensionMovementPL']);
		$this->assertSame(30000.0, $movement['netPensionMovementOCI']);
		$this->assertSame([$this->savedOf('GLTransaction')[0]['id']], $movement['linkedJournalEntries']);
	}//end testTheMovementRecordsTheFiguresAndTheEntry()

	/**
	 * Configured accounts replace the placeholders.
	 *
	 * @return void
	 */
	public function testConfiguredAccountsAreUsed(): void {
		$this->rows['PensionMovement'] = [$this->movement()];

		$this->service([PensionValuationPostingService::CFG_PENSION_COST_ACCOUNT => '4200', PensionValuationPostingService::CFG_PENSION_LIABILITY_ACCOUNT => '0700'])->postForValuation($this->valuation());

		$this->assertSame(['4200', '0560', '0700'], array_column($this->savedOf('GLLine'), 'accountNumber'));
	}//end testConfiguredAccountsAreUsed()

	/**
	 * Net interest and the asset remeasurement are computed when the movement does not carry them.
	 *
	 * @return void
	 */
	public function testMissingNetInterestAndAssetRemeasurementAreComputed(): void {
		$figures = PensionValuationPostingService::figures(
			movement: ['serviceCostCurrent' => 80000, 'pastServiceCost' => 5000, 'gainOnSettlement' => 1000, 'dboOpening' => 1000000, 'planAssetsOpening' => 600000, 'actuarialLossGainDBO' => 30000, 'actualReturnOnAssets' => 25000, 'expectedReturnOnAssets' => 18000],
			valuation: ['discountRate' => 3.0]
		);

		$this->assertSame(12000.0, $figures['netInterest'], '3% of the opening net liability of 400,000');
		$this->assertSame(7000.0, $figures['assetRemeasurement']);
		$this->assertSame(9600000, $figures['plCents'], '80,000 + 5,000 - 1,000 + 12,000');
		$this->assertSame(2300000, $figures['ociCents'], '30,000 - 7,000');
	}//end testMissingNetInterestAndAssetRemeasurementAreComputed()

	/**
	 * An actuarial gain is credited to OCI and the lines still balance.
	 *
	 * @return void
	 */
	public function testAGainIsCreditedToOtherComprehensiveIncome(): void {
		$lines = PensionValuationPostingService::lines(
			plCents: 9200000,
			ociCents: -4000000,
			accounts: [PensionValuationPostingService::CFG_PENSION_COST_ACCOUNT => 'P', PensionValuationPostingService::CFG_PENSION_OCI_ACCOUNT => 'O', PensionValuationPostingService::CFG_PENSION_LIABILITY_ACCOUNT => 'L']
		);

		$this->assertSame([['accountNumber' => 'P', 'side' => 'debit', 'cents' => 9200000], ['accountNumber' => 'O', 'side' => 'credit', 'cents' => 4000000], ['accountNumber' => 'L', 'side' => 'credit', 'cents' => 5200000]], $lines);
	}//end testAGainIsCreditedToOtherComprehensiveIncome()

	/**
	 * A valuation already booked is not booked again.
	 *
	 * @return void
	 */
	public function testAValuationIsBookedOnce(): void {
		$this->rows['PensionMovement'] = [$this->movement()];
		$this->rows['GLTransaction'] = [['id' => 'tx-earlier', 'sourceReference' => 'val-1']];

		$result = $this->service()->postForValuation($this->valuation());

		$this->assertFalse($result['posted']);
		$this->assertSame('This valuation is already booked.', $result['message']);
		$this->assertSame([], $this->saved);
	}//end testAValuationIsBookedOnce()

	/**
	 * Nothing is posted for an unapproved valuation, a missing movement or zero figures.
	 *
	 * @return void
	 */
	public function testNothingIsPostedWithoutAnApprovedValuationAndItsMovement(): void {
		$service = $this->service();
		$this->assertSame('The valuation is not approved.', $service->postForValuation($this->valuation(['approvalStatus' => 'draft']))['message']);
		$this->assertSame('No pension movement for this plan in 2026.', $service->postForValuation($this->valuation())['message']);
		$this->assertSame('The valuation names no plan or valuation date.', $service->postForValuation($this->valuation(['valuationDate' => '']))['message']);

		$this->rows['PensionMovement'] = [$this->movement(['plan' => 'other-plan'])];
		$this->assertFalse($service->postForValuation($this->valuation())['posted']);

		$this->rows['PensionMovement'] = [$this->movement(['serviceCostCurrent' => 0, 'netInterestCost' => 0, 'actuarialLossGainDBO' => 0])];
		$this->assertSame('Nothing to book: the cost and the remeasurement are zero.', $service->postForValuation($this->valuation())['message']);
		$this->assertSame([], $this->saved);
	}//end testNothingIsPostedWithoutAnApprovedValuationAndItsMovement()

	/**
	 * A store failure is reported, not thrown.
	 *
	 * @return void
	 */
	public function testAStoreFailureIsReportedNotThrown(): void {
		$this->rows['PensionMovement'] = [$this->movement()];

		$result = $this->service(failSave: true)->postForValuation($this->valuation());

		$this->assertFalse($result['posted']);
		$this->assertSame('The pension entry could not be posted.', $result['message']);
	}//end testAStoreFailureIsReportedNotThrown()
}//end class
