<?php

/**
 * Unit tests for RegisterLocation.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\RegisterLocation;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RegisterLocation.
 */
class RegisterLocationTest extends TestCase {

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock IAppManager.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * The class under test.
	 *
	 * @var RegisterLocation
	 */
	private RegisterLocation $location;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->appConfig  = $this->createMock(IAppConfig::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->location   = new RegisterLocation(appConfig: $this->appConfig, appManager: $this->appManager);

	}//end setUp()

	/**
	 * isOpenRegisterAvailable asks IAppManager whether openregister is installed.
	 *
	 * @return void
	 */
	public function testIsOpenRegisterAvailableAsksTheAppManager(): void {
		$this->appManager->expects($this->exactly(2))
			->method('isInstalled')
			->with('openregister')
			->willReturnOnConsecutiveCalls(true, false);

		self::assertTrue($this->location->isOpenRegisterAvailable());
		self::assertFalse($this->location->isOpenRegisterAvailable());

	}//end testIsOpenRegisterAvailableAsksTheAppManager()

	/**
	 * getRegisterSlug returns the configured slug, read with the 'shillinq' default.
	 *
	 * @return void
	 */
	public function testGetRegisterSlugReturnsConfiguredSlug(): void {
		$this->appConfig->expects($this->once())
			->method('getValueString')
			->with('shillinq', 'register', 'shillinq')
			->willReturn('finance-register');

		self::assertSame('finance-register', $this->location->getRegisterSlug());

	}//end testGetRegisterSlugReturnsConfiguredSlug()

	/**
	 * getRegisterSlug falls back to 'shillinq' when the stored slug is empty.
	 *
	 * @return void
	 */
	public function testGetRegisterSlugFallsBackWhenEmpty(): void {
		$this->appConfig->method('getValueString')->willReturn('');

		self::assertSame('shillinq', $this->location->getRegisterSlug());

	}//end testGetRegisterSlugFallsBackWhenEmpty()
}//end class
