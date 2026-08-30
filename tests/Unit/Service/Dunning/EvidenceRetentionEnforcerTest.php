<?php

/**
 * Unit tests for EvidenceRetentionEnforcer.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Dunning
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-25
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Dunning;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Shillinq\Service\Dunning\EvidenceRetentionEnforcer;
use PHPUnit\Framework\TestCase;

/**
 * EvidenceRetentionEnforcer unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class EvidenceRetentionEnforcerTest extends TestCase {
	/**
	 * Task-25: known schemes (docudesk:, openregister:, postnl:, dunning-run:) pass.
	 *
	 * @return void
	 */
	public function testAllowedSchemesPass(): void {
		$enf = new EvidenceRetentionEnforcer();
		$enf->assertEvidenceUri(uri: 'docudesk:files/oninb/faillissementsvonnis-2026-04-12.pdf');
		$enf->assertEvidenceUri(uri: 'openregister:DunningRun/42:sha256=abc123');
		$enf->assertEvidenceUri(uri: 'postnl:3S1234567890123');
		$enf->assertEvidenceUri(uri: 'dunning-run:drun-77:sha256=def456');

		self::assertTrue(true, 'no exception → all four schemes accepted');

	}//end testAllowedSchemesPass()

	/**
	 * Task-25: empty URI fails closed.
	 *
	 * @return void
	 */
	public function testEmptyUriFailsClosed(): void {
		$this->expectException(InvalidArgumentException::class);
		(new EvidenceRetentionEnforcer())->assertEvidenceUri(uri: '');

	}//end testEmptyUriFailsClosed()

	/**
	 * Task-25: bare scheme without a locator fails closed.
	 *
	 * @return void
	 */
	public function testSchemeWithoutLocatorFailsClosed(): void {
		$this->expectException(InvalidArgumentException::class);
		(new EvidenceRetentionEnforcer())->assertEvidenceUri(uri: 'docudesk:');

	}//end testSchemeWithoutLocatorFailsClosed()

	/**
	 * Task-25: unknown scheme fails closed.
	 *
	 * @return void
	 */
	public function testUnknownSchemeFailsClosed(): void {
		$this->expectException(InvalidArgumentException::class);
		(new EvidenceRetentionEnforcer())->assertEvidenceUri(uri: 's3://bucket/key.pdf');

	}//end testUnknownSchemeFailsClosed()

	/**
	 * Task-25: validateEvidenceRefs surfaces every violation in one exception.
	 *
	 * @return void
	 */
	public function testValidateEvidenceRefsCollectsAllViolations(): void {
		$enf = new EvidenceRetentionEnforcer();
		try {
			$enf->validateEvidenceRefs(uris: [
				'docudesk:files/ok.pdf',
				's3://invalid',
				42,  // not a string
				'',
			]);
			self::fail('expected violations to throw');
		} catch (InvalidArgumentException $e) {
			$msg = $e->getMessage();
			self::assertStringContainsString('evidenceRefs[1]', $msg);
			self::assertStringContainsString('evidenceRefs[2]', $msg);
			self::assertStringContainsString('evidenceRefs[3]', $msg);
		}

	}//end testValidateEvidenceRefsCollectsAllViolations()

	/**
	 * Task-25: retentionPolicy returns a 7-year envelope keyed off `issuedAt`.
	 *
	 * @return void
	 */
	public function testRetentionPolicyReturnsSevenYearWindow(): void {
		$enf = new EvidenceRetentionEnforcer();
		$env = $enf->retentionPolicy(
			uri: 'docudesk:files/oninb/x.pdf',
			issuedAt: new DateTimeImmutable('2026-06-09')
		);

		self::assertSame(7, $env['retentionYears']);
		self::assertSame('2033-06-09', $env['deletionDate']);
		self::assertSame('docudesk:files/oninb/x.pdf', $env['sourceUri']);

	}//end testRetentionPolicyReturnsSevenYearWindow()

}//end class
