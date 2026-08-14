<?php

/**
 * Unit tests for BankfeedMatcher.
 *
 * Covers REQ-CF-012 (fuzzy matching of PSD2 transactions vs CashflowARProjection).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/zzp-cashflow-13wk/tasks.md#task-29
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BankfeedMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Fuzzy-match scoring tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BankfeedMatcherTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var BankfeedMatcher
	 */
	private BankfeedMatcher $matcher;

	/**
	 * Set up fresh matcher per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->matcher = new BankfeedMatcher();

	}//end setUp()

	/**
	 * Exact amount + matching reference + matching date -> very high confidence.
	 *
	 * @return void
	 */
	public function testHighConfidenceMatchOnExactAmountAndReference(): void {
		$tx = [
			'amount' => 8400.00,
			'reference' => 'fact-2026-0247',
			'valueDate' => '2026-05-28',
		];
		$candidate = [
			'arInvoiceId' => 'fact-2026-0247',
			'customerId' => 'klant-acme-bv',
			'outstandingAmount' => 8400.00,
			'expectedReceiptDate' => '2026-05-28',
		];

		$result = $this->matcher->matchTransaction($tx, [$candidate]);

		self::assertSame('fact-2026-0247', $result['arInvoiceId']);
		self::assertGreaterThanOrEqual(0.95, $result['confidence']);

	}//end testHighConfidenceMatchOnExactAmountAndReference()

	/**
	 * Amount delta above 5% drops the amount score to zero and crashes the
	 * combined confidence below the 0.5 acceptance threshold.
	 *
	 * @return void
	 */
	public function testNoMatchWhenAmountIsFarOff(): void {
		$tx = [
			'amount' => 8400.00,
			'reference' => 'no-match-here',
			'valueDate' => '2026-08-30',
		];
		$candidate = [
			'arInvoiceId' => 'fact-2026-0247',
			'customerId' => 'klant-acme-bv',
			'outstandingAmount' => 100.00,
			'expectedReceiptDate' => '2026-05-28',
		];

		$result = $this->matcher->matchTransaction($tx, [$candidate]);

		self::assertNull($result['arInvoiceId']);
		self::assertLessThan(0.5, $result['confidence']);

	}//end testNoMatchWhenAmountIsFarOff()

	/**
	 * Empty candidate list returns null match with zero confidence.
	 *
	 * @return void
	 */
	public function testEmptyCandidatesReturnsNullMatch(): void {
		$result = $this->matcher->matchTransaction(
			['amount' => 100.00, 'reference' => 'x', 'valueDate' => '2026-05-01'],
			[]
		);

		self::assertNull($result['arInvoiceId']);
		self::assertSame(0.0, $result['confidence']);

	}//end testEmptyCandidatesReturnsNullMatch()

	/**
	 * The matcher picks the highest-scoring candidate from a mixed set.
	 *
	 * @return void
	 */
	public function testPicksBestCandidateAmongMultiple(): void {
		$tx = [
			'amount' => 1210.00,
			'reference' => 'INV 0247',
			'valueDate' => '2026-05-28',
		];
		$candidates = [
			[
				'arInvoiceId' => 'fact-2026-0247',
				'customerId' => 'klant-acme-bv',
				'outstandingAmount' => 1210.00,
				'expectedReceiptDate' => '2026-05-28',
			],
			[
				'arInvoiceId' => 'fact-2026-0250',
				'customerId' => 'klant-other',
				'outstandingAmount' => 1209.00,
				'expectedReceiptDate' => '2026-07-15',
			],
		];

		$result = $this->matcher->matchTransaction($tx, $candidates);

		self::assertSame('fact-2026-0247', $result['arInvoiceId']);

	}//end testPicksBestCandidateAmongMultiple()

	/**
	 * Customer-id similarity (klantId) drives the reference score when the
	 * transaction reference contains the customer rather than the invoice.
	 *
	 * @return void
	 */
	public function testReferenceMatchOnCustomerId(): void {
		$tx = [
			'amount' => 5200.00,
			'reference' => 'klant-acme-bv',
			'valueDate' => '2026-06-10',
		];
		$candidate = [
			'arInvoiceId' => 'fact-jan-2026-023',
			'customerId' => 'klant-acme-bv',
			'outstandingAmount' => 5200.00,
			'expectedReceiptDate' => '2026-06-10',
		];

		$result = $this->matcher->matchTransaction($tx, [$candidate]);

		self::assertSame('fact-jan-2026-023', $result['arInvoiceId']);
		self::assertGreaterThanOrEqual(0.9, $result['confidence']);

	}//end testReferenceMatchOnCustomerId()

	/**
	 * Date proximity within 14 days yields a non-zero date score; outside it
	 * collapses to zero.
	 *
	 * @return void
	 */
	public function testDateProximityScoring(): void {
		$candidate = [
			'arInvoiceId' => 'fact-x',
			'customerId' => 'klant-x',
			'outstandingAmount' => 1000.00,
			'expectedReceiptDate' => '2026-06-01',
		];

		$near = $this->matcher->matchTransaction(
			['amount' => 1000.00, 'reference' => 'fact-x', 'valueDate' => '2026-06-03'],
			[$candidate]
		);
		$far = $this->matcher->matchTransaction(
			['amount' => 1000.00, 'reference' => 'fact-x', 'valueDate' => '2026-09-01'],
			[$candidate]
		);

		self::assertGreaterThan($far['confidence'], $near['confidence']);

	}//end testDateProximityScoring()

}//end class
