<?php

/**
 * The stamps a post gives a ledger transaction.
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
 * @spec openspec/specs/bookkeeping-general-ledger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use DateTimeImmutable;
use OCA\Shillinq\Lifecycle\PostingStamps;
use OCA\Shillinq\Standards\RuleEngine;
use PHPUnit\Framework\TestCase;

/**
 * A bookkeeper's balanced memorial entry, as the general ledger page sends
 * it, carries none of the lock, retention, integrity and audit-trail fields
 * the mandatory NL and EU ledger rules ask of a POSTED entry. Those fields
 * are what the post itself establishes, so the post stamps them (#516).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PostingStampsTest extends TestCase {

	/**
	 * The design's memorial entry as the general ledger page posts it.
	 *
	 * @return array<string,mixed>
	 */
	private function memorial(): array {
		return [
			'id' => 'gl-1',
			'transactionNumber' => 'MEM-2026-0001',
			'postingDate' => '2026-09-20',
			'description' => 'Huur september',
			'sourceReference' => 'manual',
			'administrationId' => 'adm-1',
			'state' => 'posted',
			'lines' => [
				['accountNumber' => '4000', 'side' => 'debit', 'amount' => 1200.0],
				['accountNumber' => '1100', 'side' => 'credit', 'amount' => 1200.0],
			],
		];
	}//end memorial()

	/**
	 * The mandatory rule ids a transaction violates under NL jurisdiction.
	 *
	 * @param array<string,mixed> $transaction The transaction.
	 *
	 * @return list<string>
	 */
	private function mandatoryViolations(array $transaction): array {
		$ids = [];
		foreach (RuleEngine::evaluate('GLTransaction', $transaction, ['jurisdiction' => 'NL']) as $violation) {
			if ($violation->severity === 'mandatory') {
				$ids[] = $violation->ruleId;
			}
		}

		return $ids;
	}//end mandatoryViolations()

	/**
	 * Unstamped, the memorial entry violates the posted-entry rules; stamped,
	 * it violates none.
	 *
	 * @return void
	 */
	public function testAStampedBalancedEntryMeetsEveryMandatoryRule(): void {
		self::assertNotSame([], $this->mandatoryViolations($this->memorial()), 'Control: the unstamped entry is refused today.');

		$stamped = (new PostingStamps())->apply($this->memorial(), 'alice', new DateTimeImmutable('2026-09-20T10:00:00+00:00'));

		self::assertSame([], $this->mandatoryViolations($stamped));
		self::assertTrue($stamped['postingLocked']);
		self::assertTrue($stamped['integrityVerified']);
		self::assertSame('2036-12-31', $stamped['retentionUntil']);
		self::assertSame(
			[['user' => 'alice', 'timestamp' => '2026-09-20T10:00:00+00:00', 'action' => 'post']],
			$stamped['auditTrail']
		);
	}//end testAStampedBalancedEntryMeetsEveryMandatoryRule()

	/**
	 * Stamping twice records the post once, and a longer retention stays.
	 *
	 * @return void
	 */
	public function testStampingTwiceRecordsThePostOnceAndKeepsALongerRetention(): void {
		$entry = $this->memorial();
		$entry['retentionUntil'] = '2040-12-31';
		$now = new DateTimeImmutable('2026-09-20T10:00:00+00:00');

		$stamped = (new PostingStamps())->apply((new PostingStamps())->apply($entry, 'alice', $now), 'bob', $now);

		self::assertCount(1, $stamped['auditTrail']);
		self::assertSame('alice', $stamped['auditTrail'][0]['user']);
		self::assertSame('2040-12-31', $stamped['retentionUntil']);
	}//end testStampingTwiceRecordsThePostOnceAndKeepsALongerRetention()

	/**
	 * Without a posting date there is no retention to compute, so none is
	 * invented and the completeness rules still refuse the entry.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutAPostingDateGetsNoRetentionAndIsStillRefused(): void {
		$entry = $this->memorial();
		unset($entry['postingDate']);

		$stamped = (new PostingStamps())->apply($entry, 'alice', new DateTimeImmutable('2026-09-20T10:00:00+00:00'));

		self::assertArrayNotHasKey('retentionUntil', $stamped);
		self::assertNotSame([], $this->mandatoryViolations($stamped));
	}//end testAnEntryWithoutAPostingDateGetsNoRetentionAndIsStillRefused()

	/**
	 * An unbalanced entry stays refused however it is stamped.
	 *
	 * @return void
	 */
	public function testStampingDoesNotMakeAnUnbalancedEntryPass(): void {
		$entry = $this->memorial();
		$entry['lines'][1]['amount'] = 1000.0;

		$stamped = (new PostingStamps())->apply($entry, 'alice', new DateTimeImmutable('2026-09-20T10:00:00+00:00'));

		self::assertContains('gl-double-entry-balanced', $this->mandatoryViolations($stamped));
	}//end testStampingDoesNotMakeAnUnbalancedEntryPass()
}//end class
