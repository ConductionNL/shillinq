<?php

/**
 * Unit tests for BegrotingswijzigingGuard.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-26
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\BegrotingswijzigingGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the wijziging vaststellen precondition (REQ-009).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BegrotingswijzigingGuardTest extends TestCase {

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
	 * @var BegrotingswijzigingGuard
	 */
	private BegrotingswijzigingGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new BegrotingswijzigingGuard(
			appConfig: $this->appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * REQ-009 scenario: vaststellen allowed when the raadsbesluit FK is set.
	 *
	 * @return void
	 */
	public function testCanVaststellenWhenRaadsbesluitSet(): void {
		$wijziging = ['id' => 'bw-1', 'councilResolution' => 'raadsbesluit-031'];
		self::assertTrue($this->guard->canVaststellen(wijzigingId: 'bw-1', object: $wijziging));

	}//end testCanVaststellenWhenRaadsbesluitSet()

	/**
	 * REQ-009 scenario: vaststellen blocked without a raadsbesluit FK.
	 *
	 * @return void
	 */
	public function testCanVaststellenDeniedWithoutRaadsbesluit(): void {
		$wijziging = ['id' => 'bw-1', 'councilResolution' => null];
		self::assertFalse($this->guard->canVaststellen(wijzigingId: 'bw-1', object: $wijziging));

	}//end testCanVaststellenDeniedWithoutRaadsbesluit()

	/**
	 * A blank raadsbesluit string is treated as unset.
	 *
	 * @return void
	 */
	public function testCanVaststellenDeniedOnBlankRaadsbesluit(): void {
		$wijziging = ['id' => 'bw-1', 'councilResolution' => '   '];
		self::assertFalse($this->guard->canVaststellen(wijzigingId: 'bw-1', object: $wijziging));

	}//end testCanVaststellenDeniedOnBlankRaadsbesluit()

	/**
	 * An exception during lookup causes a fail-closed denial.
	 *
	 * @return void
	 */
	public function testFailsClosedOnException(): void {
		$this->container->method('get')->willThrowException(new \RuntimeException('OR down'));
		// No raadsbesluit key forces a lookup, which throws → fail-closed.
		self::assertFalse($this->guard->canVaststellen(wijzigingId: 'bw-1', object: null));

	}//end testFailsClosedOnException()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
