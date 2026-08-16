<?php

/**
 * Unit tests for SubsidieVerantwoordingService.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\SubsidieVerantwoordingService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure auto-generation rules: draft SubsidieVerantwoording on grant
 * award/disbursement (REQ-SUBV-009) and the pending AuditorStatement trigger on
 * large grants (REQ-SUBV-006), plus the idempotent persistence wrapper.
 */
class SubsidieVerantwoordingServiceTest extends TestCase {
	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The service under test.
	 *
	 * @var SubsidieVerantwoordingService
	 */
	private SubsidieVerantwoordingService $service;

	/**
	 * Set up test fixtures with the default (unset) auditor threshold.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->appConfig = $this->createMock(IAppConfig::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				return $default;
			}
		);

		$this->service = new SubsidieVerantwoordingService(appConfig: $this->appConfig);

	}//end setUp()

	/**
	 * The draft verantwoording carries an auto-calculated reporting period and
	 * snapshots the awarded amount (REQ-SUBV-009 / REQ-SUBV-002).
	 *
	 * @return void
	 */
	public function testBuildVerantwoordingForGrant(): void {
		$grant = [
			'subsidyNumber' => 'SUB-2026-0001',
			'awardDate' => '2026-01-01',
			'awardAmount' => 50000,
			'administrationId' => 'adm-gemeente-1',
		];

		$payload = $this->service->buildVerantwoordingForGrant(grant: $grant, reportDate: '2026-03-01');

		self::assertSame('SV-SUB-2026-0001', $payload['accountabilityId']);
		self::assertSame('SUB-2026-0001', $payload['grantId']);
		self::assertSame('2026-03-01', $payload['reportDate']);
		self::assertSame('2026-01-01 to 2026-03-01', $payload['reportingPeriod']);
		self::assertSame('draft', $payload['status']);
		self::assertSame(50000.0, $payload['awardedAmount']);
		self::assertSame('adm-gemeente-1', $payload['administrationId']);

	}//end testBuildVerantwoordingForGrant()

	/**
	 * With no award date the reporting period falls back to the report date span.
	 *
	 * @return void
	 */
	public function testBuildVerantwoordingWithoutAwardDate(): void {
		$grant = ['subsidyNumber' => 'SUB-2', 'awardAmount' => 1000, 'administrationId' => 'adm-1'];
		$payload = $this->service->buildVerantwoordingForGrant(grant: $grant, reportDate: '2026-05-05');

		self::assertSame('2026-05-05 to 2026-05-05', $payload['reportingPeriod']);

	}//end testBuildVerantwoordingWithoutAwardDate()

	/**
	 * A grant at or above the threshold triggers a pending AuditorStatement (REQ-SUBV-006).
	 *
	 * @return void
	 */
	public function testAuditorStatementTriggeredForLargeGrant(): void {
		$accountability = [
			'grantId' => 'SUB-2026-0001',
			'awardedAmount' => 30000.0,
			'administrationId' => 'adm-gemeente-1',
		];

		$payload = $this->service->buildAuditorStatementForVerantwoording(
			accountability: $accountability,
			auditorUserId: 'auditor-jansen',
			auditDate: '2026-03-15'
		);

		self::assertNotNull($payload);
		self::assertSame('AS-SUB-2026-0001', $payload['statementId']);
		self::assertTrue($payload['auditThresholdApplied']);
		self::assertSame('pending', $payload['status']);
		self::assertSame('auditor-jansen', $payload['auditorUserId']);
		self::assertSame([], $payload['findings']);

	}//end testAuditorStatementTriggeredForLargeGrant()

	/**
	 * A grant below the threshold does NOT trigger an AuditorStatement (REQ-SUBV-006).
	 *
	 * @return void
	 */
	public function testNoAuditorStatementForSmallGrant(): void {
		$accountability = ['grantId' => 'SUB-2', 'awardedAmount' => 10000.0, 'administrationId' => 'adm-1'];

		$payload = $this->service->buildAuditorStatementForVerantwoording(
			accountability: $accountability,
			auditorUserId: 'auditor-jansen'
		);

		self::assertNull($payload);

	}//end testNoAuditorStatementForSmallGrant()

	/**
	 * The threshold boundary (exactly 25,000) requires an auditor statement (REQ-SUBV-006).
	 *
	 * @return void
	 */
	public function testThresholdBoundaryRequiresAuditorStatement(): void {
		self::assertTrue($this->service->requiresAuditorStatement(awardedAmount: 25000.0));
		self::assertFalse($this->service->requiresAuditorStatement(awardedAmount: 24999.99));

	}//end testThresholdBoundaryRequiresAuditorStatement()

	// testPersistChangeIsIdempotent was removed with
	// SubsidieVerantwoordingService::persistChange(), which had no production
	// caller. It exercised a hand-rolled ObjectService stub rather than any
	// live path; the builders it complemented are pure and are still covered
	// by the cases above.
}//end class
