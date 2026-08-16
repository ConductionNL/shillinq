<?php

/**
 * Unit tests for ProjectActivationGuard.
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

use OCA\Shillinq\Guard\ProjectActivationGuard;
use PHPUnit\Framework\TestCase;

final class ProjectActivationGuardTest extends TestCase {
	private ProjectActivationGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new ProjectActivationGuard();
	}//end setUp()

	public function testActivateAllowedWithStartDate(): void {
		self::assertTrue($this->guard->requireStartDate(['startDate' => '2026-01-01']));
	}//end testActivateAllowedWithStartDate()

	public function testActivateDeniedWithoutStartDate(): void {
		self::assertFalse($this->guard->requireStartDate(['startDate' => null]));
	}//end testActivateDeniedWithoutStartDate()

	public function testActivateDeniedWithBlankStartDate(): void {
		self::assertFalse($this->guard->requireStartDate(['startDate' => '   ']));
	}//end testActivateDeniedWithBlankStartDate()
}//end class
