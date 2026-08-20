<?php

/**
 * Unit tests for VatSubmissionGuard.
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

use OCA\Shillinq\Guard\VatSubmissionGuard;
use PHPUnit\Framework\TestCase;

final class VatSubmissionGuardTest extends TestCase {
	private VatSubmissionGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new VatSubmissionGuard();
	}//end setUp()

	public function testSubmitAllowedBelowThreshold(): void {
		self::assertTrue($this->guard->requireApproval(['amount' => 1000, 'approvalThreshold' => 5000]));
	}//end testSubmitAllowedBelowThreshold()

	public function testSubmitAllowedWhenNoThresholdConfigured(): void {
		self::assertTrue($this->guard->requireApproval(['amount' => 999999, 'approvalThreshold' => null]));
	}//end testSubmitAllowedWhenNoThresholdConfigured()

	public function testSubmitDeniedAboveThreshold(): void {
		self::assertFalse($this->guard->requireApproval(['amount' => 8000, 'approvalThreshold' => 5000]));
	}//end testSubmitDeniedAboveThreshold()
}//end class
