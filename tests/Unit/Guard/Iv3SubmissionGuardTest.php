<?php

/**
 * Unit tests for Iv3SubmissionGuard.
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

use OCA\Shillinq\Guard\Iv3SubmissionGuard;
use PHPUnit\Framework\TestCase;

final class Iv3SubmissionGuardTest extends TestCase {
	private Iv3SubmissionGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new Iv3SubmissionGuard();
	}//end setUp()

	/**
	 * Good path: not yet submitted, xml + buckets present.
	 *
	 * @return void
	 */
	public function testSubmitAllowedWhenNotYetSubmitted(): void {
		$allowed = $this->guard->requireApproval(
			[
				'submittedAt' => null,
				'xmlAttachmentUri' => 'docudesk://iv3-2026-q1.xml',
				'buckets' => [['code' => '1A', 'amount' => 1000]],
			]
		);

		self::assertTrue($allowed);

	}//end testSubmitAllowedWhenNotYetSubmitted()

	/**
	 * Bad path: already submitted — deny resubmission.
	 *
	 * @return void
	 */
	public function testSubmitDeniedWhenAlreadySubmitted(): void {
		$allowed = $this->guard->requireApproval(
			[
				'submittedAt' => '2026-02-01T10:00:00Z',
				'xmlAttachmentUri' => 'docudesk://iv3-2026-q1.xml',
				'buckets' => [['code' => '1A', 'amount' => 1000]],
			]
		);

		self::assertFalse($allowed);

	}//end testSubmitDeniedWhenAlreadySubmitted()

	/**
	 * Bad path: missing xml attachment denies.
	 *
	 * @return void
	 */
	public function testSubmitDeniedWithoutXmlAttachment(): void {
		$allowed = $this->guard->requireApproval(
			[
				'submittedAt' => null,
				'xmlAttachmentUri' => '',
				'buckets' => [['code' => '1A', 'amount' => 1000]],
			]
		);

		self::assertFalse($allowed);

	}//end testSubmitDeniedWithoutXmlAttachment()
}//end class
