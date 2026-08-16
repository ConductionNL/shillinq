<?php

/**
 * Unit tests for the remaining EU-fondsen lifecycle guards.
 *
 * Covers IrregularityReportGuard (REQ-EUF-007 OLAF €10k IMS-meldplicht),
 * SegregatedLedgerGuard (REQ-EUF-002 zero-variance close),
 * SupportingDocumentGuard (REQ-EUF-004 SHA-256 certify), and AuditTrailGuard
 * (REQ-EUF-009 append-only immutability + event builder).
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
use OCA\Shillinq\Lifecycle\AuditTrailGuard;
use OCA\Shillinq\Lifecycle\IrregularityReportGuard;
use OCA\Shillinq\Lifecycle\SegregatedLedgerGuard;
use OCA\Shillinq\Lifecycle\SupportingDocumentGuard;
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
	 * AuditTrail records can never be modified or deleted (REQ-EUF-009).
	 *
	 * @return void
	 */
	public function testAuditTrailIsAppendOnly(): void {
		$guard = new AuditTrailGuard($this->createMock(LoggerInterface::class));

		// phpcs:disable CustomSniffs.Functions.NamedParameters
		self::assertFalse($guard->canModify('at-1', ['eventType' => 'booking']));
		self::assertFalse($guard->canDelete('at-1', ['eventType' => 'booking']));
		// phpcs:enable CustomSniffs.Functions.NamedParameters
	}//end testAuditTrailIsAppendOnly()

	/**
	 * buildEvent() constructs a well-formed event with before/after snapshots (REQ-EUF-009).
	 *
	 * @return void
	 */
	public function testBuildEventConstructsWellFormedRecord(): void {
		$guard = new AuditTrailGuard($this->createMock(LoggerInterface::class));

		$event = $guard->buildEvent(
			[
				'administrationId' => 'adm-1',
				'euProjectId' => 'eu-1',
				'eventType' => 'correction',
				'actorRole' => 'certificeringsautoriteit',
				'beforeState' => ['state' => 'in_audit'],
				'afterState' => ['state' => 'gecorrigeerd'],
				'euExpenditureId' => 'exp-1',
				'justification' => '5% financial correction per DG REGIO finding',
			]
		);

		self::assertSame('adm-1', $event['administrationId']);
		self::assertSame('correction', $event['eventType']);
		self::assertSame(['state' => 'in_audit'], $event['beforeState']);
		self::assertSame(['state' => 'gecorrigeerd'], $event['afterState']);
		self::assertArrayHasKey('timestamp', $event);
		// No BSN / natural-person leak when not supplied (ADR-005).
		self::assertArrayNotHasKey('actorNaturalPerson', $event);
	}//end testBuildEventConstructsWellFormedRecord()

	/**
	 * buildEvent() rejects an unknown event type (REQ-EUF-009 closed enum).
	 *
	 * @return void
	 */
	public function testBuildEventRejectsUnknownEventType(): void {
		$guard = new AuditTrailGuard($this->createMock(LoggerInterface::class));

		$this->expectException(\InvalidArgumentException::class);
		$guard->buildEvent(
			[
				'administrationId' => 'adm-1',
				'euProjectId' => 'eu-1',
				'eventType' => 'tampering',
				'actorRole' => 'controller',
			]
		);
	}//end testBuildEventRejectsUnknownEventType()
}//end class
