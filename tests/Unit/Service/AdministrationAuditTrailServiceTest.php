<?php

/**
 * Unit tests for AdministrationAuditTrailService.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationAuditTrailService;
use OCA\Shillinq\Service\AdministrationContextService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the cross-administratie audit-trail aggregation (REQ-MA-009).
 *
 * The storage helpers are covered by integration tests; this suite covers
 * the pure helpers (`tagWithAdministration`, `sortByTimestampDesc`) and the
 * IDOR guard on `queryForAdministration` (null on non-membership).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AdministrationAuditTrailServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var AdministrationAuditTrailService
	 */
	private AdministrationAuditTrailService $service;

	/**
	 * Mock context service.
	 *
	 * @var AdministrationContextService
	 */
	private AdministrationContextService $context;

	/**
	 * Set up the service with mocked deps.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);
		$this->context = $this->createMock(AdministrationContextService::class);

		$this->service = new AdministrationAuditTrailService(
			container: $container,
			appConfig: $appConfig,
			context: $this->context,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * tagWithAdministration stamps rows that don't already carry an administrationId.
	 *
	 * @return void
	 */
	public function testTagWithAdministrationStampsMissing(): void {
		$rows = [
			['updatedAt' => '2026-06-01T08:00:00Z'],
			['updatedAt' => '2026-06-02T08:00:00Z', 'administrationId' => 'adm-werk-002'],
		];

		$tagged = $this->service->tagWithAdministration(
			rows: $rows,
			administrationId: 'adm-werk-001'
		);

		self::assertSame('adm-werk-001', $tagged[0]['administrationId']);
		// Existing tag is preserved.
		self::assertSame('adm-werk-002', $tagged[1]['administrationId']);

	}//end testTagWithAdministrationStampsMissing()

	/**
	 * sortByTimestampDesc sorts newest-first across multiple timestamp fields.
	 *
	 * @return void
	 */
	public function testSortByTimestampDescNewestFirst(): void {
		$rows = [
			['createdAt' => '2026-06-01T08:00:00Z', 'id' => 'a'],
			['updatedAt' => '2026-06-03T08:00:00Z', 'id' => 'b'],
			['auditTrailUpdatedAt' => '2026-06-02T08:00:00Z', 'id' => 'c'],
		];

		$sorted = $this->service->sortByTimestampDesc(rows: $rows);
		self::assertSame('b', $sorted[0]['id']);
		self::assertSame('c', $sorted[1]['id']);
		self::assertSame('a', $sorted[2]['id']);

	}//end testSortByTimestampDescNewestFirst()

	/**
	 * Rows without any timestamp slot sort to the end (stable).
	 *
	 * @return void
	 */
	public function testSortPushesUndatedRowsToEnd(): void {
		$rows = [
			['id' => 'undated-1'],
			['updatedAt' => '2026-06-01T08:00:00Z', 'id' => 'with-stamp'],
			['id' => 'undated-2'],
		];

		$sorted = $this->service->sortByTimestampDesc(rows: $rows);
		self::assertSame('with-stamp', $sorted[0]['id']);

	}//end testSortPushesUndatedRowsToEnd()

	/**
	 * queryForAdministration returns null when the caller has no membership (REQ-MA-001).
	 *
	 * @return void
	 */
	public function testQueryForAdministrationNullOnNonMembership(): void {
		$this->context->method('canAccess')->willReturn(false);

		self::assertNull(
			$this->service->queryForAdministration(
				administrationId: 'adm-secret-999',
				schema: 'GLTransaction'
			)
		);

	}//end testQueryForAdministrationNullOnNonMembership()

	/**
	 * queryForAdministration returns null on empty arguments (defensive default).
	 *
	 * @return void
	 */
	public function testQueryForAdministrationNullOnEmpty(): void {
		self::assertNull(
			$this->service->queryForAdministration(
				administrationId: '',
				schema: 'GLTransaction'
			)
		);
		self::assertNull(
			$this->service->queryForAdministration(
				administrationId: 'adm-werk-001',
				schema: ''
			)
		);

	}//end testQueryForAdministrationNullOnEmpty()

	/**
	 * queryAcrossAccessibleAdministrations returns empty on empty schema.
	 *
	 * @return void
	 */
	public function testQueryAcrossEmptySchema(): void {
		self::assertSame(
			[],
			$this->service->queryAcrossAccessibleAdministrations(schema: '')
		);

	}//end testQueryAcrossEmptySchema()

	/**
	 * queryAcrossAccessibleAdministrations only iterates the user's memberships
	 * (no cross-tenant leakage — REQ-MA-001).
	 *
	 * @return void
	 */
	public function testQueryAcrossUsesAccessibleListOnly(): void {
		// No accessible administrations -> empty aggregate, no storage call.
		$this->context->method('accessibleAdministrationIds')->willReturn([]);

		$result = $this->service->queryAcrossAccessibleAdministrations(schema: 'GLTransaction');
		self::assertSame([], $result);

	}//end testQueryAcrossUsesAccessibleListOnly()
}//end class
