<?php

/**
 * Guard test: verify that no local PKI signing engine is present in Shillinq.
 *
 * This test enforces REQ-SIGN-004: the local AcmReportGenerator::sign()
 * method must NOT exist (it was removed by the shillinq-delegate-signing
 * change). All signing goes through SigningDelegationService via the
 * ADR-019 integration registry.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Signing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/shillinq-delegate-signing/tasks.md#task-17
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Signing;

use OCA\Shillinq\Service\AcmReportGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the local PKI signing engine has been fully removed (REQ-SIGN-004).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class NoLocalSigningEngineGuardTest extends TestCase {

	/**
	 * AcmReportGenerator must NOT expose a sign() method (REQ-SIGN-004).
	 *
	 * The method was the local PKI signing handler removed as part of the
	 * shillinq-delegate-signing change. Its absence is asserted statically
	 * via reflection to catch accidental re-introductions.
	 */
	public function testAcmReportGeneratorHasNoSignMethod(): void {
		$reflection = new \ReflectionClass(AcmReportGenerator::class);

		self::assertFalse(
			$reflection->hasMethod('sign'),
			'AcmReportGenerator::sign() must not exist — local PKI signing was removed (REQ-SIGN-004). '
			. 'Signing is now delegated to docudesk via SigningDelegationService.'
		);

	}//end testAcmReportGeneratorHasNoSignMethod()

	/**
	 * SigningDelegationService must be the sole signing entry-point.
	 *
	 * Asserts the class exists and exposes the expected public API surface.
	 */
	public function testSigningDelegationServiceIsPresent(): void {
		self::assertTrue(
			class_exists(\OCA\Shillinq\Service\Signing\SigningDelegationService::class),
			'SigningDelegationService must exist as the delegated signing entry-point (REQ-SIGN-001).'
		);

		$reflection = new \ReflectionClass(\OCA\Shillinq\Service\Signing\SigningDelegationService::class);

		self::assertTrue(
			$reflection->hasMethod('requestSignature'),
			'SigningDelegationService::requestSignature() must be present (REQ-SIGN-001).'
		);

		self::assertTrue(
			$reflection->hasMethod('onSigningCallback'),
			'SigningDelegationService::onSigningCallback() must be present (REQ-SIGN-005).'
		);

	}//end testSigningDelegationServiceIsPresent()

}//end class
