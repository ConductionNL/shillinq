<?php

/**
 * Characterisation tests for ReinvestmentReserves::apply.
 *
 * Pins every refusal and its message, the amount rules, the journal posted,
 * the reserve patch and the asset returned, against a recorded AssetRecords.
 *
 * @category Tests
 * @package  OCA\Shillinq\Tests\Unit\Asset
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */


declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Asset;

use DomainException;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Asset\ReinvestmentReserves;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Branch-by-branch characterisation of applying a reinvestment reserve.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ReinvestmentReserveApplyTest extends TestCase {

	/**
	 * Calls made on the register.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Build the service over one reserve.
	 *
	 * @param array<string, mixed>|null $reserve           The stored reserve.
	 * @param string                    $configuredAccount The configured reserve account.
	 *
	 * @return ReinvestmentReserves The service.
	 */
	private function reserves(?array $reserve, string $configuredAccount = ''): ReinvestmentReserves {
		$records = $this->createMock(AssetRecords::class);
		$records->method('find')->willReturnCallback(
			function (string $schema, string $id) use ($reserve): ?array {
				$this->calls[] = ['find', $schema, $id];
				return $reserve;
			}
		);
		$records->method('postJournal')->willReturnCallback(
			function (array $journal): string {
				$this->calls[] = ['postJournal', $journal];
				return 'je-1';
			}
		);
		$records->method('patch')->willReturnCallback(
			function (string $schema, string $id, array $fields): void {
				$this->calls[] = ['patch', $schema, $id, $fields];
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($configuredAccount);

		return new ReinvestmentReserves($records, $appConfig);

	}//end reserves()

	/**
	 * An open reserve of 60.00 with 10.00 applied.
	 *
	 * @return array<string, mixed> The reserve.
	 */
	private static function reserve(): array {
		return ['id' => 'res-1', 'lifecycleState' => 'open', 'administrationId' => 'adm-1', 'amount' => 60, 'appliedAmount' => 10, 'disposedAssetNumber' => 'OLD-1', 'applications' => [['assetRef' => 'earlier']]];

	}//end reserve()

	/**
	 * A replacement asset costing 100.00.
	 *
	 * @return array<string, mixed> The asset.
	 */
	private static function asset(): array {
		return ['id' => 'fa-9', 'administrationId' => 'adm-1', 'assetNumber' => 'NEW-9', 'acquisitionCost' => 100, 'assetAccountNumber' => '0320', 'acquisitionDate' => '2026-05-17T10:00:00Z', 'reinvestmentReserveId' => ' res-1 '];

	}//end asset()

	/**
	 * Applying the whole remainder closes the reserve.
	 *
	 * @return void
	 */
	public function testFullApplication(): void {
		$asset = $this->reserves(self::reserve(), '0641')->apply(self::asset());

		self::assertSame(50, $asset['fiscalCostBasis']);
		self::assertNull($asset['reserveAmountApplied']);
		$text = 'Herinvesteringsreserve OLD-1 op NEW-9';
		self::assertSame(
			[
				['find', 'ReinvestmentReserve', 'res-1'],
				[
					'postJournal',
					[
						'journalNumber' => 'HIR-20260517-' . substr(hash('sha256', 'res-1|fa-9'), 0, 8),
						'entryDate' => '2026-05-17',
						'description' => $text,
						'lines' => [
							['accountNumber' => '0641', 'side' => 'debit', 'amount' => 50, 'description' => $text],
							['accountNumber' => '0320', 'side' => 'credit', 'amount' => 50, 'description' => $text],
						],
						'journalType' => 'manual',
						'approvalState' => 'not-required',
						'administrationId' => 'adm-1',
						'state' => 'draft',
					],
				],
				[
					'patch',
					'ReinvestmentReserve',
					'res-1',
					[
						'appliedAmount' => 60,
						'remainder' => 0,
						'applications' => [['assetRef' => 'earlier'], ['assetRef' => 'fa-9', 'assetNumber' => 'NEW-9', 'amount' => 50, 'journalEntryId' => 'je-1', 'appliedOn' => '2026-05-17']],
						'lifecycleState' => 'applied',
					],
				],
			],
			$this->calls
		);

	}//end testFullApplication()

	/**
	 * A requested partial amount, a fiscal basis and the reserve's own
	 * account; the reserve stays open.
	 *
	 * @return void
	 */
	public function testPartialApplication(): void {
		$reserve = self::reserve() + ['reserveAccountNumber' => '0650'];
		unset($reserve['applications']);
		$asset = self::asset();
		unset($asset['acquisitionCost'], $asset['assetAccountNumber'], $asset['acquisitionDate']);
		$asset += ['purchaseCost' => 80, 'fiscalCostBasis' => '70.50', 'capitalizationAccountNumber' => '0330', 'purchaseDate' => '2026-06-01', 'reserveAmountApplied' => '12.25'];

		$result = $this->reserves($reserve)->apply($asset);

		self::assertSame(58.25, $result['fiscalCostBasis']);
		self::assertSame(['0650', '0330'], array_column($this->calls[1][1]['lines'], 'accountNumber'));
		self::assertSame('2026-06-01', $this->calls[1][1]['entryDate']);
		self::assertSame(
			['appliedAmount' => 22.25, 'remainder' => 37.75, 'applications' => [['assetRef' => 'fa-9', 'assetNumber' => 'NEW-9', 'amount' => 12.25, 'journalEntryId' => 'je-1', 'appliedOn' => '2026-06-01']]],
			$this->calls[2][3]
		);

	}//end testPartialApplication()

	/**
	 * Refusals, their messages, and that nothing is posted.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$cases = [
			'no reserve'          => [null, [], 'Choose an open reinvestment reserve.'],
			'applied reserve'     => [['lifecycleState' => 'applied'] + self::reserve(), [], 'Choose an open reinvestment reserve.'],
			'other administration' => [['administrationId' => 'adm-2'] + self::reserve(), [], 'The reinvestment reserve belongs to another administration.'],
			'too much'            => [self::reserve(), ['reserveAmountApplied' => 51], 'Apply between 0.01 and 50.00.'],
			'over the basis'      => [self::reserve(), ['reserveAmountApplied' => 40, 'fiscalCostBasis' => 30], 'Apply between 0.01 and 30.00.'],
			'nothing left'        => [['appliedAmount' => 60] + self::reserve(), [], 'Apply between 0.01 and 0.00.'],
			'no asset account'    => [self::reserve(), ['assetAccountNumber' => ''], 'The asset has no asset account to lower.'],
		];
		foreach ($cases as $label => [$reserve, $overrides, $message]) {
			$this->calls = [];
			try {
				$this->reserves($reserve)->apply(array_merge(self::asset(), $overrides));
				self::fail('Expected a refusal: ' . $label);
			} catch (DomainException $e) {
				self::assertSame($message, $e->getMessage(), $label);
			}

			self::assertCount(1, $this->calls, $label);
		}

	}//end testRefusals()
}//end class
