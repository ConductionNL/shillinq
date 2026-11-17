<?php

/**
 * RegisterSlugTest — the configured register slug and its fallback.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Support;

use OCA\Shillinq\Support\RegisterSlug;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Covers OCA\Shillinq\Support\RegisterSlug.
 */
#[CoversClass(RegisterSlug::class)]
final class RegisterSlugTest extends TestCase {

	/**
	 * A configured slug is returned as configured, read under the app id.
	 *
	 * @return void
	 */
	public function testAConfiguredSlugIsReturned(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())
			->method('getValueString')
			->with('shillinq', 'register', 'shillinq')
			->willReturn('finance');

		$this->assertSame('finance', RegisterSlug::of(config: $config));
	}//end testAConfiguredSlugIsReturned()

	/**
	 * An empty configured value falls back to `shillinq`.
	 *
	 * @return void
	 */
	public function testAnEmptySlugFallsBackToTheAppRegister(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');

		$this->assertSame('shillinq', RegisterSlug::of(config: $config));
	}//end testAnEmptySlugFallsBackToTheAppRegister()
}//end class
