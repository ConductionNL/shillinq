<?php

/**
 * Unit tests for AuditorStatementGuard.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-subsidie-verantwoording/specs.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\AuditorStatementGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-SUBV-005: an AuditorStatement may only be approved when it has no
 * findings, or all findings are marked resolved.
 */
class AuditorStatementGuardTest extends TestCase {
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
	 * @var AuditorStatementGuard
	 */
	private AuditorStatementGuard $guard;

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

		$this->guard = new AuditorStatementGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * A statement with no findings may be approved (REQ-SUBV-005).
	 *
	 * @return void
	 */
	public function testNoFindingsCanApprove(): void {
		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApprove(statementId: 'as-1', object: ['findings' => []]));

	}//end testNoFindingsCanApprove()

	/**
	 * A statement where all findings are resolved may be approved (REQ-SUBV-005).
	 *
	 * @return void
	 */
	public function testAllResolvedFindingsCanApprove(): void {
		$object = [
			'findings' => [
				['categoryId' => 'documentation', 'resolution' => 'resolved'],
				['categoryId' => 'tax', 'resolution' => 'resolved'],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($this->guard->canApprove(statementId: 'as-2', object: $object));

	}//end testAllResolvedFindingsCanApprove()

	/**
	 * A statement with an unresolved finding cannot be approved (REQ-SUBV-005).
	 *
	 * @return void
	 */
	public function testUnresolvedFindingCannotApprove(): void {
		$object = [
			'findings' => [
				['categoryId' => 'documentation', 'resolution' => 'resolved'],
				['categoryId' => 'compliance', 'resolution' => 'open'],
			],
		];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApprove(statementId: 'as-3', object: $object));

	}//end testUnresolvedFindingCannotApprove()

	/**
	 * A finding with no explicit resolution defaults to open and blocks approval (REQ-SUBV-005).
	 *
	 * @return void
	 */
	public function testMissingResolutionDefaultsToOpenAndBlocks(): void {
		$object = ['findings' => [['categoryId' => 'eligibility']]];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApprove(statementId: 'as-4', object: $object));

	}//end testMissingResolutionDefaultsToOpenAndBlocks()

	/**
	 * A malformed findings value fails closed (CWE-863).
	 *
	 * @return void
	 */
	public function testMalformedFindingsFailsClosed(): void {
		$object = ['findings' => 'not-an-array'];

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($this->guard->canApprove(statementId: 'as-5', object: $object));

	}//end testMalformedFindingsFailsClosed()
}//end class
