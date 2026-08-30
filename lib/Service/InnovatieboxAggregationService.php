<?php

/**
 * Innovatiebox Aggregation Service
 *
 * Materialises the per-asset Vpb innovatiebox-sectie roll-up (REQ-IBA-006,
 * REQ-IBA-007) from the five innovatiebox registers using the real OpenRegister
 * ObjectService API (find/findAll, ADR-022). For one administration + boekjaar
 * it walks every valid QualifyingAsset, joins its IBProfitAttribution and the
 * applicable CarryForwardLoss, applies the loss-offset ordering (open loss first
 * at the full tariff, residual at 0.09 x nexus), and emits one row per asset
 * plus the grand total that contributes to Vpb-aangifte regel 23.
 *
 * Assets whose status is not 'valid' are excluded (REQ-IBA-001). Reads are
 * scoped to the supplied administration, which the controller resolves from the
 * authenticated context — never a client trust boundary (REQ-IBA-008).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Computes the per-asset innovatiebox Vpb roll-up (REQ-IBA-006).
 *
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
 */
class InnovatieboxAggregationService {
	/**
	 * Construct the aggregation service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param CarryForwardLossService $lossService Loss-offset arithmetic helper.
	 * @param QualifyingAssetValidator $validator Toegangsticket validator — re-runs the
	 *                                            S&O / octrooi / combinatie rules at compute
	 *                                            time to catch assets whose persisted
	 *                                            status='valid' is now stale (REQ-IBA-001).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly CarryForwardLossService $lossService,
		private readonly QualifyingAssetValidator $validator,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Build the innovatiebox-administratie aggregation for one administration + year.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-IBA-008).
	 * @param int $financialYear Fiscal year.
	 *
	 * @return array{data: array<int,array<string,mixed>>, total: int, totals: array<string,float>}
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
	 */
	public function aggregate(string $administrationId, int $financialYear): array {
		$assets = $this->fetchValidAssets(administrationId: $administrationId);
		$attributions = $this->indexBy(
			rows: $this->fetchAttributions(administrationId: $administrationId, financialYear: $financialYear),
			key: 'qualifying_asset_id'
		);
		$openLosses = $this->indexBy(
			rows: $this->fetchOpenLosses(administrationId: $administrationId),
			key: 'qualifying_asset_id'
		);

		$rows = [];
		$grandVpb = 0.0;
		$grandBenefit = 0.0;
		foreach ($assets as $asset) {
			$assetId = $this->assetId(asset: $asset);
			if ($assetId === '' || isset($attributions[$assetId]) === false) {
				continue;
			}

			$row = $this->buildRow(asset: $asset, attribution: $attributions[$assetId], loss: ($openLosses[$assetId] ?? null));
			$rows[] = $row;
			$grandVpb += (float)$row['vpb_on_innovation_share'];
			$grandBenefit += (float)$row['benefit_innovation_box'];
		}

		return [
			'data' => $rows,
			'total' => count($rows),
			'totals' => [
				'vpb_regel_23' => round($grandVpb, 2),
				'benefit_innovation_box' => round($grandBenefit, 2),
			],
		];

	}//end aggregate()

	/**
	 * Build one per-asset aggregation row, applying the loss-offset ordering.
	 *
	 * @param array<string,mixed> $asset The valid QualifyingAsset.
	 * @param array<string,mixed> $attribution The asset's IBProfitAttribution for the year.
	 * @param array<string,mixed>|null $loss The asset's open CarryForwardLoss, if any.
	 *
	 * @return array<string,mixed> The aggregation row.
	 */
	private function buildRow(array $asset, array $attribution, ?array $loss): array {
		$name = (string)($asset['name'] ?? '');
		$forNexus = (float)($attribution['qualifying_profit_for_nexus'] ?? 0);
		$nexus = (float)($attribution['nexus_fraction_applied'] ?? 1.0);
		$afterNexus = (float)($attribution['qualifying_profit_after_nexus'] ?? 0);
		$rate = (float)($attribution['effective_rate'] ?? CarryForwardLossService::INNOVATIEBOX_TARIFF);
		$vpb = (float)($attribution['vpb_on_innovation_share'] ?? round(($afterNexus * $rate), 2));
		$benefit = (float)($attribution['benefit_innovation_box'] ?? 0);
		$lossOffset = null;

		// REQ-IBA-007: an open loss is recovered first at the full tariff before
		// the innovatiebox 0.09 applies to the residual; this raises the benefit.
		if ($loss !== null && (float)($loss['balance_open'] ?? 0) > 0.0) {
			$offset = $this->lossService->offsetLossAgainstProfit(
				openLoss: (float)$loss['balance_open'],
				currentYearProfit: $afterNexus,
				nexusBreak: $nexus
			);

			$vpb = $offset['residualProfitAt9Pct'];
			$benefit = round(($offset['totalBenefit']), 2);
			$lossOffset = [
				'loss_offset' => $offset['lossOffset'],
				'benefit_full' => $offset['lossOffsetAtFullRate'],
				'residual' => $offset['residualProfit'],
				'benefit_9pct' => $offset['residualProfitAt9Pct'],
				'balance_after' => $offset['balanceAfter'],
			];
		}

		return [
			'qualifying_asset_id' => $this->assetId(asset: $asset),
			'name' => $name,
			'method' => (string)($attribution['method'] ?? ''),
			'qualifying_profit_for_nexus' => round($forNexus, 2),
			'nexus_fraction_applied' => round($nexus, 4),
			'qualifying_profit_after_nexus' => round($afterNexus, 2),
			'effective_rate' => $rate,
			'vpb_on_innovation_share' => round($vpb, 2),
			'benefit_innovation_box' => round($benefit, 2),
			'loss_carry_forward' => $lossOffset,
		];

	}//end buildRow()

	/**
	 * Resolve the object id of an OpenRegister record (top-level or @self.id).
	 *
	 * @param array<string,mixed> $asset The record.
	 *
	 * @return string The id, or '' when absent.
	 */
	private function assetId(array $asset): string {
		$id = ($asset['id'] ?? ($asset['@self']['id'] ?? ($asset['@self']['slug'] ?? null)));
		if ($id === null) {
			return '';
		}

		return (string)$id;
	}//end assetId()

	/**
	 * Index a list of records by a foreign-key field, keeping the first per key.
	 *
	 * @param array<int,array<string,mixed>> $rows Records to index.
	 * @param string $key Field to key on.
	 *
	 * @return array<string,array<string,mixed>> Indexed records.
	 */
	private function indexBy(array $rows, string $key): array {
		$indexed = [];
		foreach ($rows as $row) {
			$value = (string)($row[$key] ?? '');
			if ($value !== '' && isset($indexed[$value]) === false) {
				$indexed[$value] = $row;
			}
		}

		return $indexed;
	}//end indexBy()

	/**
	 * Fetch valid QualifyingAsset rows for an administration (REQ-IBA-001).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>> Valid assets.
	 */
	private function fetchValidAssets(string $administrationId): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('QualifyingAsset')
			->findAll(['filters' => ['administrationId' => $administrationId, 'status' => 'valid']]);

		// Re-run the toegangsticket validator at compute time so an asset whose
		// persisted status='valid' is now stale (e.g. S&O cert expired since the
		// last save) is excluded from the roll-up (REQ-IBA-001).
		$current = [];
		foreach ($rows as $asset) {
			if (is_array($asset) === false) {
				continue;
			}

			$result = $this->validator->validateAccessTicket(asset: $asset);
			if (($result['valid'] ?? false) === true) {
				$current[] = $asset;
			}
		}

		return $current;
	}//end fetchValidAssets()

	/**
	 * Fetch IBProfitAttribution rows for an administration + year.
	 *
	 * @param string $administrationId Administration scope.
	 * @param int $financialYear Fiscal year.
	 *
	 * @return array<int,array<string,mixed>> Attribution rows.
	 */
	private function fetchAttributions(string $administrationId, int $financialYear): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('IBProfitAttribution')
			->findAll(['filters' => ['administrationId' => $administrationId, 'financialYear' => $financialYear]]);

		return $rows;
	}//end fetchAttributions()

	/**
	 * Fetch open CarryForwardLoss rows for an administration.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>> Open loss rows.
	 */
	private function fetchOpenLosses(string $administrationId): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('CarryForwardLoss')
			->findAll(['filters' => ['administrationId' => $administrationId, 'status' => 'open']]);

		return $rows;
	}//end fetchOpenLosses()

	/**
	 * Resolve the configured OpenRegister register slug, defaulting to 'shillinq'.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
