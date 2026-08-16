<?php

/**
 * Unit tests for DBAComplianceGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dba-compliance-marker/specs/dba-compliance-marker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\DBAComplianceGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DBAComplianceGuard lifecycle preconditions + derivation helpers.
 *
 * Covers REQ-DBA-001 (intake verplicht before first factuur — canActivateOpdracht),
 * REQ-DBA-003 (totaalScore sum, four-band derivation, completable intake),
 * REQ-DBA-007 (evidence completeness ratio), REQ-DBA-016 (VBAR effective-rate
 * breach) and REQ-DBA-002 (modelovereenkomst expiry). All guards fail closed;
 * inline-object cases never touch the container.
 */
class DBAComplianceGuardTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var DBAComplianceGuard
	 */
	private DBAComplianceGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		// Default: register slug resolves to shillinq, no VBAR override.
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				return $default;
			}
		);

		$this->guard = new DBAComplianceGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * An opdracht whose intake is VOLTOOID may be activated (REQ-DBA-001).
	 *
	 * @return void
	 */
	public function testCanActivateOpdrachtWithCompletedIntake(): void {
		$object = ['intakeStatus' => 'VOLTOOID'];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canActivateOpdracht(assignmentId: 'dba-opdr-1', object: $object));

	}//end testCanActivateOpdrachtWithCompletedIntake()

	/**
	 * An opdracht without a completed intake cannot be activated — the first
	 * factuur stays blocked (REQ-DBA-001 fail-closed).
	 *
	 * @return void
	 */
	public function testCannotActivateOpdrachtWithoutCompletedIntake(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivateOpdracht(assignmentId: 'dba-opdr-2', object: ['intakeStatus' => 'DRAFT']));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canActivateOpdracht(assignmentId: 'dba-opdr-3', object: ['intakeStatus' => 'NONE']));

	}//end testCannotActivateOpdrachtWithoutCompletedIntake()

	/**
	 * Beeindiging requires a feitelijkeEindDatum to start the 7-year AWR clock
	 * (REQ-DBA-018).
	 *
	 * @return void
	 */
	public function testCanBeeindigOpdrachtRequiresEndDate(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canBeeindigOpdracht(assignmentId: 'dba-opdr-4', object: ['actualEndDate' => '2026-09-30']));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canBeeindigOpdracht(assignmentId: 'dba-opdr-5', object: ['actualEndDate' => '']));

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canBeeindigOpdracht(assignmentId: 'dba-opdr-6', object: []));

	}//end testCanBeeindigOpdrachtRequiresEndDate()

	/**
	 * An intake whose stored totaalScore matches the recomputed sum and yields a
	 * non-empty band may complete (REQ-DBA-003).
	 *
	 * @return void
	 */
	public function testCanCompleteIntakeWithConsistentScore(): void {
		$object = [
			'gezagSubtotaal' => 9,
			'arbeidSubtotaal' => 4,
			'financieelSubtotaal' => 9,
			'deliverooSubtotaal' => 12,
			'totalScore' => 34,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canCompleteIntake(intakeId: 'intake-1', object: $object));

	}//end testCanCompleteIntakeWithConsistentScore()

	/**
	 * A tampered or stale totaalScore that does not equal the recomputed sum
	 * blocks completion (REQ-DBA-003 anti-tamper).
	 *
	 * @return void
	 */
	public function testCannotCompleteIntakeWithTamperedScore(): void {
		$object = [
			'gezagSubtotaal' => 9,
			'arbeidSubtotaal' => 4,
			'financieelSubtotaal' => 9,
			'deliverooSubtotaal' => 12,
			// Real sum is 34; a tampered 10 must be rejected.
			'totalScore' => 10,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canCompleteIntake(intakeId: 'intake-2', object: $object));

	}//end testCannotCompleteIntakeWithTamperedScore()

	/**
	 * A verkorte intake (eenmalige opdracht < EUR 5000) is always completable —
	 * it carries the VERKORT_LAGE_DREMPEL band, not a full score (REQ-DBA-001).
	 *
	 * @return void
	 */
	public function testVerkorteIntakeAlwaysCompletable(): void {
		$object = ['verkort' => true, 'totalScore' => 0];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canCompleteIntake(intakeId: 'intake-3', object: $object));

	}//end testVerkorteIntakeAlwaysCompletable()

	/**
	 * The totaalScore is the clamped sum of the three pijler subtotals plus the
	 * Deliveroo subtotal (REQ-DBA-003).
	 *
	 * @return void
	 */
	public function testComputeTotaalScoreSumsSubtotals(): void {
		$intake = [
			'gezagSubtotaal' => 17,
			'arbeidSubtotaal' => 4,
			'financieelSubtotaal' => 16,
			'deliverooSubtotaal' => 44,
		];

		// 17 + 4 + 16 + 44 = 81, within 0-100.
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame(81, $this->guard->computeTotaalScore(intake: $intake));

	}//end testComputeTotaalScoreSumsSubtotals()

	/**
	 * An over-100 raw sum is clamped to 100 (REQ-DBA-003).
	 *
	 * @return void
	 */
	public function testComputeTotaalScoreClampsToHundred(): void {
		$intake = [
			'gezagSubtotaal' => 50,
			'arbeidSubtotaal' => 50,
			'financieelSubtotaal' => 50,
			'deliverooSubtotaal' => 50,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertSame(100, $this->guard->computeTotaalScore(intake: $intake));

	}//end testComputeTotaalScoreClampsToHundred()

	/**
	 * The four risk bands map onto the documented score ranges (REQ-DBA-003).
	 *
	 * @return void
	 */
	public function testDeriveRiskBandBoundaries(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertSame('LOW', $this->guard->deriveRiskBand(score: 0));
		self::assertSame('LOW', $this->guard->deriveRiskBand(score: 24));
		self::assertSame('LOW_MIDDEN', $this->guard->deriveRiskBand(score: 25));
		self::assertSame('LOW_MIDDEN', $this->guard->deriveRiskBand(score: 49));
		self::assertSame('MIDDEN_HIGH', $this->guard->deriveRiskBand(score: 50));
		self::assertSame('MIDDEN_HIGH', $this->guard->deriveRiskBand(score: 74));
		self::assertSame('HIGH', $this->guard->deriveRiskBand(score: 75));
		self::assertSame('HIGH', $this->guard->deriveRiskBand(score: 100));
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end testDeriveRiskBandBoundaries()

	/**
	 * A dossier with all required stuk types scores 1.0 with no missing items
	 * (REQ-DBA-007).
	 *
	 * @return void
	 */
	public function testComputeCompletenessFullDossier(): void {
		$documents = [
			['type' => 'SIGNED_AGREEMENT'],
			['type' => 'INVOICE_FIRST'],
			['type' => 'TIMESHEET_QUARTER'],
		];

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$result = $this->guard->computeCompleteness(documents: $documents);

		self::assertSame(1.0, $result['score']);
		self::assertSame([], $result['missing']);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end testComputeCompletenessFullDossier()

	/**
	 * A dossier missing the urenstaten scores ~0.667 and lists the missing type
	 * (REQ-DBA-007).
	 *
	 * @return void
	 */
	public function testComputeCompletenessMissingUrenstaat(): void {
		$documents = [
			['type' => 'SIGNED_AGREEMENT'],
			['type' => 'INVOICE_FIRST'],
		];

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$result = $this->guard->computeCompleteness(documents: $documents);

		self::assertEqualsWithDelta(0.6667, $result['score'], 0.001);
		self::assertSame(['TIMESHEET_QUARTER'], $result['missing']);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end testComputeCompletenessMissingUrenstaat()

	/**
	 * An effective hourly rate below the VBAR grens is a breach (REQ-DBA-016).
	 *
	 * @return void
	 */
	public function testVbarBreachBelowGrens(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		// 40 hours x EUR 28 -> EUR 28/hour, below the EUR 33 grens.
		$result = $this->guard->effectiveHourlyRateBreach(amount: 1120.0, hours: 40.0);

		self::assertTrue($result['breach']);
		self::assertEqualsWithDelta(28.0, $result['rate'], 0.001);
		self::assertSame(33.0, $result['grens']);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end testVbarBreachBelowGrens()

	/**
	 * An effective hourly rate at or above the VBAR grens is no breach
	 * (REQ-DBA-016).
	 *
	 * @return void
	 */
	public function testVbarNoBreachAboveGrens(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		// EUR 12.000 / 280 hours -> EUR 42,86/hour, above the grens.
		$result = $this->guard->effectiveHourlyRateBreach(amount: 12000.0, hours: 280.0);

		self::assertFalse($result['breach']);
		self::assertEqualsWithDelta(42.857, $result['rate'], 0.001);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end testVbarNoBreachAboveGrens()

	/**
	 * Non-positive hours yield no breach — the rate cannot be computed
	 * (REQ-DBA-016).
	 *
	 * @return void
	 */
	public function testVbarNoBreachOnZeroHours(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$result = $this->guard->effectiveHourlyRateBreach(amount: 1000.0, hours: 0.0);

		self::assertFalse($result['breach']);
		self::assertSame(0.0, $result['rate']);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end testVbarNoBreachOnZeroHours()

	/**
	 * A modelovereenkomst past its geldigTot is expired; one without geldigTot
	 * never expires (REQ-DBA-002).
	 *
	 * @return void
	 */
	public function testIsModelExpired(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->isModelExpired(model: ['validTo' => '2024-12-31'], referenceYmd: '2026-05-15'));
		self::assertFalse($this->guard->isModelExpired(model: ['validTo' => '2029-04-12'], referenceYmd: '2026-05-15'));
		self::assertFalse($this->guard->isModelExpired(model: ['validTo' => ''], referenceYmd: '2026-05-15'));
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end testIsModelExpired()

	/**
	 * A configured administration VBAR override replaces the constant grens
	 * (REQ-DBA-016).
	 *
	 * @return void
	 */
	public function testVbarGrensHonorsAdministrationOverride(): void {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				if ($key === 'dba_vbar_grens') {
					return '35';
				}

				return $default;
			}
		);

		$guard = new DBAComplianceGuard(
			appConfig: $appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// 40 hours x EUR 34 -> EUR 34/hour, above the constant 33 but below the
		// overridden 35 -> breach.
		$result = $guard->effectiveHourlyRateBreach(amount: 1360.0, hours: 40.0);

		self::assertTrue($result['breach']);
		self::assertSame(35.0, $result['grens']);
		// phpcs:enable CustomSniffs.Functions.NamedParameters

	}//end testVbarGrensHonorsAdministrationOverride()
}//end class
