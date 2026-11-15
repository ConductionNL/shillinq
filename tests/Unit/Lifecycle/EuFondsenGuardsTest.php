<?php

/**
 * Unit tests for the remaining EU-fondsen lifecycle guards.
 *
 * Covers IrregularityReportGuard (REQ-EUF-007 OLAF €10k IMS-meldplicht),
 * SegregatedLedgerGuard (REQ-EUF-002 zero-variance close),
 * SupportingDocumentGuard (REQ-EUF-004 SHA-256 certify), and the AuditTrail
 * schema (REQ-EUF-009: append-only in OpenRegister, required fields, closed enum).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-single-audit-eu-fondsen/specs/bookkeeping-single-audit-eu-fondsen/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\IrregularityReportGuard;
use OCA\Shillinq\Lifecycle\SegregatedLedgerGuard;
use OCA\Shillinq\Lifecycle\SupportingDocumentGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the four single-purpose EU-fondsen guards.
 */
class EuFondsenGuardsTest extends TestCase {

	/**
	 * Build an IAppConfig stub returning the register slug.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		return $appConfig;
	}//end appConfig()

	/**
	 * Build a container stub (never used when object is supplied inline).
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		return $this->createMock(ContainerInterface::class);
	}//end container()

	/**
	 * An irregularity >= €10k with an IMS-reference may escalate (REQ-EUF-007).
	 *
	 * @return void
	 */
	public function testIrregularityAtThresholdWithImsReferenceCanEscalate(): void {
		$guard = new IrregularityReportGuard( $this->appConfig(), $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue(
			$guard->canEscalate('irr-1', ['amountConcerned' => 15400.0, 'imsReference' => 'IMS-2026-0001'])
		);
	}//end testIrregularityAtThresholdWithImsReferenceCanEscalate()

	/**
	 * An irregularity >= €10k WITHOUT an IMS-reference is blocked (REQ-EUF-007).
	 *
	 * @return void
	 */
	public function testIrregularityAtThresholdWithoutImsReferenceCannotEscalate(): void {
		$guard = new IrregularityReportGuard( $this->appConfig(), $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse(
			$guard->canEscalate('irr-2', ['amountConcerned' => 15400.0, 'imsReference' => ''])
		);
	}//end testIrregularityAtThresholdWithoutImsReferenceCannotEscalate()

	/**
	 * An irregularity below €10k escalates without an IMS-reference (REQ-EUF-007).
	 *
	 * @return void
	 */
	public function testIrregularityBelowThresholdEscalatesUnconditionally(): void {
		$guard = new IrregularityReportGuard( $this->appConfig(), $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue(
			$guard->canEscalate('irr-3', ['amountConcerned' => 4200.0])
		);
	}//end testIrregularityBelowThresholdEscalatesUnconditionally()

	/**
	 * A segregated ledger with zero variance may close (REQ-EUF-002).
	 *
	 * @return void
	 */
	public function testReconciledLedgerCanClose(): void {
		$guard = new SegregatedLedgerGuard( $this->appConfig(), $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($guard->canClose('led-1', ['reconciliationVariance' => 0.0]));
	}//end testReconciledLedgerCanClose()

	/**
	 * A segregated ledger with non-zero variance is blocked (REQ-EUF-002).
	 *
	 * @return void
	 */
	public function testUnreconciledLedgerCannotClose(): void {
		$guard = new SegregatedLedgerGuard( $this->appConfig(), $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($guard->canClose('led-2', ['reconciliationVariance' => 12.50]));
	}//end testUnreconciledLedgerCannotClose()

	/**
	 * Variance derived from equal balance fields permits close (REQ-EUF-002).
	 *
	 * @return void
	 */
	public function testLedgerWithEqualBalancesCanClose(): void {
		$guard = new SegregatedLedgerGuard( $this->appConfig(), $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue(
			$guard->canClose('led-3', ['regularGlBalanceEur' => 12500.00, 'euAdministrationBalanceEur' => 12500.00])
		);
	}//end testLedgerWithEqualBalancesCanClose()

	/**
	 * A bewijsstuk with a valid SHA-256 hash may be certified (REQ-EUF-004).
	 *
	 * @return void
	 */
	public function testDocumentWithValidHashCanCertify(): void {
		$guard = new SupportingDocumentGuard( $this->appConfig(), $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
		$hash = str_repeat('a', 64);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertTrue($guard->canCertify('doc-1', ['sha256Hash' => $hash]));
	}//end testDocumentWithValidHashCanCertify()

	/**
	 * A bewijsstuk with a malformed hash cannot be certified (REQ-EUF-004 / CWE-863).
	 *
	 * @return void
	 */
	public function testDocumentWithMalformedHashCannotCertify(): void {
		$guard = new SupportingDocumentGuard( $this->appConfig(), $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($guard->canCertify('doc-2', ['sha256Hash' => 'not-a-hash']));
	}//end testDocumentWithMalformedHashCannotCertify()

	/**
	 * A bewijsstuk with no hash cannot be certified (REQ-EUF-004).
	 *
	 * @return void
	 */
	public function testDocumentWithoutHashCannotCertify(): void {
		$guard = new SupportingDocumentGuard( $this->appConfig(), $this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// phpcs:ignore CustomSniffs.Functions.NamedParameters
		self::assertFalse($guard->canCertify('doc-3', []));
	}//end testDocumentWithoutHashCannotCertify()

	/**
	 * AuditTrail records can never be modified or deleted (REQ-EUF-009), and
	 * OpenRegister is what refuses it: the schema declares `appendOnly`, so
	 * ObjectService answers an update or a delete with SCHEMA_APPEND_ONLY (405).
	 * The app-local AuditTrailGuard that said so was never called by anything.
	 *
	 * @return void
	 */
	public function testAuditTrailIsAppendOnlyInOpenRegister(): void {
		self::assertTrue(RegisterSchema::schema(slug: 'AuditTrail')['appendOnly'] ?? false);
	}//end testAuditTrailIsAppendOnlyInOpenRegister()

	/**
	 * A well-formed EU-fondsen event is accepted by the register as it stands.
	 *
	 * @return void
	 */
	public function testAuditTrailAcceptsAWellFormedEvent(): void {
		self::assertSame([], RegisterSchema::errors(slug: 'AuditTrail', object: $this->auditEvent()));
	}//end testAuditTrailAcceptsAWellFormedEvent()

	/**
	 * The register refuses an event without its project, the check the
	 * guard's builder made in PHP (REQ-EUF-009).
	 *
	 * @return void
	 */
	public function testAuditTrailRefusesAnEventWithoutItsProject(): void {
		$event = $this->auditEvent();
		unset($event['euProjectId']);

		self::assertNotSame([], RegisterSchema::errors(slug: 'AuditTrail', object: $event));
	}//end testAuditTrailRefusesAnEventWithoutItsProject()

	/**
	 * The register refuses an unknown event type (REQ-EUF-009 closed enum).
	 *
	 * @return void
	 */
	public function testAuditTrailRefusesAnUnknownEventType(): void {
		$event = $this->auditEvent();
		$event['eventType'] = 'tampering';

		self::assertNotSame([], RegisterSchema::errors(slug: 'AuditTrail', object: $event));
	}//end testAuditTrailRefusesAnUnknownEventType()

	/**
	 * A correction event as an EU-fondsen transition writes it.
	 *
	 * @return array<string,mixed>
	 */
	private function auditEvent(): array {
		return [
			'administrationId' => '7c0e4a3e-2f7b-4c55-9c39-8a1d1b8b6f10',
			'euProjectId' => '0b6f0a2c-6a8e-4c43-8a7e-3f2b9c1d4e21',
			'eventType' => 'correction',
			'actorRole' => 'certificeringsautoriteit',
			'timestamp' => '2026-10-04T10:00:00+00:00',
			'beforeState' => ['state' => 'in_audit'],
			'afterState' => ['state' => 'gecorrigeerd'],
			'justification' => '5% financial correction per DG REGIO finding',
		];
	}//end auditEvent()
}//end class
