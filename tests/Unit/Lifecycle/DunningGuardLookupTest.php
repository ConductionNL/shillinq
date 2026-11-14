<?php

/**
 * Unit tests for DunningGuard reading its records itself.
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
use OCA\Shillinq\Lifecycle\DunningGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * When a transition hands over only an id, the guard reads the override, run,
 * pause or write-off itself; a failing read refuses. A B2C stage-3 run without
 * a party type takes it from the incasso cost calculation of its invoice.
 *
 * @covers \OCA\Shillinq\Lifecycle\DunningGuard
 * @uses   \OCA\Shillinq\Lifecycle\GuardObjectLookup
 * @uses   \OCA\Shillinq\Lifecycle\GuardSubject
 */
final class DunningGuardLookupTest extends TestCase {

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
	 * @return DunningGuard The guard.
	 */
	private function guard(string $register = 'books'): DunningGuard {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'register' ? $register : $default
		);

		return new DunningGuard($config, $this->logger, $this->objects);
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
	 * canActivateOverride reads the KlantLadderOverride in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanActivateOverrideReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'KlantLadderOverride', ['filters' => ['id' => 'x-1']], [7, ['overrides' => ['stages' => [['nr' => 2], 'x']], 'approvedBy' => 'anna']]);

		$this->assertTrue($this->guard()->canActivateOverride('x-1'));
	}//end testCanActivateOverrideReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canActivateOverride logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanActivateOverrideFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'DunningGuard: override activate check failed — denying transition (fail-closed)',
			['overrideId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canActivateOverride('x-1'));
	}//end testCanActivateOverrideFailsClosedWhenTheReadThrows()

	/**
	 * canExecuteRun reads the DunningRun in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanExecuteRunReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'DunningRun', ['filters' => ['id' => 'x-1']], [7, ['state' => 'draft', 'stageNr' => 1]]);

		$this->assertTrue($this->guard()->canExecuteRun('x-1'));
	}//end testCanExecuteRunReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canExecuteRun logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanExecuteRunFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'DunningGuard: run execute check failed — denying transition (fail-closed)',
			['runId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canExecuteRun('x-1'));
	}//end testCanExecuteRunFailsClosedWhenTheReadThrows()

	/**
	 * canResolvePause reads the DunningPauseDispute in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanResolvePauseReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'DunningPauseDispute', ['filters' => ['id' => 'x-1']], [7, ['pauseEnd' => '2026-03-01']]);

		$this->assertTrue($this->guard()->canResolvePause('x-1'));
	}//end testCanResolvePauseReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canResolvePause logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanResolvePauseFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'DunningGuard: pause resolve check failed — denying transition (fail-closed)',
			['pauseId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canResolvePause('x-1'));
	}//end testCanResolvePauseFailsClosedWhenTheReadThrows()

	/**
	 * canPostWriteOff reads the OninbaarAfschrijving in the configured register when given only an id.
	 *
	 * @return void
	 */
	public function testCanPostWriteOffReadsTheRecordWhenGivenOnlyAnId(): void {
		$this->expectRead('books', 'OninbaarAfschrijving', ['filters' => ['id' => 'x-1']], [7, ['art29OBDeclaration' => 'signed', 'principalDepreciated' => 0.01]]);

		$this->assertTrue($this->guard()->canPostWriteOff('x-1'));
	}//end testCanPostWriteOffReadsTheRecordWhenGivenOnlyAnId()

	/**
	 * canPostWriteOff logs a failing read and refuses.
	 *
	 * @return void
	 */
	public function testCanPostWriteOffFailsClosedWhenTheReadThrows(): void {
		$this->expectFailedRead(
			'DunningGuard: write-off post check failed — denying transition (fail-closed)',
			['writeOffId' => 'x-1', 'exception' => 'store down']
		);

		$this->assertFalse($this->guard()->canPostWriteOff('x-1'));
	}//end testCanPostWriteOffFailsClosedWhenTheReadThrows()

	/**
	 * A record that is not found as an array is refused, in the default register.
	 *
	 * @return void
	 */
	public function testANotFoundRecordIsRefused(): void {
		$this->expectRead('shillinq', 'KlantLadderOverride', ['filters' => ['id' => 'x-1']], ['broken']);

		$this->assertFalse($this->guard('')->canActivateOverride('x-1'));
	}//end testANotFoundRecordIsRefused()

	/**
	 * Every check refuses a blank id without a record, without reading.
	 *
	 * @return void
	 */
	public function testABlankIdWithoutARecordIsRefusedWithoutARead(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertFalse($guard->canActivateOverride(''), 'canActivateOverride');
		$this->assertFalse($guard->canExecuteRun(''), 'canExecuteRun');
		$this->assertFalse($guard->canResolvePause(''), 'canResolvePause');
		$this->assertFalse($guard->canPostWriteOff(''), 'canPostWriteOff');
	}//end testABlankIdWithoutARecordIsRefusedWithoutARead()

	/**
	 * A stage-3 run without a party type reads it from the first calculation of its invoice that has one.
	 *
	 * @return void
	 */
	public function testAStageThreeRunWithoutPartyTypeReadsItFromTheInvoiceCalculation(): void {
		$this->expectRead('books', 'IncassoKostenBerekening', ['filters' => ['invoiceId' => 'inv-1']], ['x', ['amount' => 1], ['partyType' => 'b2c']]);
		$run = ['stageNr' => '3', 'invoiceId' => 'inv-1', 'renderedBody' => 'U heeft nog 14 DAGEN om te betalen.'];

		$this->assertTrue($this->guard()->canExecuteRun('', $run), 'the fragment matches without regard to case');
	}//end testAStageThreeRunWithoutPartyTypeReadsItFromTheInvoiceCalculation()

	/**
	 * A stage-3 run that the invoice calculation marks B2C needs the fourteen-day letter.
	 *
	 * @return void
	 */
	public function testAB2cRunFromTheInvoiceWithoutTheLetterIsRefused(): void {
		$this->expectRead('books', 'IncassoKostenBerekening', ['filters' => ['invoiceId' => 'inv-1']], [['partyType' => 'B2C']]);

		$this->assertFalse($this->guard()->canExecuteRun('', ['stageNr' => 3, 'invoiceId' => 'inv-1', 'renderedBody' => 'Betaal nu.']));
	}//end testAB2cRunFromTheInvoiceWithoutTheLetterIsRefused()

	/**
	 * Without a party type and without an invoice, or with no calculation naming one, no letter is required.
	 *
	 * @return void
	 */
	public function testAStageThreeRunWithoutPartyTypeOrInvoiceNeedsNoLetter(): void {
		$this->expectRead('books', 'IncassoKostenBerekening', ['filters' => ['invoiceId' => 'inv-2']], [['amount' => 1]]);
		$guard = $this->guard();

		$this->assertTrue($guard->canExecuteRun('', ['stageNr' => 3, 'invoiceId' => 'inv-2']));
		$this->assertTrue($guard->canExecuteRun('', ['stageNr' => 3]));
	}//end testAStageThreeRunWithoutPartyTypeOrInvoiceNeedsNoLetter()

	/**
	 * A locked run is refused like an executed one; a run without state is a draft.
	 *
	 * @return void
	 */
	public function testALockedRunIsRefusedAndAMissingStateIsADraft(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertFalse($guard->canExecuteRun('', ['state' => 'locked']));
		$this->assertTrue($guard->canExecuteRun('', []));
	}//end testALockedRunIsRefusedAndAMissingStateIsADraft()

	/**
	 * An override that lists no stages, or reaches stage 5, skips nothing and needs no approver.
	 *
	 * @return void
	 */
	public function testAnOverrideWithoutAStageListActivatesWithoutApprover(): void {
		$this->expectNoRead();
		$guard = $this->guard();

		$this->assertTrue($guard->canActivateOverride('', ['overrides' => ['stages' => 'all']]));
		$this->assertTrue($guard->canActivateOverride('', ['overrides' => ['stages' => [['nr' => 5]]]]));
		$this->assertFalse($guard->canActivateOverride('', ['overrides' => ['stages' => [['nr' => 4]]], 'approvedBy' => ' ']));
	}//end testAnOverrideWithoutAStageListActivatesWithoutApprover()

	/**
	 * The B2C incasso day comes from config: the day before it blocks, the day itself does not, and B2B never blocks.
	 *
	 * @return void
	 */
	public function testTheB2cIncassoThresholdIsReadFromConfigAndIsExclusive(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->exactly(2))->method('getValueInt')->with('shillinq', 'dunning.b2c_incassokosten_dag', 44)->willReturn(30);
		$guard = new DunningGuard($config, $this->logger, $this->objects);

		$this->assertTrue($guard->blocksB2cIncassokosten('b2c', 29));
		$this->assertFalse($guard->blocksB2cIncassokosten('B2C', 30));
		$this->assertFalse($guard->blocksB2cIncassokosten('B2B', 1));
	}//end testTheB2cIncassoThresholdIsReadFromConfigAndIsExclusive()

	/**
	 * A write-off with a declaration but nothing depreciated is refused.
	 *
	 * @return void
	 */
	public function testAWriteOffNeedsAPositiveDepreciatedPrincipal(): void {
		$this->expectNoRead();

		$this->assertFalse($this->guard()->canPostWriteOff('', ['art29OBDeclaration' => 'signed', 'principalDepreciated' => 0]));
	}//end testAWriteOffNeedsAPositiveDepreciatedPrincipal()
}//end class
