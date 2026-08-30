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
		$activities = (array)$input['activities'];
		$ikpByAct = (array)$input['definitiefIkpByActivity'];
		$priorIkpByAct = (array)($input['priorYearIkpByActivity'] ?? []);
		$revenueByAct = (array)$input['omzetByActivity'];
		$priorRevenueByAct = (array)($input['priorYearOmzetByActivity'] ?? []);
		$abbByAct = (array)($input['abbByActivity'] ?? []);
		$overridesByAct = (array)($input['manualOverridesByActivity'] ?? []);

		$rows = [];
		$compliantCount = 0;
		$totalCount = 0;

		foreach ($activities as $activity) {
			if (is_array($activity) === false) {
				continue;
			}

			$activityId = (string)($activity['id'] ?? $activity['_id'] ?? $activity['code'] ?? '');
			$code = (string)($activity['code'] ?? '');
			$name = (string)($activity['name'] ?? '');

			$ikp = (array)($ikpByAct[$activityId] ?? []);
			$integraleCost = (float)($ikp['totalCost'] ?? 0);
			$revenue = (float)($revenueByAct[$activityId] ?? 0);
			$ratio = null;
			if ($integraleCost > 0.0) {
				$ratio = round(($revenue / $integraleCost), 4);
			}

			$compliant = ($revenue >= $integraleCost);
			$colorStatus = 'rood';
			if ($compliant === true) {
				$colorStatus = 'groen';
			}

			$priorIkp = (array)($priorIkpByAct[$activityId] ?? []);
			$priorCost = (float)($priorIkp['totalCost'] ?? 0);
			$priorRevenue = (float)($priorRevenueByAct[$activityId] ?? 0);
			$priorRatio = null;
			if ($priorCost > 0.0) {
				$priorRatio = round(($priorRevenue / $priorCost), 4);
			}

			$abb = (array)($abbByAct[$activityId] ?? []);
			$abbReference = null;
			if ((bool)($activity['isExempted'] ?? false) === true) {
				$abbReference = (string)($abb['reference'] ?? $activity['exemptionDecisionId'] ?? '');
			}

			$rows[] = [
				'commercialActivityId' => $activityId,
				'code' => $code,
				'name' => $name,
				'revenue' => $revenue,
				'integralCostPrice' => $integraleCost,
				'costRecoveryRatio' => $ratio,
				'compliant' => $compliant,
				'complianceColor' => $colorStatus,
				'priorYearOmzet' => $priorRevenue,
				'priorYearIntegraleKostprijs' => $priorCost,
				'priorYearRatio' => $priorRatio,
				'abbReference' => $abbReference,
				'manualOverrides' => (int)($overridesByAct[$activityId] ?? 0),
			];

			if ($compliant === true) {
				$compliantCount++;
			}

			$totalCount++;
		}//end foreach

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

	}//end compose()

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
			if (is_array($row) === false) {
				continue;
			}

			$ratioText = '—';
			if ($row['costRecoveryRatio'] !== null) {
				$ratioText = (string)$row['costRecoveryRatio'];
			}

			$compliantText = 'rood';
			if ((bool)($row['compliant'] ?? false) === true) {
				$compliantText = 'groen';
			}

			$lines[] = sprintf(
				'| %s | %s | %.2f | %.2f | %s | %s | %s |',
				(string)($row['code'] ?? ''),
				(string)($row['name'] ?? ''),
				(float)($row['revenue'] ?? 0),
				(float)($row['integralCostPrice'] ?? 0),
				$ratioText,
				$compliantText,
				(string)($row['abbReference'] ?? '—')
			);
		}//end foreach

		$sam = (array)($bijlage['summary'] ?? []);
		$lines[] = '';
		$lines[] = sprintf('**Samenvatting**: %d compliant / %d totaal', (int)($sam['compliant'] ?? 0), (int)($sam['total'] ?? 0));

		return implode("\n", $lines);
	}//end toMarkdown()

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
