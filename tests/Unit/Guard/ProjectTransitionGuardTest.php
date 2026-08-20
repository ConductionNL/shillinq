<?php

/**
 * Unit tests for ProjectTransitionGuard.
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

use OCA\Shillinq\Guard\ProjectTransitionGuard;
use PHPUnit\Framework\TestCase;

final class ProjectTransitionGuardTest extends TestCase {
	private ProjectTransitionGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new ProjectTransitionGuard();
	}//end setUp()

	public function testPutOnHoldAllowedWithReason(): void {
		self::assertTrue($this->guard->requireReason(['closureJustification' => 'Customer requested pause']));
	}//end testPutOnHoldAllowedWithReason()

	public function testPutOnHoldDeniedWithoutReason(): void {
		self::assertFalse($this->guard->requireReason(['closureJustification' => null]));
	}//end testPutOnHoldDeniedWithoutReason()
}//end class
