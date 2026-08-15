<?php

/**
 * Unit tests for DunningGuard.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/spec.md *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\DunningGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DunningGuard lifecycle preconditions.
 *
 * Covers REQ-CCD-001 (override approval gate), REQ-CCD-002 (run immutability),
 * REQ-CCD-003/006 (B2C 14-dagen-brief + day-44 incassokosten block), REQ-CCD-004
 * (pause resolve requires pauzeEind) and REQ-CCD-010 (write-off requires art. 29
 * OB-verklaring). All guards fail closed; inline-object cases never touch the
 * container. */
class DunningGuardTest extends TestCase {

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

	/**     * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var DunningGuard
	 */
	private DunningGuard $guard;

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

		$this->appConfig->method('getValueString')
			->willReturnCallback(
				static function (string $app, string $key, string $default = '') {
					return $default;
				}
			);
		$this->appConfig->method('getValueInt')
			->willReturnCallback(
				static function (string $app, string $key, int $default = 0) {
					return $default;
				}
			);

		$this->guard = new DunningGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	// phpcs:disable CustomSniffs.Functions.NamedParameters

	/**
	 * An override that keeps all five stages needs no approver (REQ-CCD-001).
	 *
	 * @return void
	 */
	public function testOverrideKeepingAllStagesActivatesWithoutApprover(): void {
		$object = [
			'overrides' => [
				'stages' => [
					['nr' => 1],
					['nr' => 2],
					['nr' => 3],
					['nr' => 4],
					['nr' => 5],
				],
			],
		];

		self::assertTrue($this->guard->canActivateOverride(overrideId: 'ov-1', object: $object));

	}//end testOverrideKeepingAllStagesActivatesWithoutApprover()

	/**
	 * An override that drops stage 5 (escalation-skip) requires an approver
	 * (REQ-CCD-001 / design D6).
	 *
	 * @return void
	 */
	public function testOverrideSkippingLateStageNeedsApprover(): void {
		$object = [
			'overrides' => [
				'stages' => [
					['nr' => 1],
					['nr' => 2],
					['nr' => 3],
					['nr' => 4],
				],
			],
		];

		self::assertFalse($this->guard->canActivateOverride(overrideId: 'ov-2', object: $object));

		$object['approvedBy'] = 'controller-user';
		self::assertTrue($this->guard->canActivateOverride(overrideId: 'ov-2', object: $object));

	}//end testOverrideSkippingLateStageNeedsApprover()

	/**
	 * A B2C stage-3 run without the 14-dagen-brief text may not execute
	 * (REQ-CCD-006 / art. 6:96 BW).
	 *
	 * @return void
	 */
	public function testB2cStageThreeRunWithoutFourteenDayLetterBlocked(): void {
		$object = [
			'state' => 'draft',
			'stageNr' => 3,
			'partyType' => 'B2C',
			'renderedBody' => 'Beste klant, gelieve te betalen.',
		];

		self::assertFalse($this->guard->canExecuteRun(runId: 'run-1', object: $object));

	}//end testB2cStageThreeRunWithoutFourteenDayLetterBlocked()

	/**
	 * A B2C stage-3 run carrying the 14-dagen-brief text may execute (REQ-CCD-006).
	 *
	 * @return void
	 */
	public function testB2cStageThreeRunWithFourteenDayLetterAllowed(): void {
		$object = [
			'state' => 'draft',
			'stageNr' => 3,
			'partyType' => 'B2C',
			'renderedBody' => 'U krijgt 14 dagen om de factuur alsnog te voldoen.',
		];

		self::assertTrue($this->guard->canExecuteRun(runId: 'run-2', object: $object));

	}//end testB2cStageThreeRunWithFourteenDayLetterAllowed()

	/**
	 * A B2B stage-3 run is not bound by the 14-dagen-brief rule (REQ-CCD-006).
	 *
	 * @return void
	 */
	public function testB2bStageThreeRunNeedsNoFourteenDayLetter(): void {
		$object = [
			'state' => 'draft',
			'stageNr' => 3,
			'partyType' => 'B2B',
			'renderedBody' => 'Aanmaning — gelieve per omgaande te voldoen.',
		];

		self::assertTrue($this->guard->canExecuteRun(runId: 'run-3', object: $object));

	}//end testB2bStageThreeRunNeedsNoFourteenDayLetter()

	/**
	 * An already-executed run is immutable and may not be re-executed
	 * (REQ-CCD-002).
	 *
	 * @return void
	 */
	public function testExecutedRunIsImmutable(): void {
		$object = ['state' => 'executed', 'stageNr' => 1];

		self::assertFalse($this->guard->canExecuteRun(runId: 'run-4', object: $object));

	}//end testExecutedRunIsImmutable()

	/**
	 * A pause may only resolve once a pauzeEind is recorded (REQ-CCD-004).
	 *
	 * @return void
	 */
	public function testPauseResolveRequiresPauzeEind(): void {
		self::assertFalse(
			$this->guard->canResolvePause(pauseId: 'p-1', object: ['pauseEnd' => ''])
		);
		self::assertTrue(
			$this->guard->canResolvePause(pauseId: 'p-2', object: ['pauseEnd' => '2026-06-21T10:00:00Z'])
		);

	}//end testPauseResolveRequiresPauzeEind()

	/**
	 * A write-off may only post with an art. 29 OB-verklaring and a positive
	 * afgeschreven hoofdsom (REQ-CCD-010).
	 *
	 * @return void
	 */
	public function testWriteOffPostRequiresVerklaringAndAmount(): void {
		self::assertFalse(
			$this->guard->canPostWriteOff(
				writeOffId: 'w-1',
				object: ['art29OBDeclaration' => '', 'principalDepreciated' => 100.0]
			)
		);
		self::assertFalse(
			$this->guard->canPostWriteOff(
				writeOffId: 'w-2',
				object: ['art29OBDeclaration' => 'Faillissement', 'principalDepreciated' => 0.0]
			)
		);
		self::assertTrue(
			$this->guard->canPostWriteOff(
				writeOffId: 'w-3',
				object: ['art29OBDeclaration' => 'Faillissement vonnis 2026-04-12', 'principalDepreciated' => 4200.0]
			)
		);

	}//end testWriteOffPostRequiresVerklaringAndAmount()

	/**
	 * B2C incassokosten are blocked before day 44 and allowed from day 44; B2B is
	 * never blocked (REQ-CCD-003 / REQ-CCD-006 / art. 6:96 BW).
	 *
	 * @return void
	 */
	public function testB2cIncassokostenBlockedBeforeDay44(): void {
		self::assertTrue($this->guard->blocksB2cIncassokosten(partyType: 'B2C', daysAfterExpiryDate: 40));
		self::assertFalse($this->guard->blocksB2cIncassokosten(partyType: 'B2C', daysAfterExpiryDate: 44));
		self::assertFalse($this->guard->blocksB2cIncassokosten(partyType: 'B2C', daysAfterExpiryDate: 60));
		self::assertFalse($this->guard->blocksB2cIncassokosten(partyType: 'B2B', daysAfterExpiryDate: 5));

	}//end testB2cIncassokostenBlockedBeforeDay44()

	/**
	 * Every guard fails closed when the object is null (CWE-863).
	 *
	 * @return void
	 */
	public function testGuardsFailClosedOnNullObject(): void {
		self::assertFalse($this->guard->canActivateOverride(overrideId: '', object: null));
		self::assertFalse($this->guard->canExecuteRun(runId: '', object: null));
		self::assertFalse($this->guard->canResolvePause(pauseId: '', object: null));
		self::assertFalse($this->guard->canPostWriteOff(writeOffId: '', object: null));

	}//end testGuardsFailClosedOnNullObject()

	// phpcs:enable CustomSniffs.Functions.NamedParameters

}//end class
