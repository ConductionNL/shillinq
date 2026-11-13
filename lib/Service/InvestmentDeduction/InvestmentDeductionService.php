<?php

/**
 * Shillinq Investment Deduction Service
 *
 * Records whether an active fixed asset qualifies for KIA, EIA, MIA and Vamil
 * and why, and works out a year's deduction per scheme as draft claims.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\InvestmentDeduction
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

namespace OCA\Shillinq\Service\InvestmentDeduction;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Shillinq\Guard\AssetEligibilityCheck;
use OCA\Shillinq\Guard\InvesteringsaftrekEligibilityGuard;
use OCA\Shillinq\Guard\KiaSchalenLookup;
use OCA\Shillinq\Service\RegisterBinding;

/**
 * Eligibility per asset and the yearly claims.
 *
 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md
 */
class InvestmentDeductionService {

	/**
	 * The EIA rate applied to the qualifying part of an investment.
	 */
	public const EIA_PERCENTAGE = 40;

	/**
	 * The flag field of each scheme on an InvestmentAsset.
	 *
	 * @var array<string, string>
	 */
	private const FLAGS = ['kia' => 'kiaEligible', 'eia' => 'eiaEligible', 'mia' => 'miaEligible', 'vamil' => 'vamilEligible'];

	/**
	 * Constructor.
	 *
	 * @param RegisterBinding                    $binding     The shillinq register.
	 * @param InvestmentDeductionTables          $tables      The year's tables.
	 * @param InvesteringsaftrekEligibilityGuard $eligibility The per-asset classification.
	 * @param KiaSchalenLookup                   $kia         The KIA tier bands.
	 */
	public function __construct(
		private readonly RegisterBinding $binding,
		private readonly InvestmentDeductionTables $tables,
		private readonly InvesteringsaftrekEligibilityGuard $eligibility,
		private readonly KiaSchalenLookup $kia,
	) {
	}//end __construct()

	/**
	 * Whether a fixed asset is active, by either lifecycle field it may carry.
	 *
	 * @param array<string, mixed> $fixedAsset The fixed asset.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-an-activated-fixed-asset-gets-its-deduction-eligibility-req-idc-001
	 */
	public static function isActive(array $fixedAsset): bool {
		return (($fixedAsset['lifecycleState'] ?? null) === 'active' || ($fixedAsset['status'] ?? null) === 'active');
	}//end isActive()

	/**
	 * Create or update the InvestmentAsset of an active fixed asset with its
	 * flags and reasons. A bookkeeper's override and list codes are kept.
	 *
	 * @param string               $fixedAssetId The fixed asset's id.
	 * @param array<string, mixed> $fixedAsset   The fixed asset.
	 *
	 * @return array<string, mixed>|null The InvestmentAsset, or null when the asset is not active or has no administration.
	 *
	 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-an-activated-fixed-asset-gets-its-deduction-eligibility-req-idc-001
	 */
	public function recordEligibility(string $fixedAssetId, array $fixedAsset): ?array {
		$administrationId = trim((string)($fixedAsset['administrationId'] ?? ''));
		if ($fixedAssetId === '' || $administrationId === '' || self::isActive(fixedAsset: $fixedAsset) === false) {
			return null;
		}

		$filters = ['administrationId' => $administrationId, 'fixedAssetId' => $fixedAssetId];
		$existing = ($this->rowsOf(schema: 'InvestmentAsset', filters: $filters)[0] ?? []);
		$acquisitionDate = (string)($fixedAsset['acquisitionDate'] ?? ($fixedAsset['purchaseDate'] ?? ($existing['acquisitionDate'] ?? '')));
		$asset = [
			'administrationId' => $administrationId,
			'fixedAssetId' => $fixedAssetId,
			'description' => (string)($fixedAsset['name'] ?? ($fixedAsset['description'] ?? ($existing['description'] ?? $fixedAssetId))),
			'acquisitionDate' => $acquisitionDate,
			'acquisitionValue' => self::cents(amount: ($fixedAsset['acquisitionCost'] ?? ($fixedAsset['purchaseCost'] ?? 0))),
			'currency' => (string)($fixedAsset['currency'] ?? ($existing['currency'] ?? 'EUR')),
		] + $existing;

		return $this->save(schema: 'InvestmentAsset', row: $this->classified(asset: $asset));
	}//end recordEligibility()

	/**
	 * Work out a year's deduction per scheme and write it as draft claims.
	 *
	 * Active fixed assets acquired in the year that have no InvestmentAsset
	 * yet get one first. A claim already used in a tax return is left as it is.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $year             The year.
	 *
	 * @return array{year: int, totals: array<string, int>, claims: int, kept: int}
	 *
	 * @throws InvalidArgumentException When no KIA tiers are loaded for the year.
	 *
	 * @spec openspec/changes/tax-investment-deduction-claims/specs/bookkeeping-investeringsaftrek/spec.md#requirement-a-years-deduction-is-calculated-per-scheme-req-idc-002
	 */
	public function calculateYear(string $administrationId, int $year): array {
		$tiers = $this->tables->kiaTiers(year: $year);
		if ($tiers === []) {
			throw new InvalidArgumentException('No KIA tiers are loaded for ' . $year . '.');
		}

		foreach ($this->rowsOf(schema: 'FixedAsset', filters: ['administrationId' => $administrationId]) as $fixedAsset) {
			if (self::yearOf(date: (string)($fixedAsset['acquisitionDate'] ?? ($fixedAsset['purchaseDate'] ?? ''))) === $year) {
				$this->recordEligibility(fixedAssetId: self::idOf(row: $fixedAsset), fixedAsset: $fixedAsset);
			}
		}

		$assets = array_values(
			array_filter(
				$this->rowsOf(schema: 'InvestmentAsset', filters: ['administrationId' => $administrationId]),
				static fn (array $asset): bool => self::yearOf(date: (string)($asset['acquisitionDate'] ?? '')) === $year
			)
		);

		$wanted = array_merge($this->kiaClaims(assets: $assets, tiers: $tiers), $this->listClaims(assets: $assets, year: $year));

		return $this->writeClaims(administrationId: $administrationId, year: $year, wanted: $wanted);
	}//end calculateYear()

	/**
	 * The KIA claims: the year's deduction over the eligible total, shared
	 * over the assets by value.
	 *
	 * @param array<int, array<string, mixed>> $assets The year's InvestmentAssets.
	 * @param array<int, array<string, mixed>> $tiers  The year's KIA tiers.
	 *
	 * @return array<string, array<string, mixed>> Claims keyed "assetId|scheme".
	 */
	private function kiaClaims(array $assets, array $tiers): array {
		$eligible = array_values(array_filter($assets, static fn (array $asset): bool => ($asset['kiaEligible'] ?? false) === true));
		$total = array_sum(array_map(static fn (array $asset): int => (int)($asset['acquisitionValue'] ?? 0), $eligible));
		$deduction = $this->kia->computeAftrek(tiers: $tiers, jaartotaal: $total);

		$claims = [];
		$left = $deduction;
		foreach ($eligible as $index => $asset) {
			$value = (int)($asset['acquisitionValue'] ?? 0);
			$share = $left;
			if ($index < count($eligible) - 1 && $total > 0) {
				$share = (int)round($deduction * $value / $total);
			}

			$left -= $share;
			$claims[self::idOf(row: $asset) . '|KIA'] = ['basis' => $value, 'percentage' => null, 'deductionAmount' => $share];
		}

		return $claims;
	}//end kiaClaims()

	/**
	 * The EIA and MIA claims at their list's percentage, over the part of the
	 * value the list code counts. EIA and MIA do not combine on one asset
	 * (art. 3.42 lid 7): an asset eligible for both claims the larger.
	 *
	 * @param array<int, array<string, mixed>> $assets The year's InvestmentAssets.
	 * @param int                              $year   The year.
	 *
	 * @return array<string, array<string, mixed>> Claims keyed "assetId|scheme".
	 */
	private function listClaims(array $assets, int $year): array {
		$claims = [];
		foreach ($assets as $asset) {
			$value = (int)($asset['acquisitionValue'] ?? 0);
			$options = [];
			$energy = $this->tables->energyCode(code: (string)($asset['energyListCode'] ?? ''), year: $year);
			if (($asset['eiaEligible'] ?? false) === true && $energy !== null) {
				$options['EIA'] = self::listClaim(value: $value, share: (int)($energy['deelpercentage'] ?? 100), percentage: self::EIA_PERCENTAGE);
			}

			$environment = $this->tables->environmentCode(code: (string)($asset['environmentListCode'] ?? ''), year: $year);
			if (($asset['miaEligible'] ?? false) === true && $environment !== null) {
				$options['MIA'] = self::listClaim(
					value: $value,
					share: (int)($environment['deelpercentage'] ?? 100),
					percentage: (int)($environment['miaPercentage'] ?? 0)
				);
			}

			if ($this->eligibility->validateCumulation(schemes: array_keys($options))['allowed'] === false) {
				$keep = 'EIA';
				if ($options['MIA']['deductionAmount'] > $options['EIA']['deductionAmount']) {
					$keep = 'MIA';
				}

				$options = [$keep => $options[$keep]];
			}

			foreach ($options as $scheme => $claim) {
				$claims[self::idOf(row: $asset) . '|' . $scheme] = $claim;
			}
		}//end foreach

		return $claims;
	}//end listClaims()

	/**
	 * Write the wanted claims as drafts, remove the year's drafts no longer
	 * wanted, and leave every claim used in a tax return as it is.
	 *
	 * @param string                               $administrationId The administration.
	 * @param int                                  $year             The year.
	 * @param array<string, array<string, mixed>> $wanted           Claims keyed "assetId|scheme".
	 *
	 * @return array{year: int, totals: array<string, int>, claims: int, kept: int}
	 */
	private function writeClaims(string $administrationId, int $year, array $wanted): array {
		$existing = [];
		foreach ($this->rowsOf(schema: 'InvesteringsaftrekClaim', filters: ['administrationId' => $administrationId, 'financialYear' => $year]) as $claim) {
			$existing[(string)($claim['investmentAssetId'] ?? '') . '|' . (string)($claim['scheme'] ?? '')] = $claim;
		}

		$totals = ['KIA' => 0, 'EIA' => 0, 'MIA' => 0];
		$written = 0;
		$kept = 0;
		foreach ($existing as $key => $claim) {
			if (($claim['submittedInTaxReturn'] ?? false) === true) {
				$kept++;
				$totals[(string)$claim['scheme']] = (($totals[(string)$claim['scheme']] ?? 0) + (int)($claim['deductionAmount'] ?? 0));
				unset($wanted[$key]);
				continue;
			}

			if (isset($wanted[$key]) === false) {
				$this->binding->schema(schema: 'InvesteringsaftrekClaim')->deleteObject(uuid: self::idOf(row: $claim));
			}
		}

		foreach ($wanted as $key => $values) {
			[$assetId, $scheme] = explode('|', $key, 2);
			$this->save(
				schema: 'InvesteringsaftrekClaim',
				row: [
					'administrationId' => $administrationId,
					'investmentAssetId' => $assetId,
					'financialYear' => $year,
					'scheme' => $scheme,
					'status' => 'draft',
					'submittedInTaxReturn' => false,
				] + $values + ($existing[$key] ?? [])
			);
			$totals[$scheme] += (int)$values['deductionAmount'];
			$written++;
		}

		return ['year' => $year, 'totals' => $totals, 'claims' => $written, 'kept' => $kept];
	}//end writeClaims()

	/**
	 * The asset with its flags, reasons and notification deadline; a
	 * bookkeeper's override wins over the computed flag of its scheme.
	 *
	 * @param array<string, mixed> $asset The InvestmentAsset.
	 *
	 * @return array<string, mixed>
	 */
	private function classified(array $asset): array {
		$year = self::yearOf(date: (string)($asset['assignmentAwardDate'] ?? '')) ?? self::yearOf(date: (string)$asset['acquisitionDate']) ?? 0;
		$environment = $this->tables->environmentCode(code: (string)($asset['environmentListCode'] ?? ''), year: $year);
		$result = $this->eligibility->classify(
			check: new AssetEligibilityCheck(
				acquisitionValue: (int)$asset['acquisitionValue'],
				energyListHit: $this->tables->energyCode(code: (string)($asset['energyListCode'] ?? ''), year: $year) !== null,
				environmentListHit: $environment !== null,
				vamilPermitted: ($environment['vamilPermitted'] ?? false) === true,
				kiaExcluded: false,
			)
		);

		$override = (array)($asset['eligibilityOverride'] ?? []);
		$overridden = strtolower((string)($override['scheme'] ?? ''));
		$reasons = $result['rationale'];
		foreach (self::FLAGS as $scheme => $field) {
			$asset[$field] = $result[$scheme];
			if ($scheme === $overridden && is_bool($override['value'] ?? null) === true) {
				$asset[$field] = $override['value'];
				$reasons[$scheme] = strtoupper($scheme) . ': ' . trim((string)($override['rationale'] ?? ''));
			}
		}

		$asset['eligibilityReasons'] = $reasons;
		$asset['rvoReportDeadline'] = null;
		$awarded = (string)($asset['assignmentAwardDate'] ?? '');
		if (($asset['eiaEligible'] === true || $asset['miaEligible'] === true) && self::yearOf(date: $awarded) !== null) {
			$asset['rvoReportDeadline'] = (new DateTimeImmutable($awarded))->modify('+3 months')->format('Y-m-d');
		}

		return $asset;
	}//end classified()

	/**
	 * An EIA or MIA claim over the part of the value its list code counts.
	 *
	 * @param int $value      The acquisition value in cents.
	 * @param int $share      The list code's counted part, in percent.
	 * @param int $percentage The deduction rate, in percent.
	 *
	 * @return array{basis: int, percentage: int, deductionAmount: int}
	 */
	private static function listClaim(int $value, int $share, int $percentage): array {
		$basis = (int)round($value * $share / 100);

		return ['basis' => $basis, 'percentage' => $percentage, 'deductionAmount' => (int)round($basis * $percentage / 100)];
	}//end listClaim()

	/**
	 * An amount in euros as whole cents.
	 *
	 * @param mixed $amount The amount.
	 *
	 * @return int
	 */
	private static function cents(mixed $amount): int {
		if (is_numeric($amount) === false) {
			return 0;
		}

		return (int)round((float)$amount * 100);
	}//end cents()

	/**
	 * The year of a Y-m-d date, or null.
	 *
	 * @param string $date The date.
	 *
	 * @return int|null
	 */
	private static function yearOf(string $date): ?int {
		if (preg_match('/^(\d{4})-\d{2}-\d{2}/', $date, $match) !== 1) {
			return null;
		}

		return (int)$match[1];
	}//end yearOf()

	/**
	 * The rows of a schema matching the filters, as arrays.
	 *
	 * @param string               $schema  The schema.
	 * @param array<string, mixed> $filters Property filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsOf(string $schema, array $filters): array {
		$rows = [];
		foreach ($this->binding->schema(schema: $schema)->findAll(['filters' => $filters]) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * Save a row and return it as stored; with an id it updates that record.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return array<string, mixed>
	 */
	private function save(string $schema, array $row): array {
		$id = self::idOf(row: $row);
		unset($row['@self'], $row['id']);
		$uuid = null;
		if ($id !== '') {
			$uuid = $id;
		}

		return (array)$this->binding->schema(schema: $schema)->saveObject(object: $row, uuid: $uuid)->jsonSerialize();
	}//end save()

	/**
	 * A row's id, top level or under `@self`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private static function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()
}//end class
