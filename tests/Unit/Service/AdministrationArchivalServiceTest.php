<?php

/**
 * Unit tests for AdministrationArchivalService.
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
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-17
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationArchivalService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the administratie archival write-block (REQ-MA-007).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AdministrationArchivalServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var AdministrationArchivalService
	 */
	private AdministrationArchivalService $service;

	/**
	 * Set up the service with mocked dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new AdministrationArchivalService(
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * Active administration accepts writes.
	 *
	 * @return void
	 */
	public function testActiefIsWritable(): void {
		self::assertTrue(
			$this->service->writesAllowed(administration: ['status' => 'actief'])
		);

	}//end testActiefIsWritable()

	/**
	 * In-liquidatie administration still accepts (closing) writes.
	 *
	 * @return void
	 */
	public function testInLiquidatieIsWritable(): void {
		self::assertTrue(
			$this->service->writesAllowed(administration: ['status' => 'in_liquidatie'])
		);

	}//end testInLiquidatieIsWritable()

	/**
	 * Archived administration rejects writes.
	 *
	 * @return void
	 */
	public function testGearchiveerdRejectsWrites(): void {
		self::assertFalse(
			$this->service->writesAllowed(administration: ['status' => 'gearchiveerd'])
		);

	}//end testGearchiveerdRejectsWrites()

	/**
	 * Opgeheven administration rejects writes.
	 *
	 * @return void
	 */
	public function testOpgehevenRejectsWrites(): void {
		self::assertFalse(
			$this->service->writesAllowed(administration: ['status' => 'opgeheven'])
		);

	}//end testOpgehevenRejectsWrites()

	/**
	 * An unknown or missing status is default-secure: writes are rejected.
	 *
	 * @return void
	 */
	public function testUnknownStatusIsDefaultSecure(): void {
		self::assertFalse(
			$this->service->writesAllowed(administration: [])
		);
		self::assertFalse(
			$this->service->writesAllowed(administration: ['status' => 'iets_anders'])
		);

	}//end testUnknownStatusIsDefaultSecure()

	/**
	 * assertWritable raises on archived state.
	 *
	 * @return void
	 */
	public function testAssertWritableRaisesOnGearchiveerd(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/administratie gearchiveerd/');
		$this->service->assertWritable(administration: ['status' => 'gearchiveerd']);

	}//end testAssertWritableRaisesOnGearchiveerd()

	/**
	 * assertWritable raises on missing status (default-secure).
	 *
	 * @return void
	 */
	public function testAssertWritableRaisesOnMissingStatus(): void {
		$this->expectException(RuntimeException::class);
		$this->service->assertWritable(administration: []);

	}//end testAssertWritableRaisesOnMissingStatus()

	/**
	 * assertWritable is a no-op for actief.
	 *
	 * @return void
	 */
	public function testAssertWritableActiefIsNoop(): void {
		// No assertion needed — the absence of an exception is the contract.
		$this->service->assertWritable(administration: ['status' => 'actief']);
		self::assertTrue(true);

	}//end testAssertWritableActiefIsNoop()

	/**
	 * Lifecycle transitions match the schema-declared graph.
	 *
	 * @return void
	 */
	public function testIsTransitionAllowedMatchesLifecycle(): void {
		// Allowed transitions out of actief.
		self::assertTrue($this->service->isTransitionAllowed(from: 'actief', to: 'gearchiveerd'));
		self::assertTrue($this->service->isTransitionAllowed(from: 'actief', to: 'in_liquidatie'));

		// in_liquidatie -> opgeheven, gearchiveerd.
		self::assertTrue($this->service->isTransitionAllowed(from: 'in_liquidatie', to: 'opgeheven'));
		self::assertTrue($this->service->isTransitionAllowed(from: 'in_liquidatie', to: 'gearchiveerd'));

		// Terminal states have no transitions.
		self::assertFalse($this->service->isTransitionAllowed(from: 'gearchiveerd', to: 'actief'));
		self::assertFalse($this->service->isTransitionAllowed(from: 'opgeheven', to: 'actief'));

		// Same-state transitions are a no-op (tolerated).
		self::assertTrue($this->service->isTransitionAllowed(from: 'actief', to: 'actief'));

	}//end testIsTransitionAllowedMatchesLifecycle()

	/**
	 * Retention clock starts on the active -> read-only transition (REQ-MA-007).
	 *
	 * @return void
	 */
	public function testRetentionClockStartsOnArchive(): void {
		self::assertTrue(
			$this->service->shouldStartRetentionClock(from: 'actief', to: 'gearchiveerd')
		);
		self::assertTrue(
			$this->service->shouldStartRetentionClock(from: 'in_liquidatie', to: 'opgeheven')
		);

	}//end testRetentionClockStartsOnArchive()

	/**
	 * The retention clock does NOT start on transitions within the writable set.
	 *
	 * @return void
	 */
	public function testRetentionClockDoesNotStartBetweenWritableStates(): void {
		self::assertFalse(
			$this->service->shouldStartRetentionClock(from: 'actief', to: 'in_liquidatie')
		);

	}//end testRetentionClockDoesNotStartBetweenWritableStates()

	/**
	 * The retention clock does NOT restart for transitions between read-only states.
	 *
	 * @return void
	 */
	public function testRetentionClockDoesNotRestartBetweenReadOnlyStates(): void {
		// We don't allow gearchiveerd -> opgeheven in the graph, so this returns
		// false because the transition itself isn't allowed.
		self::assertFalse(
			$this->service->shouldStartRetentionClock(from: 'gearchiveerd', to: 'opgeheven')
		);

	}//end testRetentionClockDoesNotRestartBetweenReadOnlyStates()

	/**
	 * assertWritableById rejects an empty id outright.
	 *
	 * @return void
	 */
	public function testAssertWritableByIdRejectsEmpty(): void {
		$this->expectException(RuntimeException::class);
		$this->service->assertWritableById(administrationId: '');

	}//end testAssertWritableByIdRejectsEmpty()
}//end class
