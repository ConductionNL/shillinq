<?php

/**
 * ACM Report Generator (WMO REQ-WMO-006)
 *
 * Pure-logic generator for ACM rapportages (ACM-standaardformulier-mo-2024).
 * Aggregates commercial activities, integrale-kostprijs records, allocations
 * and ABB-besluiten for a reporting period into a submittable report record.
 * Generates JSON/XML serialisations.
 *
 * Signing is delegated to docudesk via ADR-019 integration registry
 * (REQ-SIGN-001). No PKI signing is performed here.
 *
 * Side-effect-free: takes plain arrays and returns plain arrays / strings; the
 * caller persists the resulting record via OR ObjectService.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Side-effect-free ACM report generator (REQ-WMO-006).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-7
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class AcmReportGenerator {
	/**
	 * Canonical ACM standard format version.
	 *
	 * @var string
	 */
	public const FORMAT = 'acm-standard_form-mo-2024';

	/**
	 * Compose an ACM report record for a reporting period (REQ-WMO-006).
	 *
	 * @param array<string,mixed> $input Inputs (period, administrationId, activities,
	 *                                   ikpRecords, omzetByActivity, abbList?,
	 *                                   manualOverrides?, samenvatting?).
	 *
	 * @return array<string,mixed> ACMReport record matching the schema.
	 *
	 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-8
	 */
	public function compose(array $input): array {
		$period = (string)$input['period'];
		if (preg_match('/^[0-9]{4}-(Q[1-4]|YTD)$/', $period) !== 1) {
			throw new InvalidArgumentException('Invalid period format: ' . $period);
		}

		$inputActivities = (array)$input['activities'];
		$ikpRecords = (array)($input['ikpRecords'] ?? []);
		$revenueByActivity = (array)($input['omzetByActivity'] ?? []);
		$abbList = (array)($input['abbList'] ?? []);

		// The source list and the accumulator were `$activiteiten` and
		// `$activities`; translating the first collapsed them onto one name, and
		// the accumulator's `= []` then emptied the input before the loop read it.
		$activities = [];
		foreach ($inputActivities as $activity) {
			if (is_array($activity) === false) {
				continue;
			}

			$activityId = (string)($activity['id'] ?? $activity['_id'] ?? $activity['code'] ?? '');
			$code = (string)($activity['code'] ?? '');
			$name = (string)($activity['name'] ?? '');
			$ikp = (array)($ikpRecords[$activityId] ?? []);
			$integraleCost = (float)($ikp['totalCost'] ?? 0);
			$revenue = (float)($revenueByActivity[$activityId] ?? 0);
			if ($integraleCost > 0.0) {
				$ratio = round(($revenue / $integraleCost), 4);
			} else {
				$ratio = null;
			}

			$compliant = ($revenue >= $integraleCost);
			if ((bool)($activity['isExempted'] ?? false) === true) {
				$abbReference = (string)($activity['exemptionDecisionId'] ?? '');
			} else {
				$abbReference = null;
			}

			$activities[] = [
				'commercialActivityId' => $activityId,
				'code' => $code,
				'name' => $name,
				'revenue' => $revenue,
				'integralCostPrice' => $integraleCost,
				'costRecoveryRatio' => $ratio,
				'compliant' => $compliant,
				'abbReference' => $abbReference,
			];
		}//end foreach

		$abbSummaries = [];
		foreach ($abbList as $abb) {
			if (is_array($abb) === false) {
				continue;
			}

			$abbSummaries[] = [
				'reference' => (string)($abb['reference'] ?? ''),
				'rationaleExcerpt' => mb_substr(trim((string)($abb['rationale'] ?? '')), 0, 240),
			];
		}

		return [
			'period' => $period,
			'format' => self::FORMAT,
			'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM),
			'activities' => $activities,
			'summary' => ($input['summary'] ?? null),
			'manualOverrides' => (int)($input['manualOverrides'] ?? 0),
			'abbList' => $abbSummaries,
			// Deprecated legacy fields — retained for array shape compatibility; see REQ-SIGN-004.
			'signatory' => null,
			'signedOn' => null,
			'signatureFingerprint' => null,
			'sentInAcm' => false,
			'sentInAcmOn' => null,
			'publicationMunicipalGazette' => null,
			'administrationId' => (string)$input['administrationId'],
			'status' => 'draft',
		];

	}//end compose()

	/**
	 * Submit a signed report (REQ-WMO-006 §submit). Status flips to `verzonden`.
	 *
	 * @param array<string,mixed> $report A signed report.
	 * @param string|null $publicationGmblad Optional gemeenteblad reference.
	 *
	 * @return array<string,mixed> Submitted report (immutable from here).
	 *
	 * @throws InvalidArgumentException When the report is not signed.
	 */
	public function submit(array $report, ?string $publicationGmblad = null): array {
		if ((string)($report['status'] ?? '') !== 'ready-for-submission') {
			throw new InvalidArgumentException('Only ready-for-submission reports can be submitted');
		}

		$report['sentInAcm'] = true;
		$report['sentInAcmOn'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM);
		$report['publicationMunicipalGazette'] = $publicationGmblad;
		$report['status'] = 'verzonden';

		return $report;
	}//end submit()

	/**
	 * Serialize a report to JSON (ACM-API-compatible).
	 *
	 * @param array<string,mixed> $report The report record.
	 *
	 * @return string Pretty-printed JSON.
	 */
	public function toJson(array $report): string {
		$encoded = json_encode($report, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		if ($encoded === false) {
			throw new RuntimeException('Failed to encode report as JSON');
		}

		return $encoded;
	}//end toJson()

	/**
	 * Serialize a report to a minimal SBR/XBRL-style XML (REQ-WMO-006).
	 *
	 * Anticipates the ACM API: a simple top-level <ACMReport format="..."/> with
	 * one <Activiteit/> per line. Real SBR XBRL schemas will be wired when the
	 * ACM API spec is published; this is the structural placeholder used for
	 * the gemeenteblad export and offline review.
	 *
	 * @param array<string,mixed> $report The report record.
	 *
	 * @return string XML serialization.
	 */
	public function toXml(array $report): string {
		$period = htmlspecialchars((string)($report['period'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
		$administrationId = htmlspecialchars((string)($report['administrationId'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
		$format = htmlspecialchars((string)($report['format'] ?? self::FORMAT), ENT_XML1 | ENT_QUOTES, 'UTF-8');

		$lines = [];
		foreach ((array)($report['activities'] ?? []) as $a) {
			if (is_array($a) === false) {
				continue;
			}

			if ($a['costRecoveryRatio'] === null) {
				$ratioAttr = '';
			} else {
				$ratioAttr = (string)$a['costRecoveryRatio'];
			}

			if ((bool)($a['compliant'] ?? false) === true) {
				$compliantAttr = 'true';
			} else {
				$compliantAttr = 'false';
			}

			$lines[] = sprintf(
				'  <Activiteit code="%s" omzet="%.2f" integraleKostprijs="%.2f" kostendekkingsratio="%s" compliant="%s"/>',
				htmlspecialchars((string)($a['code'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
				(float)($a['revenue'] ?? 0),
				(float)($a['integralCostPrice'] ?? 0),
				$ratioAttr,
				$compliantAttr
			);
		}//end foreach

		$body = implode("\n", $lines);

		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ACMReport format="{$format}" period="{$period}" administrationId="{$administrationId}">
{$body}
</ACMReport>
XML;

	}//end toXml()
}//end class
