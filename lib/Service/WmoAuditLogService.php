<?php

/**
 * WMO Audit Log Service (REQ-WMO-010)
 *
 * Pure-logic composer for `WMOAuditLog` entries. The audit log is append-only:
 * every WMO entity-mutation (activity save, IKP calc, allocation override,
 * ABB state change, alert raised/resolved, ACM report sign / submit, benchmark
 * created) flows through `composeEntry()`, which fills in eventType, before/after
 * snapshots, user-id, ms-precision timestamp, and motivation. The caller
 * persists the result via OR ObjectService.
 *
 * The service also provides the CSV export (REQ-WMO-010 §csv) and the
 * handhavings-pakket index assembler (REQ-WMO-010 §handhavings-pakket).
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Side-effect-free WMO audit-log composer and export helper (REQ-WMO-010).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-16
 */
class WmoAuditLogService {
	/**
	 * Retention period in years (Mededingingswet bewaartermijn).
	 *
	 * @var int
	 */
	public const RETENTION_YEARS = 7;

	/**
	 * Allowed eventType values (matches schema enum).
	 *
	 * @var array<int,string>
	 */
	public const EVENT_TYPES = [
		'activity-created',
		'activity-updated',
		'ikp-calculated',
		'ikp-final-signed',
		'split-applied',
		'split-overridden',
		'abb-status-change',
		'acm-report-generated',
		'acm-report-signed',
		'acm-report-submitted',
		'alert-created',
		'alert-resolved',
		'benchmark-created',
	];

	/**
	 * Allowed entityType values.
	 *
	 * @var array<int,string>
	 */
	public const ENTITY_TYPES = [
		'CommercialActivity',
		'IntegralCostPrice',
		'ActivityCostAllocation',
		'GeneralInterestDecision',
		'ACMReport',
		'AlertLog',
		'MarketBenchmark',
	];

	/**
	 * Compose a WMOAuditLog entry (REQ-WMO-010).
	 *
	 * @param array<string,mixed> $input Composition inputs: eventType, entityId,
	 *                                   entityType, userId, beforeValues?,
	 *                                   afterValues, reason?, administrationId.
	 *
	 * @return array<string,mixed> Audit-log record matching the schema.
	 *
	 * @throws InvalidArgumentException When eventType / entityType is invalid.
	 */
	public function composeEntry(array $input): array {
		$eventType = (string)$input['eventType'];
		if (in_array($eventType, self::EVENT_TYPES, true) === false) {
			throw new InvalidArgumentException('Invalid eventType: ' . $eventType);
		}

		$entityType = (string)$input['entityType'];
		if (in_array($entityType, self::ENTITY_TYPES, true) === false) {
			throw new InvalidArgumentException('Invalid entityType: ' . $entityType);
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		return [
			'eventType' => $eventType,
			'entityId' => (string)$input['entityId'],
			'entityType' => $entityType,
			'userId' => (string)$input['userId'],
			'timestamp' => $now->format('Y-m-d\TH:i:s.v\Z'),
			'beforeValues' => ($input['beforeValues'] ?? null),
			'afterValues' => (array)$input['afterValues'],
			'reason' => ($input['reason'] ?? null),
			'administrationId' => (string)$input['administrationId'],
			'status' => 'logged',
		];

	}//end composeEntry()

	/**
	 * Determine whether an audit log entry has crossed the 7-year retention boundary (REQ-WMO-010 §retention).
	 *
	 * @param array<string,mixed> $entry The audit log entry.
	 * @param string $today Today's ISO date.
	 *
	 * @return bool True when the entry should be moved to status=archived.
	 */
	public function retentionExpiredState(array $entry, string $today): bool {
		$timestamp = (string)($entry['timestamp'] ?? '');
		if ($timestamp === '') {
			return false;
		}

		try {
			$logged = new DateTimeImmutable($timestamp);
			$now = new DateTimeImmutable($today);
		} catch (\Throwable) {
			return false;
		}

		$boundary = $logged->add(new DateInterval('P' . self::RETENTION_YEARS . 'Y'));
		return $now >= $boundary;
	}//end retentionExpiredState()

	/**
	 * Export audit log entries to CSV (REQ-WMO-010 §csv).
	 *
	 * Columns: timestamp, eventType, entityType, entityId, userId, reason,
	 * beforeValues (JSON), afterValues (JSON).
	 *
	 * @param array<int,array<string,mixed>> $entries Audit log records.
	 *
	 * @return string CSV output.
	 */
	public function toCsv(array $entries): string {
		$rows = ['timestamp,eventType,entityType,entityId,userId,reason,beforeValues,afterValues'];

		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$rows[] = sprintf(
				'%s,%s,%s,%s,%s,%s,%s,%s',
				$this->csvEscape(field: (string)($entry['timestamp'] ?? '')),
				$this->csvEscape(field: (string)($entry['eventType'] ?? '')),
				$this->csvEscape(field: (string)($entry['entityType'] ?? '')),
				$this->csvEscape(field: (string)($entry['entityId'] ?? '')),
				$this->csvEscape(field: (string)($entry['userId'] ?? '')),
				$this->csvEscape(field: (string)($entry['reason'] ?? '')),
				$this->csvEscape(field: $this->jsonInline(value: $entry['beforeValues'] ?? null)),
				$this->csvEscape(field: $this->jsonInline(value: $entry['afterValues'] ?? []))
			);
		}

		return implode("\n", $rows);
	}//end toCsv()

	/**
	 * Compose the ACM-handhavings-pakket manifest (REQ-WMO-010 §handhavings-pakket).
	 *
	 * The caller bundles the listed files into a zip; this returns the
	 * manifest.json index describing what the package contains.
	 *
	 * @param array<string,mixed> $input Inputs: fiscalYear, administrationId,
	 *                                   activitiesCount, ikpCount,
	 *                                   allocationsCount, abbCount,
	 *                                   auditEntriesCount.
	 *
	 * @return array<string,mixed> Manifest object.
	 */
	public function composeHandhavingsPakketManifest(array $input): array {
		$fiscalYear = (string)$input['fiscalYear'];
		$generatedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM);

		return [
			'format' => 'ACM-handhavings-pakket-2024',
			'generatedAt' => $generatedAt,
			'fiscalYear' => $fiscalYear,
			'administrationId' => (string)$input['administrationId'],
			'files' => [
				'commercial-activities/' => [
					'description' => 'Per-activity snapshots',
					'count' => (int)$input['activitiesCount'],
					'pattern' => 'commercial-activities/<activity-code>.json',
				],
				'cost-prices/' => [
					'description' => 'IKP records per activity per period',
					'count' => (int)$input['ikpCount'],
					'pattern' => 'cost-prices/<periode>/<activity-code>.json',
				],
				'allocations/' => [
					'description' => 'ActivityCostAllocation records',
					'count' => (int)$input['allocationsCount'],
					'pattern' => 'allocations/<periode>/<journal-entry-id>.json',
				],
				'besluiten/' => [
					'description' => 'ABB decision PDFs / metadata',
					'count' => (int)$input['abbCount'],
					'pattern' => 'besluiten/<abb-kenmerk>.json',
				],
				'audit-log/' => [
					'description' => 'WMOAuditLog CSV export per period',
					'count' => (int)$input['auditEntriesCount'],
					'pattern' => 'audit-log/<periode>.csv',
				],
			],
		];

	}//end composeHandhavingsPakketManifest()

	/**
	 * CSV-escape a single field (RFC 4180).
	 *
	 * @param string $field The field to escape.
	 *
	 * @return string The escaped field.
	 */
	private function csvEscape(string $field): string {
		if (str_contains($field, ',') === true || str_contains($field, '"') === true || str_contains($field, "\n") === true) {
			return '"' . str_replace('"', '""', $field) . '"';
		}

		return $field;
	}//end csvEscape()

	/**
	 * Convert a value to an inline JSON string.
	 *
	 * @param mixed $value Any value.
	 *
	 * @return string Inline JSON or empty string.
	 */
	private function jsonInline(mixed $value): string {
		if ($value === null) {
			return '';
		}

		$json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			return '';
		}

		return $json;
	}//end jsonInline()
}//end class
