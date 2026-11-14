<?php

/**
 * Unit tests for CcmFindingGuard reading the finding or report itself.
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
use OCA\Shillinq\Lifecycle\CcmFindingGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * When a transition hands over only an id, the guard reads the CCM finding or
 * audit-committee report itself; a failing read refuses.
 *
 * @covers \OCA\Shillinq\Lifecycle\CcmFindingGuard
 * @uses   \OCA\Shillinq\Lifecycle\GuardObjectLookup
 * @uses   \OCA\Shillinq\Lifecycle\GuardSubject
 */
final class CcmFindingGuardLookupTest extends TestCase {

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
	 * @return CcmFindingGuard The guard.
	 */
	private function guard(string $register = 'books'): CcmFindingGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? $register : $default
		);

		return new CcmFindingGuard($config, $this->logger, $this->objects);
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
	 * canDismiss reads the CcmFinding in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanDismissReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'CcmFinding', ['filters' => ['id' => 'x-1']], [7, ['resolutionRationale' => 'False positive']]);

		$this->assertTrue($this->guard()->canDismiss('x-1'));
	}//end testCanDismissReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canDismiss logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanDismissFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CcmFindingGuard: dismiss check failed — denying transition (fail-closed)',
			['findingId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canDismiss('x-1'));
	}//end testCanDismissFailsClosedWhenTheReadThrows()

	/**
	 * canConfirm reads the CcmFinding in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanConfirmReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'CcmFinding', ['filters' => ['id' => 'x-1']], [7, ['resolutionRationale' => 'Confirmed by controller']]);

		$this->assertTrue($this->guard()->canConfirm('x-1'));
	}//end testCanConfirmReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canConfirm logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanConfirmFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CcmFindingGuard: confirm check failed — denying transition (fail-closed)',
			['findingId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canConfirm('x-1'));
	}//end testCanConfirmFailsClosedWhenTheReadThrows()

	/**
	 * canApproveReport reads the CcmAuditCommitteeReport in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanApproveReportReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'CcmAuditCommitteeReport', ['filters' => ['id' => 'x-1']], [7, ['approver' => 'anna', 'executiveSummary' => 'Two findings']]);

		$this->assertTrue($this->guard()->canApproveReport('x-1'));
	}//end testCanApproveReportReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canApproveReport logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanApproveReportFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'CcmFindingGuard: report approve check failed — denying transition (fail-closed)',
			['reportId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canApproveReport('x-1'));
	}//end testCanApproveReportFailsClosedWhenTheReadThrows()

	/**
	 * A record that is not found as an array is refused, in the default register.
	 *
	 * @return void
	 */
	public function testANotFoundRecordIsRefused(): void {
		$this->expectRead('shillinq', 'CcmFinding', ['filters' => ['id' => 'x-1']], ['broken']);

		$this->assertFalse($this->guard('')->canDismiss('x-1'));
	}//end testANotFoundRecordIsRefused()

	/**
	 * Every check refuses a blank id without a record, without reading.
	 *
	 * @return void
	 */
	public function testABlankIdWithoutARecordIsRefusedWithoutARead(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertFalse($guard->canDismiss(''), 'canDismiss');
		$this->assertFalse($guard->canConfirm(''), 'canConfirm');
		$this->assertFalse($guard->canApproveReport(''), 'canApproveReport');
	}//end testABlankIdWithoutARecordIsRefusedWithoutARead()

	/**
	 * A rationale, approver or summary of only whitespace is missing.
	 *
	 * @return void
	 */
	public function testWhitespaceDoesNotCountAsARationaleApproverOrSummary(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertFalse($guard->canDismiss('', ['resolutionRationale' => "  \t"]));
		$this->assertFalse($guard->canConfirm('', ['resolutionRationale' => ' ']));
		$this->assertFalse($guard->canApproveReport('', ['approver' => ' ', 'executiveSummary' => 'S']));
		$this->assertFalse($guard->canApproveReport('', ['approver' => 'anna', 'executiveSummary' => ' ']));
	}//end testWhitespaceDoesNotCountAsARationaleApproverOrSummary()
}//end class
