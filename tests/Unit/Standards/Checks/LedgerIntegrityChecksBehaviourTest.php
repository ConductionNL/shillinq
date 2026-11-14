<?php

/**
 * Behaviour of the ledger-integrity checks on real journal entries.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Standards\Checks
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

namespace OCA\Shillinq\Tests\Unit\Standards\Checks;

use OCA\Shillinq\Standards\Checks\LedgerIntegrityChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The orderly-books, immutability, audit-trail, FEC and retention rules
 * accept a complete posted entry and refuse each specific defect.
 *
 * @covers \OCA\Shillinq\Standards\Checks\LedgerIntegrityChecks
 * @uses   \OCA\Shillinq\Standards\Checks\GlTransactionLines
 * @uses   \OCA\Shillinq\Standards\Checks\CheckFields
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
final class LedgerIntegrityChecksBehaviourTest extends TestCase {

	private const COMPLETE = ['de-hgb239-2-timely-orderly', 'de-ao146-1-timely-complete', 'de-gobd-vollstaendigkeit'];

	private const NO_GAP = ['fr-comm-l123-22-no-blank-no-gap', 'be-wer-no-blank-no-alteration', 'es-ccom-29-no-blank-no-gap', 'it-cc-2219-orderly'];

	private const IMMUTABLE = ['gl-no-deletion-only-reversal', 'fr-nf525-inalterability'];

	private const WITH_TRAIL = ['de-hgb239-3-no-undetectable-change', 'de-ao146-4-no-unrecorded-change', 'de-gobd-unveraenderbarkeit'];

	/**
	 * A complete, posted, locked and validated two-line entry.
	 *
	 * @return array<string, mixed>
	 */
	private static function entry(): array {
		return [
			'state'             => 'posted',
			'transactionNumber' => 'MEM-2026-0001',
			'postingDate'       => '2026-01-01',
			'sourceReference'   => 'F-2026-001',
			'postingLocked'     => true,
			'irreversible'      => '1',
			'validationDate'    => '2026-12-31',
			'retentionUntil'    => '2033-01-01',
			'auditTrail'        => [['user' => 'admin', 'timestamp' => '2026-01-01T10:00:00+00:00', 'action' => 'post']],
			'lines'             => [
				['accountNumber' => '1000', 'side' => 'debit', 'amount' => 121.00],
				['accountNumber' => '8000', 'side' => 'credit', 'amount' => '121.00'],
			],
		];

	}//end entry()

	/**
	 * The verdict of each named rule on an object.
	 *
	 * @param array<int, string>   $ruleIds The rules.
	 * @param array<string, mixed> $o       The entry.
	 *
	 * @return array<string, bool>
	 */
	private static function verdicts(array $ruleIds, array $o): array {
		$checks = LedgerIntegrityChecks::checks()['GLTransaction'];
		$out    = [];
		foreach ($ruleIds as $ruleId) {
			$out[$ruleId] = $checks[$ruleId]($o, ['jurisdiction' => 'NL']);
		}

		return $out;

	}//end verdicts()

	/**
	 * Every named rule gives the same verdict.
	 *
	 * @param array<int, string>   $ruleIds  The rules.
	 * @param array<string, mixed> $o        The entry.
	 * @param boolean              $expected The verdict.
	 * @param string               $message  What is being checked.
	 *
	 * @return void
	 */
	private function assertAll(array $ruleIds, array $o, bool $expected, string $message): void {
		$this->assertSame(array_fill_keys($ruleIds, $expected), self::verdicts($ruleIds, $o), $message);

	}//end assertAll()

	/**
	 * A complete posted entry satisfies every behaviour rule exercised here.
	 *
	 * @return void
	 */
	public function testACompletePostedEntryPasses(): void {
		$rules = [...self::COMPLETE, ...self::NO_GAP, ...self::IMMUTABLE, ...self::WITH_TRAIL, 'gl-change-audit-trail', 'fr-fec-validated-entries', 'fr-fec-18-fields', 'nl-awr52-general-7y'];
		$this->assertAll($rules, self::entry(), true, 'complete entry');

	}//end testACompletePostedEntryPasses()

	/**
	 * An incomplete entry is refused by the completeness and no-gap rules.
	 *
	 * @param \Closure $defect Turns the complete entry into an incomplete one.
	 *
	 * @return void
	 */
	#[DataProvider('incompleteEntries')]
	public function testAnIncompleteEntryIsRefused(\Closure $defect): void {
		$o = $defect(self::entry());
		$this->assertAll(self::COMPLETE, $o, false, 'completeness');
		$this->assertAll(self::NO_GAP, $o, false, 'no blank, no gap');

	}//end testAnIncompleteEntryIsRefused()

	/**
	 * Entries the completeness rules refuse.
	 *
	 * @return array<string, array{0: \Closure}>
	 */
	public static function incompleteEntries(): array {
		return [
			'a single line'          => [static function (array $o): array {
				array_pop($o['lines']);
				return $o;
			},
			],
			'a line without account' => [static function (array $o): array {
				$o['lines'][1]['accountNumber'] = ' ';
				return $o;
			},
			],
			'a line without side'    => [static function (array $o): array {
				$o['lines'][0]['side'] = 'left';
				return $o;
			},
			],
			'a zero line'            => [static function (array $o): array {
				$o['lines'][] = ['accountNumber' => '1100', 'side' => 'debit', 'amount' => 0.004];
				return $o;
			},
			],
			'no posting date'        => [static fn (array $o): array => ['postingDate' => ''] + $o],
		];

	}//end incompleteEntries()

	/**
	 * Without an entry number only the no-gap rules refuse.
	 *
	 * @return void
	 */
	public function testAnUnnumberedEntryHasAGap(): void {
		$o = array_diff_key(self::entry(), ['transactionNumber' => 1]);
		$this->assertAll(self::COMPLETE, $o, true, 'still complete');
		$this->assertAll(self::NO_GAP, $o, false, 'gap');

	}//end testAnUnnumberedEntryHasAGap()

	/**
	 * Posted entries must be locked, reversals must name what they reverse, drafts are free.
	 *
	 * @return void
	 */
	public function testImmutabilityFollowsTheState(): void {
		$this->assertAll(self::IMMUTABLE, ['postingLocked' => false] + self::entry(), false, 'unlocked posting');
		$this->assertAll(self::IMMUTABLE, ['postingLocked' => 'yes'] + self::entry(), false, 'not a boolean');
		$this->assertAll(self::IMMUTABLE, ['postingLocked' => 'true'] + self::entry(), true, 'boolean text');
		$this->assertAll(self::IMMUTABLE, ['state' => 'reversed', 'reversesTransactionId' => 'gl-1', 'postingLocked' => false] + self::entry(), true, 'reversal');
		$this->assertAll(self::IMMUTABLE, ['state' => 'reversed', 'reversesTransactionId' => ' '] + self::entry(), false, 'reversal without origin');
		$this->assertAll(self::IMMUTABLE, ['state' => 'draft', 'postingLocked' => false] + self::entry(), true, 'draft');

	}//end testImmutabilityFollowsTheState()

	/**
	 * The audit trail needs a user and a time on every entry; the change
	 * rules need both a trail and immutability.
	 *
	 * @return void
	 */
	public function testTheAuditTrailNeedsUserAndTime(): void {
		$trailRules = ['gl-change-audit-trail', ...self::WITH_TRAIL];
		$trails     = [
			'no trail'           => [],
			'not a list'         => 'posted by admin',
			'a text entry'       => ['posted by admin'],
			'no user'            => [['user' => '', 'timestamp' => '2026-01-01T10:00:00+00:00']],
			'no time'            => [['user' => 'admin']],
			'one entry is blank' => [self::entry()['auditTrail'][0], ['user' => 'admin', 'timestamp' => ' ']],
		];
		foreach ($trails as $label => $trail) {
			$this->assertAll($trailRules, ['auditTrail' => $trail] + self::entry(), false, $label);
		}

		$unlocked = ['postingLocked' => false] + self::entry();
		$this->assertSame([true], array_values(self::verdicts(['gl-change-audit-trail'], $unlocked)));
		$this->assertAll(self::WITH_TRAIL, $unlocked, false, 'trail but unlocked');

	}//end testTheAuditTrailNeedsUserAndTime()

	/**
	 * Only posted or reversed entries with a validation date and the
	 * irreversible flag count as validated.
	 *
	 * @return void
	 */
	public function testValidatedEntries(): void {
		$cases = [
			'reversed'              => [['state' => 'reversed'], true],
			'draft'                 => [['state' => 'draft'], false],
			'no validation date'    => [['validationDate' => ''], false],
			'reversible'            => [['irreversible' => 'false'], false],
			'irreversible as text'  => [['irreversible' => 'yes'], false],
		];
		foreach ($cases as $label => [$change, $expected]) {
			$this->assertAll(['fr-fec-validated-entries'], $change + self::entry(), $expected, $label);
		}

	}//end testValidatedEntries()

	/**
	 * The FEC export needs the number, date, source and a sided, numeric amount per line.
	 *
	 * @return void
	 */
	public function testFecFields(): void {
		$zero = self::entry();
		$zero['lines'][0]['amount'] = 0;
		$this->assertAll(['fr-fec-18-fields'], $zero, true, 'a zero amount is still an amount');

		$noAmount = self::entry();
		$noAmount['lines'][1]['amount'] = 'n/a';
		$noSide = self::entry();
		unset($noSide['lines'][0]['side']);
		$noAccount = self::entry();
		$noAccount['lines'][0]['accountNumber'] = '';
		$cases = [
			'no source'      => ['sourceReference' => ''] + self::entry(),
			'no number'      => array_diff_key(self::entry(), ['transactionNumber' => 1]),
			'no date'        => ['postingDate' => ' '] + self::entry(),
			'no lines'       => ['lines' => 'none'] + self::entry(),
			'no amount'      => $noAmount,
			'no side'        => $noSide,
			'no account'     => $noAccount,
		];
		foreach ($cases as $label => $o) {
			$this->assertAll(['fr-fec-18-fields'], $o, false, $label);
		}

	}//end testFecFields()

	/**
	 * Retention runs the full number of years from the posting date.
	 *
	 * @return void
	 */
	public function testRetentionCountsYearsFromPosting(): void {
		$sevenYears = self::entry();
		$this->assertSame(
			['nl-awr52-general-7y' => true, 'nl-awr52-realestate-10y' => false, 'us-irs-general-3y' => true],
			self::verdicts(['nl-awr52-general-7y', 'nl-awr52-realestate-10y', 'us-irs-general-3y'], $sevenYears)
		);
		$this->assertAll(['nl-awr52-general-7y'], ['retentionUntil' => '2032-12-31'] + self::entry(), false, 'a day short');
		$this->assertAll(['nl-awr52-general-7y'], ['retentionUntil' => 'forever'] + self::entry(), false, 'unparsable retention');
		$this->assertAll(['nl-awr52-general-7y'], ['postingDate' => ''] + self::entry(), false, 'no posting date');

	}//end testRetentionCountsYearsFromPosting()
}//end class
