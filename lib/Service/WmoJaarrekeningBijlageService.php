<?php

/**
 * WMO Jaarrekening Bijlage Service (REQ-WMO-004)
 *
 * Pure-logic generator for the WMO-bijlage on the jaarrekening: per
 * commercial activity, collects omzet (revenue GL line sum), integrale
 * kostprijs (from the definitief IKP), kostendekkingsratio, prior-year
 * comparison, ABB reference (if exempted), and manual-override counts.
 * Produces PDF-ready summary and XBRL-style XML for SBR delivery.
 *
 * Side-effect-free; the caller wires plain arrays + persists the export
 * through OR ObjectService or hands it to the bookkeeping-financial-reporting
 * jaarrekening-generator (REQ-WMO-004 wiring).
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-13
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Side-effect-free WMO jaarrekening-bijlage composer (REQ-WMO-004).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-13
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * Pre-existing debt (issue #506): early-return refactor and variable
 * renames deferred pending a dedicated pass.
 */
class WmoJaarrekeningBijlageService {
	/**
	 * Compose a WMO jaarrekening-bijlage for a fiscal year (REQ-WMO-004).
	 *
	 * @param array<string,mixed> $input Inputs (fiscalYear, administrationId, activities,
	 *                                   definitiefIkpByActivity, priorYearIkpByActivity?,
	 *                                   omzetByActivity, priorYearOmzetByActivity?,
	 *                                   abbByActivity?, manualOverridesByActivity?).
	 *
	 * @return array<string,mixed> Bijlage payload.
	 */
	public function compose(array $input): array {
		$figures = $this->activityFigures(input: $input);

		$rows = [];
		foreach ($figures['activities'] as $activity) {
			if (is_array($activity) === false) {
				continue;
			}

			$rows[] = $this->activityRow(activity: $activity, figures: $figures);
		}

		return $this->bijlage(input: $input, rows: $rows);

	}//end compose()

	/**
	 * The activities and the per-activity figure maps of the input.
	 *
	 * @param array<string,mixed> $input The compose() inputs.
	 *
	 * @return array<string,array<mixed>>
	 */
	private function activityFigures(array $input): array {
		return [
			'activities' => (array)$input['activities'],
			'ikp' => (array)$input['definitiefIkpByActivity'],
			'priorIkp' => (array)($input['priorYearIkpByActivity'] ?? []),
			'revenue' => (array)$input['omzetByActivity'],
			'priorRevenue' => (array)($input['priorYearOmzetByActivity'] ?? []),
			'abb' => (array)($input['abbByActivity'] ?? []),
			'overrides' => (array)($input['manualOverridesByActivity'] ?? []),
		];

	}//end activityFigures()

	/**
	 * The bijlage row of one commercial activity.
	 *
	 * @param array<string,mixed>        $activity The commercial activity.
	 * @param array<string,array<mixed>> $figures  From activityFigures().
	 *
	 * @return array<string,mixed>
	 */
	private function activityRow(array $activity, array $figures): array {
		$activityId = (string)($activity['id'] ?? $activity['_id'] ?? $activity['code'] ?? '');

		return [
			'commercialActivityId' => $activityId,
			'code' => (string)($activity['code'] ?? ''),
			'name' => (string)($activity['name'] ?? ''),
		] + $this->currentYearFigures(activityId: $activityId, figures: $figures)
			+ $this->priorYearFigures(activityId: $activityId, figures: $figures)
			+ [
				'abbReference' => $this->abbReference(activity: $activity, abb: (array)($figures['abb'][$activityId] ?? [])),
				'manualOverrides' => (int)($figures['overrides'][$activityId] ?? 0),
			];

	}//end activityRow()

	/**
	 * Revenue, integral cost price, cost recovery and compliance of the fiscal year.
	 *
	 * @param string                     $activityId The activity id.
	 * @param array<string,array<mixed>> $figures    From activityFigures().
	 *
	 * @return array<string,mixed>
	 */
	private function currentYearFigures(string $activityId, array $figures): array {
		$ikp = (array)($figures['ikp'][$activityId] ?? []);
		$integraleCost = (float)($ikp['totalCost'] ?? 0);
		$revenue = (float)($figures['revenue'][$activityId] ?? 0);
		$compliant = ($revenue >= $integraleCost);

		return [
			'revenue' => $revenue,
			'integralCostPrice' => $integraleCost,
			'costRecoveryRatio' => $this->recoveryRatio(revenue: $revenue, cost: $integraleCost),
			'compliant' => $compliant,
			'complianceColor' => $this->complianceColor(compliant: $compliant),
		];

	}//end currentYearFigures()

	/**
	 * Revenue, integral cost price and cost recovery of the prior year.
	 *
	 * @param string                     $activityId The activity id.
	 * @param array<string,array<mixed>> $figures    From activityFigures().
	 *
	 * @return array<string,mixed>
	 */
	private function priorYearFigures(string $activityId, array $figures): array {
		$priorIkp = (array)($figures['priorIkp'][$activityId] ?? []);
		$priorCost = (float)($priorIkp['totalCost'] ?? 0);
		$priorRevenue = (float)($figures['priorRevenue'][$activityId] ?? 0);

		return [
			'priorYearOmzet' => $priorRevenue,
			'priorYearIntegraleKostprijs' => $priorCost,
			'priorYearRatio' => $this->recoveryRatio(revenue: $priorRevenue, cost: $priorCost),
		];

	}//end priorYearFigures()

	/**
	 * Revenue over cost to four decimals, or null without a positive cost.
	 *
	 * @param float $revenue The revenue.
	 * @param float $cost    The integral cost.
	 *
	 * @return float|null
	 */
	private function recoveryRatio(float $revenue, float $cost): ?float {
		if ($cost > 0.0) {
			return round(($revenue / $cost), 4);
		}

		return null;

	}//end recoveryRatio()

	/**
	 * The compliance color: groen when compliant, else rood.
	 *
	 * @param bool $compliant Whether revenue covers the integral cost.
	 *
	 * @return string
	 */
	private function complianceColor(bool $compliant): string {
		if ($compliant === true) {
			return 'groen';
		}

		return 'rood';

	}//end complianceColor()

	/**
	 * The ABB exemption reference of an exempted activity, else null.
	 *
	 * @param array<string,mixed> $activity The commercial activity.
	 * @param array<string,mixed> $abb      The activity's ABB record, if any.
	 *
	 * @return string|null
	 */
	private function abbReference(array $activity, array $abb): ?string {
		if ((bool)($activity['isExempted'] ?? false) === true) {
			return (string)($abb['reference'] ?? $activity['exemptionDecisionId'] ?? '');
		}

		return null;

	}//end abbReference()

	/**
	 * The bijlage envelope around the rows, with its compliance summary.
	 *
	 * @param array<string,mixed>            $input The compose() inputs.
	 * @param array<int,array<string,mixed>> $rows  The activity rows.
	 *
	 * @return array<string,mixed>
	 */
	private function bijlage(array $input, array $rows): array {
		$totalCount = count($rows);
		$compliantCount = count(array_filter($rows, static fn (array $row): bool => $row['compliant'] === true));

		return [
			'format' => 'WMO-jaarrekening-bijlage-2024',
			'fiscalYear' => (string)$input['fiscalYear'],
			'administrationId' => (string)$input['administrationId'],
			'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM),
			'activities' => $rows,
			'summary' => [
				'total' => $totalCount,
				'compliant' => $compliantCount,
				'nonCompliant' => ($totalCount - $compliantCount),
			],
		];

	}//end bijlage()

	/**
	 * Aggregate compliance status (REQ-WMO-004 §validate).
	 *
	 * @param array<string,mixed> $bijlage The composed bijlage.
	 *
	 * @return array{compliant:int,nonCompliant:int,total:int,overallCompliant:bool}
	 */
	public function summariseCompliance(array $bijlage): array {
		$compliant = 0;
		$nonCompliant = 0;

		foreach ((array)($bijlage['activities'] ?? []) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			if ((bool)($row['compliant'] ?? false)) {
				$compliant++;
			} else {
				$nonCompliant++;
			}
		}

		$total = ($compliant + $nonCompliant);

		return [
			'compliant' => $compliant,
			'nonCompliant' => $nonCompliant,
			'total' => $total,
			'overallCompliant' => ($nonCompliant === 0 && $total > 0),
		];

	}//end summariseCompliance()

	/**
	 * Render the WMO-bijlage as PDF-ready Markdown (REQ-WMO-004 §pdf).
	 *
	 * The actual PDF render is handled by the shared CashflowPdfRenderer / a
	 * future MdToPdf step; this returns the canonical Markdown source.
	 *
	 * @param array<string,mixed> $bijlage The composed bijlage.
	 *
	 * @return string Markdown source.
	 */
	public function toMarkdown(array $bijlage): string {
		$lines = [];
		$lines[] = '# WMO-bijlage jaarrekening ' . (string)($bijlage['fiscalYear'] ?? '');
		$lines[] = '';
		$lines[] = '_Format: ' . (string)($bijlage['format'] ?? '') . '_';
		$lines[] = '';
		$lines[] = '| Code | Naam | Omzet | Integrale Kostprijs | Ratio | Compliant | ABB |';
		$lines[] = '|------|------|-------|---------------------|-------|-----------|-----|';

		foreach ((array)($bijlage['activities'] ?? []) as $row) {
			if (is_array($row) === true) {
				$lines[] = $this->markdownRow(row: $row);
			}
		}

		$sam = (array)($bijlage['summary'] ?? []);
		$lines[] = '';
		$lines[] = sprintf('**Samenvatting**: %d compliant / %d totaal', (int)($sam['compliant'] ?? 0), (int)($sam['total'] ?? 0));

		return implode("\n", $lines);
	}//end toMarkdown()

	/**
	 * The Markdown table line of one bijlage row.
	 *
	 * @param array<string,mixed> $row A bijlage activity row.
	 *
	 * @return string
	 */
	private function markdownRow(array $row): string {
		$ratioText = '—';
		if ($row['costRecoveryRatio'] !== null) {
			$ratioText = (string)$row['costRecoveryRatio'];
		}

		return sprintf(
			'| %s | %s | %.2f | %.2f | %s | %s | %s |',
			(string)($row['code'] ?? ''),
			(string)($row['name'] ?? ''),
			(float)($row['revenue'] ?? 0),
			(float)($row['integralCostPrice'] ?? 0),
			$ratioText,
			$this->complianceColor(compliant: (bool)($row['compliant'] ?? false)),
			(string)($row['abbReference'] ?? '—')
		);

	}//end markdownRow()

	/**
	 * Render the WMO-bijlage as minimal SBR/XBRL-style XML (REQ-WMO-004 §xml).
	 *
	 * @param array<string,mixed> $bijlage The composed bijlage.
	 *
	 * @return string XML serialization.
	 */
	public function toXml(array $bijlage): string {
		$fy = htmlspecialchars((string)($bijlage['fiscalYear'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
		$administrationId = htmlspecialchars((string)($bijlage['administrationId'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');

		$rows = [];
		foreach ((array)($bijlage['activities'] ?? []) as $r) {
			if (is_array($r) === false) {
				continue;
			}

			$ratioAttr = '';
			if ($r['costRecoveryRatio'] !== null) {
				$ratioAttr = (string)$r['costRecoveryRatio'];
			}

			$compliantAttr = 'false';
			if ((bool)($r['compliant'] ?? false) === true) {
				$compliantAttr = 'true';
			}

			$rows[] = sprintf(
				'  <Activiteit code="%s" omzet="%.2f" integraleKostprijs="%.2f" kostendekkingsratio="%s" compliant="%s" abb="%s"/>',
				htmlspecialchars((string)($r['code'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
				(float)($r['revenue'] ?? 0),
				(float)($r['integralCostPrice'] ?? 0),
				$ratioAttr,
				$compliantAttr,
				htmlspecialchars((string)($r['abbReference'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8')
			);
		}//end foreach

		$body = implode("\n", $rows);

		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<WMOJaarrekeningBijlage fiscalYear="{$fy}" administrationId="{$administrationId}">
{$body}
</WMOJaarrekeningBijlage>
XML;

	}//end toXml()
}//end class
