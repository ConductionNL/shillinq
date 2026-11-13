<?php

/**
 * Unit tests for InvestmentDeductionService and InvestmentDeductionTables.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\InvestmentDeduction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\InvestmentDeduction;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Shillinq\Guard\InvesteringsaftrekEligibilityGuard;
use OCA\Shillinq\Guard\KiaSchalenLookup;
use OCA\Shillinq\Service\InvestmentDeduction\InvestmentDeductionService;
use OCA\Shillinq\Service\InvestmentDeduction\InvestmentDeductionTables;
use OCA\Shillinq\Service\RegisterBinding;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Bakkerij De Korenaar's 2026 investments, over the shipped 2026 tables.
 *
 * @covers \OCA\Shillinq\Service\InvestmentDeduction\InvestmentDeductionService
 * @covers \OCA\Shillinq\Service\InvestmentDeduction\InvestmentDeductionTables
 * @uses   \OCA\Shillinq\Service\RegisterBinding
 * @uses   \OCA\Shillinq\Guard\InvesteringsaftrekEligibilityGuard
 * @uses   \OCA\Shillinq\Guard\AssetEligibilityCheck
 * @uses   \OCA\Shillinq\Guard\KiaSchalenLookup
 */
class InvestmentDeductionServiceTest extends TestCase {

	/**
	 * Stored rows by schema, keyed by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * Deleted ids, as "schema:id".
	 *
	 * @var list<string>
	 */
	private array $deleted = [];

	/**
	 * The schema the last setSchema() named.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * The service over an in-memory register and the shipped seeds.
	 *
	 * @return InvestmentDeductionService
	 */
	private function service(): InvestmentDeductionService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $schema) use ($objects) {
				$this->schema = (string)$schema;

				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$rows = [];
				foreach (($this->store[$this->schema] ?? []) as $id => $row) {
					foreach (($config['filters'] ?? []) as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$rows[] = $this->entity(row: ['id' => $id] + $row);
				}

				return $rows;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) {
				$id = ($uuid ?? strtolower($this->schema) . '-' . (count($this->store[$this->schema] ?? []) + 1));
				$this->store[$this->schema][$id] = $object;

				return $this->entity(row: ['id' => $id] + $object);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid): bool {
				$this->deleted[] = $this->schema . ':' . $uuid;
				unset($this->store[$this->schema][$uuid]);

				return true;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$binding = new RegisterBinding(objectService: $objects, appConfig: $appConfig);

		return new InvestmentDeductionService(
			binding: $binding,
			tables: new InvestmentDeductionTables(binding: $binding),
			eligibility: new InvesteringsaftrekEligibilityGuard(logger: new NullLogger()),
			kia: new KiaSchalenLookup(logger: new NullLogger()),
		);
	}//end service()

	/**
	 * A row as OpenRegister hands it back.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $row): ObjectEntity {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($row);

		return $entity;
	}//end entity()

	/**
	 * An active fixed asset of the bakery.
	 *
	 * @param string $name The name.
	 * @param float  $cost The cost in euros.
	 * @param string $date The acquisition date.
	 *
	 * @return array<string, mixed>
	 */
	private static function fixedAsset(string $name, float $cost, string $date = '2026-03-01'): array {
		return ['name' => $name, 'acquisitionCost' => $cost, 'acquisitionDate' => $date, 'currency' => 'EUR', 'lifecycleState' => 'active', 'administrationId' => 'adm-korenaar'];
	}//end fixedAsset()

	/**
	 * A delivery van of EUR 32,000 is KIA eligible with its reason, and not
	 * EIA, MIA or Vamil because no list code matched (REQ-IDC-001).
	 *
	 * @return void
	 */
	public function testAnActivatedVanIsKiaEligibleWithItsReasons(): void {
		$asset = $this->service()->recordEligibility(fixedAssetId: 'fa-van', fixedAsset: self::fixedAsset(name: 'Bestelbus', cost: 32000.0));

		self::assertNotNull($asset);
		self::assertSame(
			['fa-van', 'Bestelbus', 3200000, '2026-03-01', true, false, false, false, null],
			[$asset['fixedAssetId'], $asset['description'], $asset['acquisitionValue'], $asset['acquisitionDate'], $asset['kiaEligible'], $asset['eiaEligible'], $asset['miaEligible'], $asset['vamilEligible'], $asset['rvoReportDeadline']]
		);
		self::assertSame(
			[
				'kia' => 'KIA: in aanmerking (binnen drempel en plafond)',
				'eia' => 'EIA: geen Energielijst-code match',
				'mia' => 'MIA: geen Milieulijst-code match',
				'vamil' => 'Vamil: geen Milieulijst-code match',
			],
			$asset['eligibilityReasons']
		);
		self::assertCount(1, $this->store['InvestmentAsset']);
	}//end testAnActivatedVanIsKiaEligibleWithItsReasons()

	/**
	 * An inactive asset, or one without an administration or id, records nothing.
	 *
	 * @return void
	 */
	public function testAnInactiveAssetRecordsNothing(): void {
		$service = $this->service();
		$proposed = ['lifecycleState' => 'proposed'] + self::fixedAsset(name: 'Oven', cost: 18000.0);

		self::assertNull($service->recordEligibility(fixedAssetId: 'fa-1', fixedAsset: $proposed));
		self::assertNull($service->recordEligibility(fixedAssetId: 'fa-1', fixedAsset: ['administrationId' => ''] + self::fixedAsset(name: 'Oven', cost: 18000.0)));
		self::assertNull($service->recordEligibility(fixedAssetId: '', fixedAsset: self::fixedAsset(name: 'Oven', cost: 18000.0)));
		self::assertSame([], $this->store);
		self::assertTrue(InvestmentDeductionService::isActive(fixedAsset: ['status' => 'active']));
	}//end testAnInactiveAssetRecordsNothing()

	/**
	 * A bookkeeper's override and list code survive a later update; the
	 * same record is updated, not duplicated; an EIA asset gets its
	 * notification deadline three months after the award.
	 *
	 * @return void
	 */
	public function testAnOverrideAndAListCodeSurviveAnUpdate(): void {
		$service = $this->service();
		$service->recordEligibility(fixedAssetId: 'fa-van', fixedAsset: self::fixedAsset(name: 'Bestelbus', cost: 32000.0));
		$id = array_key_first($this->store['InvestmentAsset']);
		$this->store['InvestmentAsset'][$id]['eligibilityOverride'] = ['scheme' => 'KIA', 'value' => false, 'rationale' => 'privé gebruik'];
		$this->store['InvestmentAsset'][$id]['energyListCode'] = '210000';
		$this->store['InvestmentAsset'][$id]['assignmentAwardDate'] = '2026-02-15';

		$asset = $service->recordEligibility(fixedAssetId: 'fa-van', fixedAsset: self::fixedAsset(name: 'Bestelbus wit', cost: 32000.0));

		self::assertCount(1, $this->store['InvestmentAsset']);
		self::assertSame([false, 'KIA: privé gebruik'], [$asset['kiaEligible'], $asset['eligibilityReasons']['kia']]);
		self::assertSame([true, '2026-05-15', 'Bestelbus wit'], [$asset['eiaEligible'], $asset['rvoReportDeadline'], $asset['description']]);
		self::assertSame('210000', $this->store['InvestmentAsset'][$id]['energyListCode']);
	}//end testAnOverrideAndAListCodeSurviveAnUpdate()

	/**
	 * 2026: KIA over EUR 64,000 is 28 % (EUR 17,920) shared by value, the heat
	 * pump also has its EIA claim; an asset from another year gets no record
	 * (REQ-IDC-002).
	 *
	 * @return void
	 */
	public function testTheYearGivesKiaByTheTiersAndEiaAtTheListPercentage(): void {
		$this->store['FixedAsset'] = [
			'fa-oven' => self::fixedAsset(name: 'Oven', cost: 18000.0),
			'fa-van' => self::fixedAsset(name: 'Bestelbus', cost: 32000.0),
			'fa-pump' => self::fixedAsset(name: 'Warmtepomp', cost: 14000.0),
			'fa-old' => self::fixedAsset(name: 'Oude kassa', cost: 900.0, date: '2025-06-01'),
		];
		$this->store['InvestmentAsset'] = [
			'ia-pump' => ['administrationId' => 'adm-korenaar', 'fixedAssetId' => 'fa-pump', 'description' => 'Warmtepomp', 'acquisitionValue' => 0, 'acquisitionDate' => '2026-03-01', 'energyListCode' => '210000'],
		];

		$result = $this->service()->calculateYear(administrationId: 'adm-korenaar', year: 2026);

		self::assertSame(['year' => 2026, 'totals' => ['KIA' => 1792000, 'EIA' => 560000, 'MIA' => 0], 'claims' => 4, 'kept' => 0], $result);
		$claims = [];
		foreach ($this->store['InvesteringsaftrekClaim'] as $claim) {
			$claims[$this->store['InvestmentAsset'][$claim['investmentAssetId']]['fixedAssetId'] . ' ' . $claim['scheme']] = [$claim['basis'], $claim['percentage'], $claim['deductionAmount'], $claim['status'], $claim['financialYear']];
		}

		ksort($claims);
		self::assertSame(
			[
				'fa-oven KIA' => [1800000, null, 504000, 'draft', 2026],
				'fa-pump EIA' => [1400000, 40, 560000, 'draft', 2026],
				'fa-pump KIA' => [1400000, null, 392000, 'draft', 2026],
				'fa-van KIA' => [3200000, null, 896000, 'draft', 2026],
			],
			$claims
		);
		self::assertCount(3, $this->store['InvestmentAsset']);
	}//end testTheYearGivesKiaByTheTiersAndEiaAtTheListPercentage()

	/**
	 * A recalculation keeps a claim used in a tax return, replaces a draft and
	 * removes a draft no longer due.
	 *
	 * @return void
	 */
	public function testARecalculationKeepsFiledClaimsAndReplacesDrafts(): void {
		$this->store['InvestmentAsset'] = [
			'ia-oven' => ['administrationId' => 'adm-korenaar', 'fixedAssetId' => 'fa-oven', 'acquisitionValue' => 1800000, 'acquisitionDate' => '2026-03-01', 'kiaEligible' => true],
			'ia-van' => ['administrationId' => 'adm-korenaar', 'fixedAssetId' => 'fa-van', 'acquisitionValue' => 3200000, 'acquisitionDate' => '2026-03-01', 'kiaEligible' => true],
		];
		$this->store['InvesteringsaftrekClaim'] = [
			'cl-oven' => ['administrationId' => 'adm-korenaar', 'investmentAssetId' => 'ia-oven', 'financialYear' => 2026, 'scheme' => 'KIA', 'deductionAmount' => 111, 'submittedInTaxReturn' => true, 'status' => 'final'],
			'cl-van' => ['administrationId' => 'adm-korenaar', 'investmentAssetId' => 'ia-van', 'financialYear' => 2026, 'scheme' => 'KIA', 'deductionAmount' => 1, 'status' => 'draft'],
			'cl-gone' => ['administrationId' => 'adm-korenaar', 'investmentAssetId' => 'ia-gone', 'financialYear' => 2026, 'scheme' => 'MIA', 'deductionAmount' => 5, 'status' => 'draft'],
			'cl-2025' => ['administrationId' => 'adm-korenaar', 'investmentAssetId' => 'ia-x', 'financialYear' => 2025, 'scheme' => 'KIA', 'deductionAmount' => 9, 'status' => 'draft'],
		];

		$result = $this->service()->calculateYear(administrationId: 'adm-korenaar', year: 2026);

		self::assertSame(['year' => 2026, 'totals' => ['KIA' => 111 + 896000, 'EIA' => 0, 'MIA' => 0], 'claims' => 1, 'kept' => 1], $result);
		self::assertSame(['InvesteringsaftrekClaim:cl-gone'], $this->deleted);
		self::assertSame(111, $this->store['InvesteringsaftrekClaim']['cl-oven']['deductionAmount']);
		self::assertSame([896000, 'draft'], [$this->store['InvesteringsaftrekClaim']['cl-van']['deductionAmount'], $this->store['InvesteringsaftrekClaim']['cl-van']['status']]);
		self::assertSame(9, $this->store['InvesteringsaftrekClaim']['cl-2025']['deductionAmount']);
	}//end testARecalculationKeepsFiledClaimsAndReplacesDrafts()

	/**
	 * A year without KIA tiers is refused and named; tiers in the register
	 * are used before the shipped ones.
	 *
	 * @return void
	 */
	public function testAYearWithoutTablesIsRefusedAndRegisterTiersComeFirst(): void {
		try {
			$this->service()->calculateYear(administrationId: 'adm-korenaar', year: 2027);
			self::fail('2027 was calculated without tiers.');
		} catch (InvalidArgumentException $refused) {
			self::assertSame('No KIA tiers are loaded for 2027.', $refused->getMessage());
		}

		self::assertSame([], $this->store);

		$this->store['KIATier'] = [
			't2' => ['tier' => 2, 'yearNumber' => 2027, 'from' => 100, 'tot' => null, 'percentage' => 10, 'fixedAmount' => null],
			't1' => ['tier' => 1, 'yearNumber' => 2027, 'from' => 0, 'tot' => 100, 'percentage' => 0, 'fixedAmount' => 0],
		];
		$this->store['InvestmentAsset'] = ['ia-1' => ['administrationId' => 'adm-korenaar', 'fixedAssetId' => 'fa-1', 'acquisitionValue' => 500000, 'acquisitionDate' => '2027-01-10', 'kiaEligible' => true]];
		self::assertSame(['KIA' => 50000, 'EIA' => 0, 'MIA' => 0], $this->service()->calculateYear(administrationId: 'adm-korenaar', year: 2027)['totals']);
		self::assertSame([1, 2], array_column((new InvestmentDeductionTables(binding: $this->bindingOnly()))->kiaTiers(year: 2027), 'tier'));
	}//end testAYearWithoutTablesIsRefusedAndRegisterTiersComeFirst()

	/**
	 * EIA and MIA do not combine on one asset: the larger claim is kept.
	 *
	 * @return void
	 */
	public function testAnAssetOnBothListsClaimsTheLargerOfEiaAndMia(): void {
		$this->store['MilieulijstCode'] = ['m1' => ['code' => 'M-45', 'yearNumber' => 2026, 'miaPercentage' => 45, 'deelpercentage' => 100, 'vamilPermitted' => false]];
		$this->store['InvestmentAsset'] = [
			'ia-1' => ['administrationId' => 'adm-korenaar', 'fixedAssetId' => 'fa-1', 'acquisitionValue' => 1000000, 'acquisitionDate' => '2026-05-01', 'energyListCode' => '210000', 'environmentListCode' => 'M-45', 'eiaEligible' => true, 'miaEligible' => true],
		];

		$result = $this->service()->calculateYear(administrationId: 'adm-korenaar', year: 2026);

		self::assertSame(['KIA' => 0, 'EIA' => 0, 'MIA' => 450000], $result['totals']);
		self::assertSame(['MIA'], array_column($this->store['InvesteringsaftrekClaim'], 'scheme'));
	}//end testAnAssetOnBothListsClaimsTheLargerOfEiaAndMia()

	/**
	 * A binding over the current store, for the tables alone.
	 *
	 * @return RegisterBinding
	 */
	private function bindingOnly(): RegisterBinding {
		$this->service();
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('findAll')->willReturnCallback(fn (): array => array_values($this->store['KIATier']));
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		return new RegisterBinding(objectService: $objects, appConfig: $appConfig);
	}//end bindingOnly()
}//end class
