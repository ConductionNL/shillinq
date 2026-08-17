<?php

/**
 * Unit tests for FidoTreasuryGuard.
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
 * @spec openspec/changes/bookkeeping-wet-fido-treasury/specs/bookkeeping-wet-fido-treasury/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\FidoTreasuryGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for FidoTreasuryGuard lifecycle preconditions.
 *
 * Covers REQ-FDO-001 / D5 (signing-mandate matrix), REQ-FDO-004 / D6 (RUDDO
 * hedging-only), REQ-FDO-006 / D8 (rapportage dual sign-off) and REQ-FDO-008 /
 * D10 (limiet-breach override-rationale). All guards fail closed.
 */
class FidoTreasuryGuardTest extends TestCase {

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
	 * @var FidoTreasuryGuard
	 */
	private FidoTreasuryGuard $guard;

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

		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->stubAdoptedStatuut(statuut: null);

	}//end setUp()

	/**
	 * Rebuild the guard over a stub ObjectService that yields the given
	 * Treasurystatuut row for the adopted-statuut lookup (ADR-022 real API shape).
	 *
	 * ADR-084 injects the ObjectService through the constructor, so the store
	 * has to be present when the guard is built — parking it on the container
	 * after the fact leaves the guard reading an empty world.
	 *
	 * @param array<string,mixed>|null $statuut The adopted statuut to return, or null.
	 *
	 * @return void
	 */
	private function stubAdoptedStatuut(?array $statuut): void {
		$rows = [];
		if ($statuut !== null) {
			$rows = [$statuut];
		}

		$objectService = new class($rows) {

			/**
			 * Rows to return from findAll().
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $rows;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $rows Rows to return from findAll().
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
			}//end __construct()

			/**
			 * Fluent register setter.
			 *
			 * @param string $register Register slug (ignored by the stub).
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug (ignored by the stub).
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				return $this;
			}//end setSchema()

			/**
			 * Return the canned rows regardless of the query (ADR-022 shape).
			 *
			 * @param array<string,mixed> $query Query payload (ignored by the stub).
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $query): array {
				return $this->rows;
			}//end findAll()
		};

		$this->guard = new FidoTreasuryGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($objectService),
		);

	}//end stubAdoptedStatuut()

	/**
	 * The canonical seed signing-mandate matrix (treasurer / directeur / college).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function seedMandates(): array {
		return [
			[
				'role' => 'treasurer',
				'maxAmount' => 5000000,
				'instruments' => ['kasgeld'],
				'authority' => 'zelfstandig',
			],
			[
				'role' => 'directeur',
				'maxAmount' => 25000000,
				'instruments' => ['kasgeld', 'onderhandse-lening'],
				'authority' => 'co-sign-required',
			],
			[
				'role' => 'college',
				'maxAmount' => null,
				'instruments' => ['kasgeld', 'onderhandse-lening', 'obligatie', 'MTN', 'EMTN'],
				'authority' => 'college-besluit-required',
			],
		];

	}//end seedMandates()

	/**
	 * A lening within the treasurer mandate and the limieten may be recorded
	 * (REQ-FDO-001 / D5).
	 *
	 * @return void
	 */
	public function testLeningWithinMandateCanRecord(): void {
		$this->stubAdoptedStatuut(statuut: ['status' => 'adopted', 'signingMandates' => $this->seedMandates()]);

		$lening = [
			'organisationId' => 'org-1',
			'treasuryStatuteId' => 'stat-1',
			'signingMandateRole' => 'treasurer',
			'type' => 'kasgeld',
			'principal' => 2500000,
			'limitBreach' => false,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canRecordLening(leningId: 'len-1', object: $lening));

	}//end testLeningWithinMandateCanRecord()

	/**
	 * A lening above the treasurer maxAmount falls outside the mandate row and is
	 * denied (REQ-FDO-001 / D5).
	 *
	 * @return void
	 */
	public function testLeningAboveMandateAmountDenied(): void {
		$this->stubAdoptedStatuut(statuut: ['status' => 'adopted', 'signingMandates' => $this->seedMandates()]);

		$lening = [
			'organisationId' => 'org-1',
			'signingMandateRole' => 'treasurer',
			'type' => 'kasgeld',
			'principal' => 9000000,
			'limitBreach' => false,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRecordLening(leningId: 'len-2', object: $lening));

	}//end testLeningAboveMandateAmountDenied()

	/**
	 * A lening whose instrument is not listed for the signer role is denied
	 * (REQ-FDO-001 / D5).
	 *
	 * @return void
	 */
	public function testLeningInstrumentNotPermittedForRoleDenied(): void {
		$this->stubAdoptedStatuut(statuut: ['status' => 'adopted', 'signingMandates' => $this->seedMandates()]);

		$lening = [
			'organisationId' => 'org-1',
			'signingMandateRole' => 'treasurer',
			'type' => 'obligatie',
			'principal' => 1000000,
			'limitBreach' => false,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRecordLening(leningId: 'len-3', object: $lening));

	}//end testLeningInstrumentNotPermittedForRoleDenied()

	/**
	 * The college mandate has an unbounded (null) maxAmount and authorises any
	 * principal (REQ-FDO-001 / D5).
	 *
	 * @return void
	 */
	public function testCollegeUnboundedMandateAuthorisesLargeLening(): void {
		$this->stubAdoptedStatuut(statuut: ['status' => 'adopted', 'signingMandates' => $this->seedMandates()]);

		$lening = [
			'organisationId' => 'org-1',
			'signingMandateRole' => 'college',
			'type' => 'EMTN',
			'principal' => 500000000,
			'limitBreach' => false,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canRecordLening(leningId: 'len-4', object: $lening));

	}//end testCollegeUnboundedMandateAuthorisesLargeLening()

	/**
	 * A flagged limiet-breach without an override-rationale blocks the lening
	 * (REQ-FDO-008 / D10).
	 *
	 * @return void
	 */
	public function testLimietBreachWithoutRationaleDenied(): void {
		$this->stubAdoptedStatuut(statuut: ['status' => 'adopted', 'signingMandates' => $this->seedMandates()]);

		$lening = [
			'organisationId' => 'org-1',
			'signingMandateRole' => 'treasurer',
			'type' => 'kasgeld',
			'principal' => 2500000,
			'limitBreach' => true,
			'overrideRationale' => '   ',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRecordLening(leningId: 'len-5', object: $lening));

	}//end testLimietBreachWithoutRationaleDenied()

	/**
	 * A flagged limiet-breach WITH an override-rationale and within the mandate may
	 * be recorded with override (REQ-FDO-008 / D10).
	 *
	 * @return void
	 */
	public function testLimietBreachWithRationaleCanRecord(): void {
		$this->stubAdoptedStatuut(statuut: ['status' => 'adopted', 'signingMandates' => $this->seedMandates()]);

		$lening = [
			'organisationId' => 'org-1',
			'signingMandateRole' => 'treasurer',
			'type' => 'kasgeld',
			'principal' => 2500000,
			'limitBreach' => true,
			'overrideRationale' => 'Emergency bridging finance for grant-receipt delay; repayment Q2.',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canRecordLening(leningId: 'len-6', object: $lening));

	}//end testLimietBreachWithRationaleCanRecord()

	/**
	 * A lening with no adopted statuut available is denied — fail-closed
	 * (REQ-FDO-001 / D4).
	 *
	 * @return void
	 */
	public function testLeningWithoutAdoptedStatuutDenied(): void {
		$this->stubAdoptedStatuut(statuut: null);

		$lening = [
			'organisationId' => 'org-1',
			'signingMandateRole' => 'treasurer',
			'type' => 'kasgeld',
			'principal' => 2500000,
			'limitBreach' => false,
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRecordLening(leningId: 'len-7', object: $lening));

	}//end testLeningWithoutAdoptedStatuutDenied()

	/**
	 * A fully valid hedging derivaat passes RUDDO validation (REQ-FDO-004 / D6).
	 *
	 * @return void
	 */
	public function testValidHedgingDerivaatCanRecord(): void {
		$derivaat = [
			'type' => 'IRS',
			'notional' => 18000000,
			'hedgedExposureId' => 'len-mtn-2028',
			'hedgedExposureAmount' => 20000000,
			'RUDDOJustification' => 'IRS to hedge floating-rate risk on EUR 20M MTN maturing 2028.',
			'counterpartyRating' => 'A',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canRecordDerivaat(derivaatId: 'der-1', object: $derivaat));

	}//end testValidHedgingDerivaatCanRecord()

	/**
	 * A derivaat without a RUDDO justification narrative is refused (REQ-FDO-004).
	 *
	 * @return void
	 */
	public function testDerivaatWithoutJustificationDenied(): void {
		$derivaat = [
			'type' => 'IRS',
			'notional' => 1000000,
			'hedgedExposureId' => 'len-1',
			'hedgedExposureAmount' => 2000000,
			'RUDDOJustification' => '',
			'counterpartyRating' => 'AA',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRecordDerivaat(derivaatId: 'der-2', object: $derivaat));

	}//end testDerivaatWithoutJustificationDenied()

	/**
	 * A derivaat without a hedge-link is refused — speculation is illegal
	 * (REQ-FDO-004 / RUDDO Article 2).
	 *
	 * @return void
	 */
	public function testDerivaatWithoutHedgeLinkDenied(): void {
		$derivaat = [
			'type' => 'cap',
			'notional' => 1000000,
			'hedgedExposureId' => '',
			'hedgedExposureAmount' => 2000000,
			'RUDDOJustification' => 'Speculative rate bet.',
			'counterpartyRating' => 'AA',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRecordDerivaat(derivaatId: 'der-3', object: $derivaat));

	}//end testDerivaatWithoutHedgeLinkDenied()

	/**
	 * A derivaat whose notional exceeds the hedged exposure (over-hedging) is
	 * refused (REQ-FDO-004 / D6).
	 *
	 * @return void
	 */
	public function testDerivaatOverHedgingDenied(): void {
		$derivaat = [
			'type' => 'IRS',
			'notional' => 25000000,
			'hedgedExposureId' => 'len-1',
			'hedgedExposureAmount' => 20000000,
			'RUDDOJustification' => 'Hedge on MTN.',
			'counterpartyRating' => 'A',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRecordDerivaat(derivaatId: 'der-4', object: $derivaat));

	}//end testDerivaatOverHedgingDenied()

	/**
	 * A derivaat with a counterparty rating below single-A is refused
	 * (REQ-FDO-004 / D6).
	 *
	 * @return void
	 */
	public function testDerivaatBelowSingleARated(): void {
		$derivaat = [
			'type' => 'IRS',
			'notional' => 1000000,
			'hedgedExposureId' => 'len-1',
			'hedgedExposureAmount' => 2000000,
			'RUDDOJustification' => 'Hedge on MTN.',
			'counterpartyRating' => 'BBB',
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canRecordDerivaat(derivaatId: 'der-5', object: $derivaat));

	}//end testDerivaatBelowSingleARated()

	/**
	 * A rapportage with both treasurer + concerncontroller sign-off may be
	 * submitted (REQ-FDO-006 / D8).
	 *
	 * @return void
	 */
	public function testRapportageWithBothSignOffsCanSubmit(): void {
		$report = [
			'signOffTreasurer' => ['person' => 'treasurer-user', 'timestamp' => '2026-01-12T10:00:00Z'],
			'signOffGroupController' => ['person' => 'controller-user', 'timestamp' => '2026-01-12T11:00:00Z'],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canSubmitRapportage(reportId: 'rep-1', object: $report));

	}//end testRapportageWithBothSignOffsCanSubmit()

	/**
	 * A rapportage missing the concerncontroller sign-off cannot be submitted
	 * (REQ-FDO-006 / D8).
	 *
	 * @return void
	 */
	public function testRapportageMissingControllerSignOffDenied(): void {
		$report = [
			'signOffTreasurer' => ['person' => 'treasurer-user', 'timestamp' => '2026-01-12T10:00:00Z'],
			'signOffGroupController' => ['person' => '', 'timestamp' => ''],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmitRapportage(reportId: 'rep-2', object: $report));

	}//end testRapportageMissingControllerSignOffDenied()

	/**
	 * A rapportage with no sign-off objects at all cannot be submitted
	 * (REQ-FDO-006 fail-closed).
	 *
	 * @return void
	 */
	public function testRapportageWithoutSignOffsDenied(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canSubmitRapportage(reportId: 'rep-3', object: []));

	}//end testRapportageWithoutSignOffsDenied()
}//end class
