<?php

/**
 * Flux Analysis Service
 *
 * Tier-2 post-soft-close variance analysis (REQ-CLS-005..007). Computes per-GL
 * variance vs the chosen comparison basis (budget | forecast | prior-period |
 * prior-year), classifies materiality per the configured MaterialityPolicy,
 * runs the rule-based driver decomposition (volume | price | mix | fx |
 * one-off), and routes items to owner-explanation when auto-coverage is below
 * the 80% threshold (REQ-CLS-006). Each step is a pure computation; the
 * service only sequences them and persists the FluxRun + FluxItem +
 * FluxAttribution rows.
 *
 * The compute* helpers are exposed `public` (with pure-function semantics) so
 * unit tests (Task 30) can exercise the calculations without setting up the
 * ObjectService.
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
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Variance calculation + driver decomposition for continuous-close (REQ-CLS-005..007).
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Pre-existing debt (issue
 *     #506): changing this signature would ripple to callers; deferred.
 * @SuppressWarnings(PHPMD.ElseExpression)      Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class FluxService {
	/**
	 * Auto-explanation coverage threshold (REQ-CLS-006).
	 *
	 * @var float
	 */
	public const AUTO_EXPLANATION_COVERAGE_THRESHOLD = 0.80;

	/**
	 * Owner SLA in hours (REQ-CLS-006).
	 *
	 * @var int
	 */
	public const OWNER_SLA_HOURS = 24;

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Compute the signed variance in cents (actual - basis).
	 *
	 * @param int $actualCents Actual amount in cents.
	 * @param int $basisCents Comparison basis amount in cents (budget/PY/PP/forecast).
	 *
	 * @return int Signed variance in cents.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function computeVarianceCents(int $actualCents, int $basisCents): int {
		return ($actualCents - $basisCents);
	}//end computeVarianceCents()

	/**
	 * Compute the percentage variance (variance / basis); 0.0 when basis is zero.
	 *
	 * @param int $varianceCents Signed variance in cents.
	 * @param int $basisCents Comparison basis in cents.
	 *
	 * @return float The percentage variance (e.g. 0.15 for +15%).
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function computePercentageVariance(int $varianceCents, int $basisCents): float {
		if ($basisCents === 0) {
			return 0.0;
		}

		return ($varianceCents / $basisCents);
	}//end computePercentageVariance()

	/**
	 * Classify a variance against a MaterialityPolicy (REQ-CLS-005).
	 *
	 * Materiality threshold = max(absoluteThresholdCents, percentageThreshold * basisCents).
	 * Variances <= threshold are 'immaterial'. Variances > threshold are 'material'.
	 * Variances > 10× threshold are 'highly-material'.
	 *
	 * @param int $varianceCents Signed variance in cents.
	 * @param int $basisCents Basis amount in cents.
	 * @param array<string,mixed> $policy The MaterialityPolicy record.
	 * @param string $accountGroup Account group ('operational' | 'cash' | 'tax' | 'revenue').
	 *
	 * @return string One of 'immaterial', 'material', 'highly-material'.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function classifyMateriality(int $varianceCents, int $basisCents, array $policy, string $accountGroup = 'operational'): string {
		$absoluteThreshold = (int)($policy['absoluteThresholdCents'] ?? 0);
		$percentageThreshold = (float)($policy['percentageThreshold'] ?? 0.0);

		$specials = (array)($policy['specialRules'] ?? []);
		if (isset($specials[$accountGroup]) === true && is_array($specials[$accountGroup]) === true) {
			$absoluteThreshold = (int)($specials[$accountGroup]['absoluteThresholdCents'] ?? $absoluteThreshold);
			$percentageThreshold = (float)($specials[$accountGroup]['percentageThreshold'] ?? $percentageThreshold);
		}

		$absoluteVariance = abs($varianceCents);
		$percentageThresholdCents = (int)round(abs($basisCents) * $percentageThreshold);
		$threshold = max($absoluteThreshold, $percentageThresholdCents);

		if ($threshold <= 0) {
			// No threshold defined — treat anything non-zero as material to surface for review.
			if ($absoluteVariance === 0) {
				return 'immaterial';
			}

			return 'material';
		}

		if ($absoluteVariance <= $threshold) {
			return 'immaterial';
		}

		if ($absoluteVariance > ($threshold * 10)) {
			return 'highly-material';
		}

		return 'material';
	}//end classifyMateriality()

	/**
	 * Sum every driver contribution to the total reconstructed variance (REQ-CLS-006).
	 *
	 * @param array<int,array<string,mixed>> $attributions FluxAttribution rows (each {driver, contributionCents}).
	 *
	 * @return int The summed contribution in cents.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function sumAttributionsCents(array $attributions): int {
		$total = 0;
		foreach ($attributions as $attribution) {
			$total += (int)($attribution['contributionCents'] ?? 0);
		}

		return $total;
	}//end sumAttributionsCents()

	/**
	 * Compute the auto-explanation coverage fraction (REQ-CLS-006).
	 *
	 * @param array<int,array<string,mixed>> $attributions Driver attributions.
	 * @param int $varianceCents Total variance in cents.
	 *
	 * @return float Coverage in [0, 1]; 0.0 when variance is zero.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function computeAutoExplanationCoverage(array $attributions, int $varianceCents): float {
		if ($varianceCents === 0) {
			return 0.0;
		}

		$summed = $this->sumAttributionsCents(attributions: $attributions);
		$coverage = abs($summed) / abs($varianceCents);
		if ($coverage > 1.0) {
			$coverage = 1.0;
		}

		return $coverage;
	}//end computeAutoExplanationCoverage()

	/**
	 * Decide flux-item status from auto-coverage + materiality (REQ-CLS-006).
	 *
	 * @param string $materiality Classification ('immaterial', 'material', 'highly-material').
	 * @param float $coverage Auto-explanation coverage (0..1).
	 *
	 * @return string One of 'open', 'auto-explained', 'escalated'.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function decideStatus(string $materiality, float $coverage): string {
		if ($materiality === 'immaterial') {
			return 'accepted';
		}

		if ($materiality === 'highly-material') {
			// Always escalate highly-material variances even when auto-explained.
			return 'escalated';
		}

		if ($coverage >= self::AUTO_EXPLANATION_COVERAGE_THRESHOLD) {
			return 'auto-explained';
		}

		return 'escalated';
	}//end decideStatus()

	/**
	 * Run a flux analysis for an administratie + period (REQ-CLS-005).
	 *
	 * @param array<string,mixed> $inputs Run inputs:
	 *                                    {
	 *                                    administrationId, periodId, scope, comparisonBasis,
	 *                                    accounts: [{glAccountNumber, accountGroupCode, actualCents, basisCents, attributions}],
	 *                                    materialityPolicy: {...},
	 *                                    runTimestamp?: DateTimeImmutable
	 *                                    }
	 *
	 * @return array{
	 *   fluxRunId: string,
	 *   itemCount: int,
	 *   materialCount: int,
	 *   autoExplainedCount: int,
	 *   escalatedCount: int,
	 *   totalVarianceCents: int,
	 *   items: array<int, array<string, mixed>>
	 * } Run summary.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function run(array $inputs): array {
		$administrationId = (string)($inputs['administrationId'] ?? '');
		$periodId = (string)($inputs['periodId'] ?? '');
		$scope = (string)($inputs['scope'] ?? 'administration');
		$comparisonBasis = (string)($inputs['comparisonBasis'] ?? 'budget');
		$accounts = (array)($inputs['accounts'] ?? []);
		$policy = (array)($inputs['materialityPolicy'] ?? []);
		$runTimestamp = ($inputs['runTimestamp'] ?? new DateTimeImmutable());
		if (($runTimestamp instanceof DateTimeImmutable) === false) {
			$runTimestamp = new DateTimeImmutable();
		}

		$fluxRunId = $this->generateRunId(administrationId: $administrationId, periodId: $periodId, asOf: $runTimestamp);
		$items = [];
		$material = 0;
		$auto = 0;
		$escalated = 0;
		$totalVar = 0;

		foreach ($accounts as $account) {
			$glAccount = (string)($account['glAccountNumber'] ?? '');
			$group = (string)($account['accountGroupCode'] ?? 'operational');
			$actualCents = (int)($account['actualCents'] ?? 0);
			$basisCents = (int)($account['basisCents'] ?? 0);
			$attribs = (array)($account['attributions'] ?? []);

			$variance = $this->computeVarianceCents(actualCents: $actualCents, basisCents: $basisCents);
			$percentage = $this->computePercentageVariance(varianceCents: $variance, basisCents: $basisCents);
			$materiality = $this->classifyMateriality(
				varianceCents: $variance,
				basisCents: $basisCents,
				policy: $policy,
				accountGroup: $group
			);
			$coverage = $this->computeAutoExplanationCoverage(attributions: $attribs, varianceCents: $variance);
			$status = $this->decideStatus(materiality: $materiality, coverage: $coverage);
			$totalVar += abs($variance);

			if ($materiality !== 'immaterial') {
				$material++;
			}

			if ($status === 'auto-explained') {
				$auto++;
			} elseif ($status === 'escalated') {
				$escalated++;
			}

			$escalationSla = null;
			$escalatedTo = '';
			if ($status === 'escalated') {
				$escalationSla = $runTimestamp->modify('+' . self::OWNER_SLA_HOURS . ' hours')
					->format(DateTimeInterface::ATOM);
				$escalatedTo = $this->ownerForAccount(group: $group);
			}

			$items[] = [
				'fluxRunId' => $fluxRunId,
				'glAccountNumber' => $glAccount,
				'budgetCents' => $basisCents,
				'actualCents' => $actualCents,
				'varianceCents' => $variance,
				'percentageVariance' => $percentage,
				'materialityClassification' => $materiality,
				'autoExplanation' => $this->formatAutoExplanation(attributions: $attribs),
				'autoExplanationCoverage' => $coverage,
				'ownerExplanation' => '',
				'status' => $status,
				'ownerEscalationSLA' => $escalationSla,
				'ownerEscalatedTo' => $escalatedTo,
				'attributions' => $attribs,
			];
		}//end foreach

		$this->persistRun(
			fluxRunId: $fluxRunId,
			administrationId: $administrationId,
			periodId: $periodId,
			scope: $scope,
			comparisonBasis: $comparisonBasis,
			policy: $policy,
			runTimestamp: $runTimestamp,
			items: $items,
			summary: [
				'materialCount' => $material,
				'autoExplainedCount' => $auto,
				'escalatedCount' => $escalated,
				'totalVarianceCents' => $totalVar,
			]
		);

		return [
			'fluxRunId' => $fluxRunId,
			'itemCount' => count($items),
			'materialCount' => $material,
			'autoExplainedCount' => $auto,
			'escalatedCount' => $escalated,
			'totalVarianceCents' => $totalVar,
			'items' => $items,
		];

	}//end run()

	/**
	 * Aggregate run items into a flux narrative ordered by absolute variance (REQ-CLS-007).
	 *
	 * @param array<int,array<string,mixed>> $items Flux items (typically from run()['items']).
	 * @param string $periodId The yyyy-mm period.
	 *
	 * @return array{
	 *   periodId: string,
	 *   itemCount: int,
	 *   explainedCount: int,
	 *   unexplainedCount: int,
	 *   totalAdverseCents: int,
	 *   rows: array<int,array<string,mixed>>
	 * } Narrative structure.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function buildNarrative(array $items, string $periodId): array {
		$material = array_values(
			array_filter(
				$items,
				static fn (array $item): bool => ($item['materialityClassification'] ?? 'immaterial') !== 'immaterial'
			)
		);

		usort(
			$material,
			static fn (array $a, array $b): int => abs((int)($b['varianceCents'] ?? 0)) <=> abs((int)($a['varianceCents'] ?? 0))
		);

		$explained = 0;
		$unexplained = 0;
		$adverse = 0;
		foreach ($material as $item) {
			$status = (string)($item['status'] ?? 'open');
			if (in_array($status, ['auto-explained', 'owner-explained', 'accepted'], true) === true) {
				$explained++;
			} else {
				$unexplained++;
			}

			$variance = (int)($item['varianceCents'] ?? 0);
			if ($variance > 0) {
				$adverse += $variance;
			}
		}

		return [
			'periodId' => $periodId,
			'itemCount' => count($material),
			'explainedCount' => $explained,
			'unexplainedCount' => $unexplained,
			'totalAdverseCents' => $adverse,
			'rows' => $material,
		];

	}//end buildNarrative()

	/**
	 * Render the flux narrative as Markdown (REQ-CLS-007).
	 *
	 * @param array<string,mixed> $narrative Output of buildNarrative().
	 *
	 * @return string Markdown text.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-21
	 */
	public function renderNarrativeMarkdown(array $narrative): string {
		$periodId = (string)($narrative['periodId'] ?? '');
		$rows = (array)($narrative['rows'] ?? []);

		$out = '# Flux Narrative — ' . $periodId . "\n\n";
		$out .= '| Account | Budget | Actual | Variance | Explanation |' . "\n";
		$out .= '|---|---:|---:|---:|---|' . "\n";

		foreach ($rows as $row) {
			$out .= sprintf(
				"| %s | %s | %s | %s | %s |\n",
				(string)($row['glAccountNumber'] ?? ''),
				$this->formatCents(cents: (int)($row['budgetCents'] ?? 0)),
				$this->formatCents(cents: (int)($row['actualCents'] ?? 0)),
				$this->formatCents(cents: (int)($row['varianceCents'] ?? 0), signed: true),
				$this->summaryFor(row: $row)
			);
		}

		$out .= "\n**Summary**\n\n";
		$out .= sprintf(
			"- Items reviewed: %d\n- Explained: %d\n- Unexplained: %d\n- Total adverse variance: %s\n",
			(int)($narrative['itemCount'] ?? 0),
			(int)($narrative['explainedCount'] ?? 0),
			(int)($narrative['unexplainedCount'] ?? 0),
			$this->formatCents(cents: (int)($narrative['totalAdverseCents'] ?? 0))
		);

		return $out;
	}//end renderNarrativeMarkdown()

	/**
	 * Render the flux narrative as JSON (REQ-CLS-007).
	 *
	 * @param array<string,mixed> $narrative Output of buildNarrative().
	 *
	 * @return string JSON text.
	 */
	public function renderNarrativeJson(array $narrative): string {
		return (string)json_encode($narrative, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	}//end renderNarrativeJson()

	/**
	 * Build a 1-page PDF-style plain text rendering of the narrative (REQ-CLS-007).
	 *
	 * We render to plain text rather than binary PDF to keep the implementation
	 * portable; the real PDF is wrapped by a downstream renderer. The contract
	 * is the layout (letterhead + period + signature line).
	 *
	 * @param array<string,mixed> $narrative Output of buildNarrative().
	 *
	 * @return string Plain-text PDF-layout body.
	 */
	public function renderNarrativePdfBody(array $narrative): string {
		$periodId = (string)($narrative['periodId'] ?? '');
		$out = "Shillinq Continuous Close — Flux Narrative\n";
		$out .= str_repeat('=', 70) . "\n";
		$out .= 'Period: ' . $periodId . "\n";
		$out .= 'Generated: ' . (new DateTimeImmutable())->format(DateTimeInterface::ATOM) . "\n";
		$out .= str_repeat('-', 70) . "\n\n";
		$out .= $this->renderNarrativeMarkdown(narrative: $narrative);
		$out .= "\n\n" . str_repeat('-', 70) . "\n";
		$out .= "Approved by:\n\n";
		$out .= "_____________________________________   __________________\n";
		$out .= "CFO signature                          Date\n";
		return $out;
	}//end renderNarrativePdfBody()

	/**
	 * Format an integer-cent amount with thousands separator + currency symbol.
	 *
	 * @param int $cents Amount in cents.
	 * @param bool $signed Whether to prefix '+' for positives.
	 *
	 * @return string Formatted amount (e.g. 'EUR 1,380').
	 */
	private function formatCents(int $cents, bool $signed = false): string {
		$euros = $cents / 100.0;
		$body = number_format(abs($euros), 0, '.', ',');
		$sign = '-';
		if ($cents >= 0) {
			$sign = '+';
		}

		if ($signed === true) {
			$prefix = $sign;
		} elseif ($cents < 0) {
			$prefix = '-';
		} else {
			$prefix = '';
		}

		return 'EUR ' . $prefix . $body;
	}//end formatCents()

	/**
	 * Build the auto-explanation summary string from attributions.
	 *
	 * @param array<int,array<string,mixed>> $attributions Driver attributions.
	 *
	 * @return string Comma-separated driver summaries.
	 */
	private function formatAutoExplanation(array $attributions): string {
		$parts = [];
		foreach ($attributions as $attribution) {
			$driver = (string)($attribution['driver'] ?? '');
			$contribution = (int)($attribution['contributionCents'] ?? 0);
			if ($driver === '' || $contribution === 0) {
				continue;
			}

			$parts[] = sprintf('%s %s', $driver, $this->formatCents(cents: $contribution, signed: true));
		}

		return implode(', ', $parts);
	}//end formatAutoExplanation()

	/**
	 * Pick the table-summary cell for one narrative row.
	 *
	 * @param array<string,mixed> $row One flux narrative row.
	 *
	 * @return string Summary text.
	 */
	private function summaryFor(array $row): string {
		$explanation = (string)($row['autoExplanation'] ?? '');
		$status = (string)($row['status'] ?? 'open');
		if ($status === 'escalated') {
			$prefix = '';
			if ($explanation !== '') {
				$prefix = $explanation . '; ';
			}

			return $prefix . 'escalated to ' . ((string)($row['ownerEscalatedTo'] ?? 'owner'));
		}

		return $explanation;
	}//end summaryFor()

	/**
	 * Generate a deterministic FluxRun id.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodId The yyyy-mm period.
	 * @param DateTimeImmutable $asOf Run timestamp.
	 *
	 * @return string Run id.
	 */
	private function generateRunId(string $administrationId, string $periodId, DateTimeImmutable $asOf): string {
		return sprintf('flux-%s-%s-%s', $administrationId, $periodId, $asOf->format('His'));
	}//end generateRunId()

	/**
	 * Derive an owner identifier for an account / group.
	 *
	 * @param string $group Account group.
	 *
	 * @return string Owner identifier.
	 */
	private function ownerForAccount(string $group): string {
		if ($group !== '') {
			return $group . '-owner';
		}

		return 'controller';
	}//end ownerForAccount()

	/**
	 * Persist FluxRun + FluxItem + FluxAttribution rows.
	 *
	 * @param string $fluxRunId Run id.
	 * @param string $administrationId Administration scope.
	 * @param string $periodId Period.
	 * @param string $scope Run scope.
	 * @param string $comparisonBasis Comparison basis.
	 * @param array<string,mixed> $policy Materiality policy.
	 * @param DateTimeImmutable $runTimestamp Run timestamp.
	 * @param array<int,array<string,mixed>> $items Flux items.
	 * @param array<string,int> $summary Aggregated summary.
	 *
	 * @return void
	 */
	private function persistRun(
		string $fluxRunId,
		string $administrationId,
		string $periodId,
		string $scope,
		string $comparisonBasis,
		array $policy,
		DateTimeImmutable $runTimestamp,
		array $items,
		array $summary,
	): void {
		try {
			$run = [
				'administrationId' => $administrationId,
				'periodId' => $periodId,
				'scope' => $scope,
				'comparisonBasis' => $comparisonBasis,
				'materialityAbsoluteCents' => (int)($policy['absoluteThresholdCents'] ?? 0),
				'materialityPercentage' => (float)($policy['percentageThreshold'] ?? 0.0),
				'runTimestamp' => $runTimestamp->format(DateTimeInterface::ATOM),
				'status' => 'completed',
				'resultSummary' => $summary,
			];

			$this->objectService->saveObject(
				object: $run,
				register: $this->register(),
				schema: 'FluxRun',
			);

			foreach ($items as $item) {
				$attributions = (array)($item['attributions'] ?? []);
				unset($item['attributions']);

				$this->objectService->saveObject(
					object: $item,
					register: $this->register(),
					schema: 'FluxItem',
				);

				foreach ($attributions as $attribution) {
					$attribution['fluxItemId'] = $item['glAccountNumber'] . '@' . $fluxRunId;
					$this->objectService->saveObject(
						object: $attribution,
						register: $this->register(),
						schema: 'FluxAttribution',
					);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'FluxService: persistRun failed',
				['exception' => $e->getMessage(), 'fluxRunId' => $fluxRunId]
			);
		}//end try

	}//end persistRun()

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
