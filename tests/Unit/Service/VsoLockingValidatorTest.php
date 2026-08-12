<?php

/**
 * Unit tests for VsoLockingValidator.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\VsoLockingValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Verifies the VSO year-lock check (REQ-IBA-008, task 4.3).
 *
 * Exercises the pure short-circuit branches (empty administration, non-positive
 * boekjaar) without touching the OpenRegister container. The full positive
 * path (find returns a row with vso_locked true) is exercised by the
 * controller / listener integration tests in the live phpunit.xml profile.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VsoLockingValidatorTest extends TestCase {

	/**
	 * The validator under test.
	 *
	 * @var VsoLockingValidator
	 */
	private VsoLockingValidator $val;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$this->val = new VsoLockingValidator(
			$this->createMock(ContainerInterface::class),
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Empty administrationId short-circuits to unlocked.
	 *
	 * @return void
	 */
	public function testEmptyAdministrationReturnsUnlocked(): void {
		$this->assertFalse($this->val->isYearLocked('', 2026));

	}//end testEmptyAdministrationReturnsUnlocked()

	/**
	 * Non-positive boekjaar short-circuits to unlocked.
	 *
	 * @return void
	 */
	public function testNonPositiveBoekjaarReturnsUnlocked(): void {
		$this->assertFalse($this->val->isYearLocked('adm-x', 0));
		$this->assertFalse($this->val->isYearLocked('adm-x', -1));

	}//end testNonPositiveBoekjaarReturnsUnlocked()

	/**
	 * A container failure (ObjectService not available) downgrades to
	 * "not locked" + a logged warning — fail-soft per the contract.
	 *
	 * @return void
	 */
	public function testContainerFailureFailsSoftToUnlocked(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('not bound'));

		$val = new VsoLockingValidator(
			$container,
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertFalse($val->isYearLocked('adm-x', 2026));

	}//end testContainerFailureFailsSoftToUnlocked()
}//end class
