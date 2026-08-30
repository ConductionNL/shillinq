<?php

/**
 * Unit tests for ProjectCloseGuard.
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

use OCA\Shillinq\Guard\ProjectCloseGuard;
use PHPUnit\Framework\TestCase;

final class ProjectCloseGuardTest extends TestCase {
	private ProjectCloseGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new ProjectCloseGuard();
	}//end setUp()

	public function testCloseAllowedWithZeroWip(): void {
		self::assertTrue(
			$this->guard->requireWipJustificationOrZero(['wipBalance' => 0, 'closureJustification' => null])
		);
	}//end testCloseAllowedWithZeroWip()

	public function testCloseAllowedWithNonZeroWipAndJustification(): void {
		self::assertTrue(
			$this->guard->requireWipJustificationOrZero(
				['wipBalance' => 4250.50, 'closureJustification' => 'Client agreed to write off remaining WIP']
			)
		);
	}//end testCloseAllowedWithNonZeroWipAndJustification()

	public function testCloseDeniedWithNonZeroWipAndNoJustification(): void {
		self::assertFalse(
			$this->guard->requireWipJustificationOrZero(['wipBalance' => 4250.50, 'closureJustification' => null])
		);
	}//end testCloseDeniedWithNonZeroWipAndNoJustification()
}//end class
