<?php

/**
 * Unit tests for WmoAuditLogService.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\WmoAuditLogService;
use PHPUnit\Framework\TestCase;

/**
 * Tests the WMO audit log composer (REQ-WMO-010).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WmoAuditLogServiceTest extends TestCase {

	/**
	 * The service under test.
	 */
	private WmoAuditLogService $svc;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new WmoAuditLogService();

	}//end setUp()

	/**
	 * Compose an entry with valid eventType + entityType.
	 */
	public function testComposeEntryHappyPath(): void {
		$entry = $this->svc->composeEntry([
			'eventType' => 'ikp-calculated',
			'entityId' => 'ikp-001',
			'entityType' => 'IntegralCostPrice',
			'userId' => 'system',
			'beforeValues' => null,
			'afterValues' => ['totalCost' => 87_500.00],
			'reason' => 'monthly calc',
			'administrationId' => 'adm-tilburg',
		]);

		self::assertSame('ikp-calculated', $entry['eventType']);
		self::assertSame('IntegralCostPrice', $entry['entityType']);
		self::assertSame('logged', $entry['status']);
		self::assertSame('monthly calc', $entry['reason']);
		self::assertMatchesRegularExpression('/T\d\d:\d\d:\d\d\.\d{3}Z$/', $entry['timestamp']);

	}//end testComposeEntryHappyPath()

	/**
	 * Invalid eventType raises.
	 */
	public function testComposeEntryRejectsInvalidEventType(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->svc->composeEntry([
			'eventType' => 'frob',
			'entityId' => 'x',
			'entityType' => 'CommercialActivity',
			'userId' => 'u',
			'afterValues' => [],
			'administrationId' => 'adm',
		]);

	}//end testComposeEntryRejectsInvalidEventType()

	/**
	 * Retention boundary is 7 years.
	 */
	public function testIsRetentionExpired(): void {
		$entry = ['timestamp' => '2018-01-01T00:00:00Z'];
		self::assertTrue($this->svc->retentionExpiredState($entry, '2026-01-15'));

		$fresh = ['timestamp' => '2024-01-01T00:00:00Z'];
		self::assertFalse($this->svc->retentionExpiredState($fresh, '2026-01-15'));

	}//end testIsRetentionExpired()

	/**
	 * CSV export produces header + one row per entry; commas in fields are quoted.
	 */
	public function testCsvExportEscapesAndOrdersColumns(): void {
		$entries = [
			[
				'timestamp' => '2026-01-15T10:00:00.123Z',
				'eventType' => 'split-overridden',
				'entityType' => 'ActivityCostAllocation',
				'entityId' => 'aca-1',
				'userId' => 'concerncontroller',
				'reason' => 'Wrong rule, fixing',
				'beforeValues' => ['amount' => 184.00],
				'afterValues' => ['amount' => 184.00, 'splits' => 1],
			],
		];

		$csv = $this->svc->toCsv($entries);
		$lines = explode("\n", $csv);
		self::assertSame('timestamp,eventType,entityType,entityId,userId,reason,beforeValues,afterValues', $lines[0]);
		self::assertStringContainsString('split-overridden', $lines[1]);
		self::assertStringContainsString('aca-1', $lines[1]);

	}//end testCsvExportEscapesAndOrdersColumns()

	/**
	 * Manifest enumerates the 5 file-buckets with counts.
	 */
	public function testComposeHandhavingsPakketManifest(): void {
		$manifest = $this->svc->composeHandhavingsPakketManifest([
			'fiscalYear' => '2025',
			'administrationId' => 'adm-tilburg',
			'activitiesCount' => 3,
			'ikpCount' => 36,
			'allocationsCount' => 142,
			'abbCount' => 2,
			'auditEntriesCount' => 580,
		]);

		self::assertSame('ACM-handhavings-pakket-2024', $manifest['format']);
		self::assertSame(3, $manifest['files']['commercial-activities/']['count']);
		self::assertSame(580, $manifest['files']['audit-log/']['count']);

	}//end testComposeHandhavingsPakketManifest()

}//end class
