<?php

/**
 * LedgerSealCanonicaliser unit tests: the sealed content and the seal, pinned
 * to fixed hashes so a drift between PHP versions shows (ledger-sealed-entries
 * design, Risks).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Ledger;

use OCA\Shillinq\Service\Ledger\LedgerSealCanonicaliser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The canonical posted content and the chained SHA-256 seal.
 */
#[CoversClass(LedgerSealCanonicaliser::class)]
final class LedgerSealCanonicaliserTest extends TestCase {
	/**
	 * Purchase posting 2026-0902 of Gemeente Voorbeeld (design, Seed Data).
	 *
	 * @return array<string,mixed>
	 */
	private static function purchase(): array {
		return [
			'administrationId' => 'adm-voorbeeld',
			'transactionNumber' => '2026-0902',
			'postingDate' => '2026-09-12',
			'periodId' => '2026-09',
			'currency' => 'EUR',
			'description' => 'Inkoopfactuur kantoorartikelen',
			'sourceReference' => 'INK-0902',
			'journalEntryId' => null,
			'reversesTransactionId' => null,
			'state' => 'posted',
		];
	}//end purchase()

	/**
	 * Its three lines, deliberately out of order and in mixed amount types.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function lines(): array {
		return [
			['lineNumber' => 2, 'accountNumber' => '1600', 'side' => 'credit', 'amount' => '363.00', 'currency' => 'EUR'],
			['lineNumber' => 1, 'accountNumber' => '4100', 'side' => 'debit', 'amount' => '300.00', 'currency' => 'EUR', 'dimensions' => ['z' => 1, 'a' => 2]],
			['lineNumber' => 3, 'accountNumber' => '1520', 'side' => 'debit', 'amount' => 63, 'currency' => 'EUR'],
		];
	}//end lines()

	public function testTheCanonicalContentIsSortedCompactAndInCents(): void {
		$canonical = (new LedgerSealCanonicaliser())->canonical(transaction: self::purchase(), lines: self::lines());

		self::assertSame(
			'{"administrationId":"adm-voorbeeld","currency":"EUR","description":"Inkoopfactuur kantoorartikelen","journalEntryId":null,'
			.'"lines":[{"accountNumber":"4100","amount":30000,"costCarrierCode":null,"costCenterCode":null,"currency":"EUR","dimensions":{"a":2,"z":1},"projectCode":null,"side":"debit"},'
			.'{"accountNumber":"1600","amount":36300,"costCarrierCode":null,"costCenterCode":null,"currency":"EUR","dimensions":null,"projectCode":null,"side":"credit"},'
			.'{"accountNumber":"1520","amount":6300,"costCarrierCode":null,"costCenterCode":null,"currency":"EUR","dimensions":null,"projectCode":null,"side":"debit"}],'
			.'"periodId":"2026-09","postingDate":"2026-09-12","reversesTransactionId":null,"sourceReference":"INK-0902","transactionNumber":"2026-0902"}',
			$canonical
		);
	}//end testTheCanonicalContentIsSortedCompactAndInCents()

	public function testTheFirstSealChainsOntoGenesisWithAFixedHash(): void {
		$sealer = new LedgerSealCanonicaliser();
		$canonical = $sealer->canonical(transaction: self::purchase(), lines: self::lines());

		self::assertSame(
			'9f0303dd1f7cfebf26d06599aa51ab6a28452711b69fece835cd382380367f2d',
			$sealer->seal(previousSealHash: LedgerSealCanonicaliser::GENESIS, canonical: $canonical)
		);
		self::assertSame(str_repeat('0', 64), LedgerSealCanonicaliser::GENESIS);
	}//end testTheFirstSealChainsOntoGenesisWithAFixedHash()

	public function testAReversalKeepsTheSealAndMetadataIsIgnored(): void {
		$sealer = new LedgerSealCanonicaliser();
		$posted = $sealer->canonical(transaction: self::purchase(), lines: self::lines());

		$reversed = self::purchase();
		$reversed['state'] = 'reversed';
		$reversed['@self'] = ['updated' => '2026-10-01T00:00:00+00:00'];
		$reversed['sealHash'] = 'abc';

		self::assertSame($posted, $sealer->canonical(transaction: $reversed, lines: array_reverse(self::lines())));
	}//end testAReversalKeepsTheSealAndMetadataIsIgnored()

	public function testAChangedAmountOrPredecessorChangesTheSeal(): void {
		$sealer = new LedgerSealCanonicaliser();
		$lines = self::lines();
		$canonical = $sealer->canonical(transaction: self::purchase(), lines: $lines);
		$lines[2]['amount'] = '63.01';
		$tampered = $sealer->canonical(transaction: self::purchase(), lines: $lines);

		self::assertNotSame($canonical, $tampered);
		self::assertNotSame(
			$sealer->seal(previousSealHash: LedgerSealCanonicaliser::GENESIS, canonical: $canonical),
			$sealer->seal(previousSealHash: str_repeat('1', 64), canonical: $canonical)
		);
	}//end testAChangedAmountOrPredecessorChangesTheSeal()

	public function testCentsAreExactFromStringsNumbersAndNegatives(): void {
		self::assertSame(36310, LedgerSealCanonicaliser::cents(amount: '363.10'));
		self::assertSame(36310, LedgerSealCanonicaliser::cents(amount: 363.1));
		self::assertSame(120000, LedgerSealCanonicaliser::cents(amount: 1200));
		self::assertSame(-505, LedgerSealCanonicaliser::cents(amount: '-5.05'));
		self::assertSame(50, LedgerSealCanonicaliser::cents(amount: '.5'));
		self::assertSame(0, LedgerSealCanonicaliser::cents(amount: 'abc'));
		self::assertSame(0, LedgerSealCanonicaliser::cents(amount: null));
	}//end testCentsAreExactFromStringsNumbersAndNegatives()
}//end class
