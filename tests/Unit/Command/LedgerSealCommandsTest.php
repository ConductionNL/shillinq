<?php

/**
 * The ledger seal occ commands (ledger-sealed-entries REQ-LSE-002, Migration Plan).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Command;

use OCA\Shillinq\Command\LedgerSealExistingCommand;
use OCA\Shillinq\Command\LedgerVerifySealCommand;
use OCA\Shillinq\Service\Ledger\LedgerSealService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * verify-seal reports the outcome and exits by it; seal-existing reports or refuses.
 */
#[CoversClass(LedgerVerifySealCommand::class)]
#[CoversClass(LedgerSealExistingCommand::class)]
final class LedgerSealCommandsTest extends TestCase {
	public function testAnIntactChainIsReportedWithItsLength(): void {
		$seals = $this->createMock(LedgerSealService::class);
		$seals->expects(self::once())->method('verify')->with('adm-voorbeeld')
			->willReturn(['intact' => true, 'entries' => 3, 'transactionNumber' => null, 'transactionId' => null, 'reason' => null]);
		$tester = new CommandTester(new LedgerVerifySealCommand($seals));

		self::assertSame(0, $tester->execute(['administrationId' => 'adm-voorbeeld']));
		self::assertSame("The ledger seal chain is intact over 3 entries.\n", $tester->getDisplay());
	}//end testAnIntactChainIsReportedWithItsLength()

	public function testABreakNamesTheTransactionAndFails(): void {
		$seals = $this->createMock(LedgerSealService::class);
		$seals->method('verify')->willReturnOnConsecutiveCalls(
			['intact' => false, 'entries' => 3, 'transactionNumber' => '2026-0902', 'transactionId' => 't2', 'reason' => 'content'],
			['intact' => false, 'entries' => 3, 'transactionNumber' => '', 'transactionId' => 't2', 'reason' => 'link']
		);
		$tester = new CommandTester(new LedgerVerifySealCommand($seals));

		self::assertSame(1, $tester->execute(['administrationId' => 'adm-voorbeeld']));
		self::assertSame("Transaction 2026-0902 is the first entry that no longer matches its seal (content).\n", $tester->getDisplay());
		self::assertSame(1, $tester->execute(['administrationId' => 'adm-voorbeeld']));
		self::assertStringContainsString('Transaction t2 is the first entry', $tester->getDisplay());
	}//end testABreakNamesTheTransactionAndFails()

	public function testTheGenesisPassReportsItsCountOrItsRefusal(): void {
		$seals = $this->createMock(LedgerSealService::class);
		$seals->method('sealExisting')->with('adm-voorbeeld')->willReturnOnConsecutiveCalls(
			3,
			self::throwException(new RuntimeException('ledger_already_sealed: administration adm-voorbeeld already has seals'))
		);
		$tester = new CommandTester(new LedgerSealExistingCommand($seals));

		self::assertSame(0, $tester->execute(['administrationId' => 'adm-voorbeeld']));
		self::assertSame("Sealed 3 posted transactions.\n", $tester->getDisplay());
		self::assertSame(1, $tester->execute(['administrationId' => 'adm-voorbeeld']));
		self::assertSame("ledger_already_sealed: administration adm-voorbeeld already has seals\n", $tester->getDisplay());
	}//end testTheGenesisPassReportsItsCountOrItsRefusal()
}//end class
