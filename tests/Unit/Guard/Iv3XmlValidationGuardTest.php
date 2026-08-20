<?php

/**
 * Unit tests for Iv3XmlValidationGuard.
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

use OCA\Shillinq\Guard\Iv3XmlValidationGuard;
use PHPUnit\Framework\TestCase;

final class Iv3XmlValidationGuardTest extends TestCase {
	private Iv3XmlValidationGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new Iv3XmlValidationGuard();
	}//end setUp()

	/**
	 * Good path: xmlAttachmentUri set + buckets non-empty passes.
	 *
	 * @return void
	 */
	public function testValidateAllowedWhenXmlAndBucketsPresent(): void {
		$allowed = $this->guard->requireValidXml(
			[
				'xmlAttachmentUri' => 'docudesk://iv3-2026-q1.xml',
				'buckets' => [['code' => '1A', 'amount' => 1000]],
			]
		);

		self::assertTrue($allowed);

	}//end testValidateAllowedWhenXmlAndBucketsPresent()

	/**
	 * Bad path: no xmlAttachmentUri denies (nothing generated yet).
	 *
	 * @return void
	 */
	public function testValidateDeniedWithoutXmlAttachment(): void {
		$allowed = $this->guard->requireValidXml(
			[
				'xmlAttachmentUri' => '',
				'buckets' => [['code' => '1A', 'amount' => 1000]],
			]
		);

		self::assertFalse($allowed);

	}//end testValidateDeniedWithoutXmlAttachment()

	/**
	 * Bad path: empty buckets denies (nothing aggregated).
	 *
	 * @return void
	 */
	public function testValidateDeniedWithEmptyBuckets(): void {
		$allowed = $this->guard->requireValidXml(
			[
				'xmlAttachmentUri' => 'docudesk://iv3-2026-q1.xml',
				'buckets' => [],
			]
		);

		self::assertFalse($allowed);

	}//end testValidateDeniedWithEmptyBuckets()
}//end class
