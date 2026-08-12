<?php

/**
 * Unit tests for KorLockoutGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\KorLockoutGuard;
use PHPUnit\Framework\TestCase;

final class KorLockoutGuardTest extends TestCase {
	private KorLockoutGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new KorLockoutGuard();
	}//end setUp()

	/**
	 * Good path: opted out more than 3 years ago — lock-out expired.
	 *
	 * @return void
	 */
	public function testReturnAllowedAfterLockoutExpires(): void {
		$fourYearsAgo = (new \DateTimeImmutable('-4 years'))->format('Y-m-d');

		$allowed = $this->guard->requireLockoutExpired(['optedOutAt' => $fourYearsAgo]);

		self::assertTrue($allowed);

	}//end testReturnAllowedAfterLockoutExpires()

	/**
	 * Bad path: opted out one year ago — still within the 3-year lock-out.
	 *
	 * @return void
	 */
	public function testReturnDeniedWithinLockoutWindow(): void {
		$oneYearAgo = (new \DateTimeImmutable('-1 year'))->format('Y-m-d');

		$allowed = $this->guard->requireLockoutExpired(['optedOutAt' => $oneYearAgo]);

		self::assertFalse($allowed);

	}//end testReturnDeniedWithinLockoutWindow()

	/**
	 * Bad path: missing optedOutAt fails closed.
	 *
	 * @return void
	 */
	public function testReturnDeniedWithoutOptOutTimestamp(): void {
		$allowed = $this->guard->requireLockoutExpired(['optedOutAt' => null]);

		self::assertFalse($allowed);

	}//end testReturnDeniedWithoutOptOutTimestamp()
}//end class
