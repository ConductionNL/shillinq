<?php

/**
 * Unit tests for CsrdEsrsGuard reading its records itself.
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
use OCA\Shillinq\Lifecycle\CsrdEsrsGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * When a transition hands over only an id, the guard reads the materiality
 * assessment, data point or assurance engagement itself; a failing read
 * refuses, and malformed entries refuse too.
 *
 * @covers \OCA\Shillinq\Lifecycle\CsrdEsrsGuard
 * @uses   \OCA\Shillinq\Lifecycle\GuardObjectLookup
 * @uses   \OCA\Shillinq\Lifecycle\GuardSubject
 */
final class CsrdEsrsGuardLookupTest extends TestCase {

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
	 * @return CsrdEsrsGuard The guard.
	 */
	private function guard(string $register = 'books'): CsrdEsrsGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? $register : $default
		);

		return new CsrdEsrsGuard($config, $this->logger, $this->objects);
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
	 * canSubmitMateriality reads the MaterialityAssessment in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanSubmitMaterialityReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'MaterialityAssessment', ['filters' => ['id' => 'x-1']], [7, ['stakeholderGroupsConsulted' => [['group' => 'staff', 'consultationMethod' => 'survey', 'date' => '2026-01-01']]]]);

		$this->assertTrue($this->guard()->canSubmitMateriality('x-1'));
	}//end testCanSubmitMaterialityReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canSubmitMateriality logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanSubmitMaterialityFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CsrdEsrsGuard: materiality submit check failed — denying transition (fail-closed)',
			['assessmentId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canSubmitMateriality('x-1'));
	}//end testCanSubmitMaterialityFailsClosedWhenTheReadThrows()

	/**
	 * canApproveMateriality reads the MaterialityAssessment in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanApproveMaterialityReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'MaterialityAssessment', ['filters' => ['id' => 'x-1']], [7, ['approver' => 'anna', 'doubleMaterialityMatrixSnapshot' => [['material' => true]]]]);

		$this->assertTrue($this->guard()->canApproveMateriality('x-1'));
	}//end testCanApproveMaterialityReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canApproveMateriality logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanApproveMaterialityFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CsrdEsrsGuard: materiality approve check failed — denying transition (fail-closed)',
			['assessmentId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canApproveMateriality('x-1'));
	}//end testCanApproveMaterialityFailsClosedWhenTheReadThrows()

	/**
	 * canApproveDataPoint reads the EsrsDataPoint in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanApproveDataPointReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'EsrsDataPoint', ['filters' => ['id' => 'x-1']], [7, ['sourceReference' => 'ledger:2025']]);

		$this->assertTrue($this->guard()->canApproveDataPoint('x-1'));
	}//end testCanApproveDataPointReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canApproveDataPoint logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanApproveDataPointFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CsrdEsrsGuard: data-point approve check failed — denying transition (fail-closed)',
			['dataPointId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canApproveDataPoint('x-1'));
	}//end testCanApproveDataPointFailsClosedWhenTheReadThrows()

	/**
	 * canRestateDataPoint reads the EsrsDataPoint in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanRestateDataPointReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'EsrsDataPoint', ['filters' => ['id' => 'x-1']], [7, ['restatedFrom' => 'dp-0', 'restatementRationale' => 'Error found']]);

		$this->assertTrue($this->guard()->canRestateDataPoint('x-1'));
	}//end testCanRestateDataPointReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canRestateDataPoint logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanRestateDataPointFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CsrdEsrsGuard: data-point restate check failed — denying transition (fail-closed)',
			['dataPointId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canRestateDataPoint('x-1'));
	}//end testCanRestateDataPointFailsClosedWhenTheReadThrows()

	/**
	 * canIssueOpinion reads the AssuranceEngagement in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanIssueOpinionReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'AssuranceEngagement', ['filters' => ['id' => 'x-1']], [7, ['findings' => [['status' => 'resolved'], ['status' => 'accepted-risk']]]]);

		$this->assertTrue($this->guard()->canIssueOpinion('x-1'));
	}//end testCanIssueOpinionReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canIssueOpinion logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanIssueOpinionFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CsrdEsrsGuard: issue-opinion check failed — denying transition (fail-closed)',
			['engagementId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canIssueOpinion('x-1'));
	}//end testCanIssueOpinionFailsClosedWhenTheReadThrows()

	/**
	 * A record that is not found as an array is refused, in the default register.
	 *
	 * @return void
	 */
	public function testANotFoundRecordIsRefused(): void {
		$this->expectRead('shillinq', 'MaterialityAssessment', ['filters' => ['id' => 'x-1']], ['broken']);

		$this->assertFalse($this->guard('')->canSubmitMateriality('x-1'));
	}//end testANotFoundRecordIsRefused()

	/**
	 * Every check refuses a blank id without a record, without reading.
	 *
	 * @return void
	 */
	public function testABlankIdWithoutARecordIsRefusedWithoutARead(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertFalse($guard->canSubmitMateriality(''), 'canSubmitMateriality');
		$this->assertFalse($guard->canApproveMateriality(''), 'canApproveMateriality');
		$this->assertFalse($guard->canApproveDataPoint(''), 'canApproveDataPoint');
		$this->assertFalse($guard->canRestateDataPoint(''), 'canRestateDataPoint');
		$this->assertFalse($guard->canIssueOpinion(''), 'canIssueOpinion');
	}//end testABlankIdWithoutARecordIsRefusedWithoutARead()

	/**
	 * A stakeholder group, matrix entry or finding that is not an array refuses, as does a non-array list.
	 *
	 * @return void
	 */
	public function testMalformedEntriesRefuse(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertFalse($guard->canSubmitMateriality('', ['stakeholderGroupsConsulted' => ['staff']]));
		$this->assertFalse($guard->canSubmitMateriality('', ['stakeholderGroupsConsulted' => 'staff']));
		$this->assertFalse($guard->canApproveMateriality('', ['approver' => 'anna', 'doubleMaterialityMatrixSnapshot' => ['x']]));
		$this->assertFalse($guard->canApproveMateriality('', ['approver' => 'anna', 'doubleMaterialityMatrixSnapshot' => 'x']));
		$this->assertTrue($guard->canApproveMateriality('', ['approver' => 'anna', 'doubleMaterialityMatrixSnapshot' => [['material' => false, 'rationale' => 'Not relevant']]]));
		$this->assertFalse($guard->canIssueOpinion('', ['findings' => ['resolved']]));
		$this->assertFalse($guard->canIssueOpinion('', ['findings' => 'none']));
		$this->assertFalse($guard->canIssueOpinion('', ['findings' => [[]]]), 'a finding without status is open');
	}//end testMalformedEntriesRefuse()

	/**
	 * A restatement with a blank source or a blank rationale is refused.
	 *
	 * @return void
	 */
	public function testARestatementNeedsBothFieldsWithContent(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertFalse($guard->canRestateDataPoint('', ['restatedFrom' => ' ', 'restatementRationale' => 'r']));
		$this->assertFalse($guard->canRestateDataPoint('', ['restatedFrom' => 'dp-0', 'restatementRationale' => ' ']));
		$this->assertFalse($guard->canApproveDataPoint('', ['sourceReference' => ' ']));
	}//end testARestatementNeedsBothFieldsWithContent()
}//end class
