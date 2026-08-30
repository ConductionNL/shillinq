<?php

/**
 * Unit tests for BcfSubmissionGuard.
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

use OCA\Shillinq\Guard\BcfSubmissionGuard;
use PHPUnit\Framework\TestCase;

final class BcfSubmissionGuardTest extends TestCase {
	private BcfSubmissionGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new BcfSubmissionGuard();
	}//end setUp()

	public function testSubmitAllowedBelowThreshold(): void {
		self::assertTrue($this->guard->requireApproval(['totalClaimAmount' => 1000, 'approvalThreshold' => 5000]));
	}//end testSubmitAllowedBelowThreshold()

	public function testSubmitDeniedAboveThreshold(): void {
		self::assertFalse($this->guard->requireApproval(['totalClaimAmount' => 8000, 'approvalThreshold' => 5000]));
	}//end testSubmitDeniedAboveThreshold()
}//end class
