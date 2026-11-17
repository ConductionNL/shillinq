<?php

/**
 * Unit tests for BookCapturesCommand.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Command;

use OCA\Shillinq\Command\BookCapturesCommand;
use OCA\Shillinq\Kernel\Contracts\RegisterLocationInterface;
use OCA\Shillinq\Service\Payout\CaptureBookingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ shillinq:payments:book-captures`: lists, and with --book books, the unbooked captures.
 *
 * @covers \OCA\Shillinq\Command\BookCapturesCommand
 *
 * @spec openspec/changes/receivables-provider-payouts/tasks.md#task-2.3
 */
final class BookCapturesCommandTest extends TestCase {

	/**
	 * The capture booking.
	 *
	 * @var CaptureBookingService&MockObject
	 */
	private CaptureBookingService $booking;

	/**
	 * Two unbooked captures in the shillinq register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->booking = $this->createMock(CaptureBookingService::class);
		$this->booking->method('unbooked')->with('shillinq')->willReturn(
			[
				['requestId' => 'pr-1', 'paymentId' => 'tr_1', 'invoiceReference' => 'inv-1', 'amount' => 145.2, 'capturedAt' => '2026-09-01'],
				['requestId' => 'pr-2', 'paymentId' => 'tr_2', 'invoiceReference' => 'inv-2', 'amount' => 60.0, 'capturedAt' => '2026-09-30'],
			]
		);
	}//end setUp()

	/**
	 * The command over the mocked booking.
	 *
	 * @return CommandTester
	 */
	private function tester(): CommandTester {
		$location = $this->createConfiguredMock(RegisterLocationInterface::class, ['getRegisterSlug' => 'shillinq']);
		$command = new BookCapturesCommand(registerLocation: $location, captureBooking: $this->booking);
		$this->assertSame('shillinq:payments:book-captures', $command->getName());

		return new CommandTester($command);
	}//end tester()

	/**
	 * Without --book it lists and books nothing.
	 *
	 * @return void
	 */
	public function testWithoutBookItOnlyLists(): void {
		$this->booking->expects($this->never())->method('bookRequest');
		$tester = $this->tester();

		$this->assertSame(0, $tester->execute([]));
		$display = $tester->getDisplay();
		$this->assertStringContainsString('2 provider-paid invoice(s) without a capture booking', $display);
		$this->assertMatchesRegularExpression('/2026-09-01\s+tr_1\s+invoice inv-1\s+145\.20/', $display);
		$this->assertMatchesRegularExpression('/2026-09-30\s+tr_2\s+invoice inv-2\s+60\.00/', $display);
		$this->assertStringContainsString('Run again with --book to book them.', $display);
	}//end testWithoutBookItOnlyLists()

	/**
	 * With --book every listed request is booked; a failed one is named and the command fails.
	 *
	 * @return void
	 */
	public function testWithBookEachCaptureIsBookedAndAFailureFails(): void {
		$this->booking->expects($this->exactly(2))->method('bookRequest')->willReturnMap(
			[
				['shillinq', 'pr-1', 'je-1'],
				['shillinq', 'pr-2', null],
			]
		);
		$tester = $this->tester();

		$this->assertSame(1, $tester->execute(['--book' => true]));
		$display = $tester->getDisplay();
		$this->assertMatchesRegularExpression('/tr_1.*booked je-1/', $display);
		$this->assertMatchesRegularExpression('/tr_2.*not booked/', $display);
		$this->assertStringContainsString('1 capture(s) could not be booked', $display);
		$this->assertStringNotContainsString('Run again', $display);
	}//end testWithBookEachCaptureIsBookedAndAFailureFails()
}//end class
