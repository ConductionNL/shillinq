<?php

/**
 * Unit tests for CbcrPillar2Guard reading its records itself.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\CbcrPillar2Guard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * When a transition hands over only an id, the guard reads the CbCR or Pillar 2
 * record itself; a failing read refuses, and the boundaries are exact.
 *
 * @covers \OCA\Shillinq\Lifecycle\CbcrPillar2Guard
 * @uses   \OCA\Shillinq\Lifecycle\GuardObjectLookup
 * @uses   \OCA\Shillinq\Lifecycle\GuardSubject
 */
final class CbcrPillar2GuardLookupTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The guard, over a configured register.
	 *
	 * @param string $register The configured register slug.
	 *
	 * @return CbcrPillar2Guard The guard.
	 */
	private function guard(string $register = 'books'): CbcrPillar2Guard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? $register : $default
		);

		return new CbcrPillar2Guard($config, $this->logger, $this->objects);
	}//end guard()

	/**
	 * Expect one read of this schema in this register, answering these records.
	 *
	 * @param string               $register The register expected.
	 * @param string               $schema   The schema expected.
	 * @param array<string, mixed> $query    The query expected.
	 * @param array<int, mixed>    $records  The records to answer.
	 *
	 * @return void
	 */
	private function expectRead(string $register, string $schema, array $query, array $records): void {
		$this->objects->expects($this->once())->method('setRegister')->with($register)->willReturnSelf();
		$this->objects->expects($this->once())->method('setSchema')->with($schema)->willReturnSelf();
		$this->objects->expects($this->once())->method('findAll')->with($query)->willReturn($records);
	}//end expectRead()

	/**
	 * Expect no read at all.
	 *
	 * @return void
	 */
	private function expectNoRead(): void {
		$this->objects->expects($this->never())->method('findAll');
	}//end expectNoRead()

	/**
	 * Expect a read that throws, and one fail-closed log line with this message.
	 *
	 * @param string               $message The logged message.
	 * @param array<string, mixed> $context The logged context.
	 *
	 * @return void
	 */
	private function expectFailedRead(string $message, array $context): void {
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('findAll')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('error')->with($message, $context);
	}//end expectFailedRead()

	/**
	 * canReconcileSummary reads the CbcrJurisdictionSummary in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanReconcileSummaryReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'CbcrJurisdictionSummary', ['filters' => ['id' => 'x-1']], [7, ['period' => '2025', 'jurisdiction' => 'NL', 'unrelatedPartyRevenue' => 60, 'relatedPartyRevenue' => 40, 'totalRevenue' => 100]]);

		$this->assertTrue($this->guard()->canReconcileSummary('x-1'));
	}//end testCanReconcileSummaryReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canReconcileSummary logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanReconcileSummaryFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CbcrPillar2Guard: summary reconcile check failed — denying transition (fail-closed)',
			['summaryId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canReconcileSummary('x-1'));
	}//end testCanReconcileSummaryFailsClosedWhenTheReadThrows()

	/**
	 * canApproveComputation reads the Pillar2JurisdictionComputation in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanApproveComputationReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'Pillar2JurisdictionComputation', ['filters' => ['id' => 'x-1']], [7, ['jurisdiction' => 'nl', 'etrJurisdiction' => 0.1, 'topUpTaxAmount' => 5, 'qdmttAmount' => 5]]);

		$this->assertTrue($this->guard()->canApproveComputation('x-1'));
	}//end testCanApproveComputationReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canApproveComputation logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanApproveComputationFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CbcrPillar2Guard: computation approve check failed — denying transition (fail-closed)',
			['computationId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canApproveComputation('x-1'));
	}//end testCanApproveComputationFailsClosedWhenTheReadThrows()

	/**
	 * canSubmitQdmtt reads the QdmttReturn in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanSubmitQdmttReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'QdmttReturn', ['filters' => ['id' => 'x-1']], [7, ['period' => '2025', 'entity' => 'e1', 'qdmttPayable' => 0]]);

		$this->assertTrue($this->guard()->canSubmitQdmtt('x-1'));
	}//end testCanSubmitQdmttReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canSubmitQdmtt logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanSubmitQdmttFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CbcrPillar2Guard: QDMTT submit check failed — denying transition (fail-closed)',
			['returnId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canSubmitQdmtt('x-1'));
	}//end testCanSubmitQdmttFailsClosedWhenTheReadThrows()

	/**
	 * canReconcileCbcrReturn reads the CbcrReturn in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanReconcileCbcrReturnReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'CbcrReturn', ['filters' => ['id' => 'x-1']], [7, ['reconciliationResidual' => -1000000]]);

		$this->assertTrue($this->guard()->canReconcileCbcrReturn('x-1'));
	}//end testCanReconcileCbcrReturnReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canReconcileCbcrReturn logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanReconcileCbcrReturnFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CbcrPillar2Guard: CbCR return reconcile check failed — denying transition (fail-closed)',
			['returnId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canReconcileCbcrReturn('x-1'));
	}//end testCanReconcileCbcrReturnFailsClosedWhenTheReadThrows()

	/**
	 * A record that is not found as an array is refused, in the default register.
	 *
	 * @return void
	 */
	public function testANotFoundRecordIsRefused(): void {
		$this->expectRead('shillinq', 'CbcrJurisdictionSummary', ['filters' => ['id' => 'x-1']], ['broken']);

		$this->assertFalse($this->guard('')->canReconcileSummary('x-1'));
	}//end testANotFoundRecordIsRefused()

	/**
	 * Every check refuses a blank id without a record, without reading.
	 *
	 * @return void
	 */
	public function testABlankIdWithoutARecordIsRefusedWithoutARead(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertFalse($guard->canReconcileSummary(''), 'canReconcileSummary');
		$this->assertFalse($guard->canApproveComputation(''), 'canApproveComputation');
		$this->assertFalse($guard->canSubmitQdmtt(''), 'canSubmitQdmtt');
		$this->assertFalse($guard->canReconcileCbcrReturn(''), 'canReconcileCbcrReturn');
	}//end testABlankIdWithoutARecordIsRefusedWithoutARead()

	/**
	 * A cent of rounding ties out, a missing jurisdiction does not, a lower-case NL counts as NL, a missing payable does not submit, and a large negative residual needs items.
	 *
	 * @return void
	 */
	public function testTheBoundariesOfEachCheck(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertTrue($guard->canReconcileSummary('', ['period' => 'p', 'jurisdiction' => 'DE', 'unrelatedPartyRevenue' => 60, 'relatedPartyRevenue' => 40, 'totalRevenue' => 100.009]));
		$this->assertFalse($guard->canReconcileSummary('', ['period' => 'p', 'unrelatedPartyRevenue' => 0, 'totalRevenue' => 0]));
		$this->assertFalse($guard->canApproveComputation('', ['jurisdiction' => 'nl', 'etrJurisdiction' => 0.1, 'topUpTaxAmount' => 5]));
		$this->assertTrue($guard->canApproveComputation('', ['jurisdiction' => 'NL', 'etrJurisdiction' => 0.149, 'minimumRate' => 0.1, 'topUpTaxAmount' => 5]));
		$this->assertFalse($guard->canSubmitQdmtt('', ['period' => 'p', 'entity' => 'e']));
		$this->assertFalse($guard->canReconcileCbcrReturn('', ['reconciliationResidual' => -1000000.01, 'reconciliationItems' => 'x']));
		$this->assertTrue($guard->canReconcileCbcrReturn('', ['reconciliationResidual' => -1000000.01, 'reconciliationItems' => [['why' => 'fx']]]));
	}//end testTheBoundariesOfEachCheck()
}//end class
