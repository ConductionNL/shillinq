<?php

/**
 * Accrual Booking
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Books the accruals the nightly soft close computes (ledger-period-end
 * REQ-LPE-006, design D4): for each rule and period it writes and posts the
 * JournalEntry the AutoAccrualPosting names, debit the rule's target account
 * and credit its contra account, once per rule, period and rule version. A
 * rule whose reversalPattern reverses at the start of the next period gets
 * its mirror entry on the first run of a later period, and the posting
 * records the reversal.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Accrual
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-a-computed-accrual-is-booked-and-reversed-req-lpe-006
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Accrual;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Writes and posts accrual and reversal journal entries.
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-a-computed-accrual-is-booked-and-reversed-req-lpe-006
 */
class AccrualBooking {

	/**
	 * The marker on postings written by the nightly close.
	 */
	public const SYSTEM_ACTOR = 'SYSTEM:SoftCloseExecutor';

	/**
	 * The reversal patterns that reverse at the start of the next period.
	 * `first-of-next-month` is the schema's name; `next-period-start` the design's.
	 */
	public const NEXT_PERIOD_PATTERNS = ['first-of-next-month', 'next-period-start'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface          $objectService OpenRegister's object service.
	 * @param ObjectTransitionRunnerInterface $transitions   Posts journal entries through `postDirect`.
	 * @param RegisterLocation                $settings      The register the objects live in.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ObjectTransitionRunnerInterface $transitions,
		private readonly RegisterLocation $settings,
	) {
	}//end __construct()

	/**
	 * Book one rule's accrual for a period, unless it is booked already.
	 *
	 * @param array<string,mixed> $rule          The AutoAccrualRule.
	 * @param string              $periodId      The yyyy-mm period.
	 * @param int                 $amountCents   The computed amount (> 0).
	 * @param DateTimeImmutable   $asOf          The run time.
	 * @param string              $journalNumber The journal entry id the posting records.
	 *
	 * @return bool True when an entry was posted; false when this rule, period and version were booked before.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-a-computed-accrual-is-booked-and-reversed-req-lpe-006
	 */
	public function book(array $rule, string $periodId, int $amountCents, DateTimeImmutable $asOf, string $journalNumber): bool {
		$ruleId  = self::ruleId(rule: $rule);
		$version = (int)($rule['ruleVersion'] ?? 1);
		if ($amountCents <= 0 || $this->booked(ruleId: $ruleId, periodId: $periodId, version: $version) === true) {
			return false;
		}

		$this->postJournal(
			journal: self::journal(
				rule: $rule,
				number: $journalNumber,
				date: self::entryDate(periodId: $periodId, asOf: $asOf),
				cents: $amountCents,
				text: 'Accrual ' . (string)($rule['ruleName'] ?? $ruleId) . ' ' . $periodId,
				reverse: false
			)
		);

		$this->scoped(schema: 'AutoAccrualPosting')->saveObject(
			[
				'ruleId' => $ruleId,
				'ruleVersion' => $version,
				'periodId' => $periodId,
				'amountCents' => $amountCents,
				'journalEntryId' => $journalNumber,
				'postedAt' => $asOf->format(DateTimeInterface::ATOM),
				'postedBy' => self::SYSTEM_ACTOR,
				'reversalId' => null,
				'reversalState' => 'posted',
			]
		);

		return true;
	}//end book()

	/**
	 * Post the reversal of every earlier period's accrual whose rule reverses at the next period's start.
	 *
	 * @param string            $administrationId The administration.
	 * @param string            $periodId         The run's yyyy-mm period; postings of earlier periods reverse.
	 * @param DateTimeImmutable $asOf             The run time.
	 *
	 * @return int The reversals posted.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-a-computed-accrual-is-booked-and-reversed-req-lpe-006
	 */
	public function reverseDue(string $administrationId, string $periodId, DateTimeImmutable $asOf): int {
		$reversed = 0;
		foreach ($this->reversingRules(administrationId: $administrationId) as $ruleId => $rule) {
			$rows = $this->scoped(schema: 'AutoAccrualPosting')->findAll(['filters' => ['ruleId' => $ruleId, 'reversalState' => 'posted']]);
			foreach ($rows as $row) {
				$posting = ObjectIdentifier::recordWithId(candidate: $row);
				if ($posting === null || self::reversible(posting: $posting, ruleId: $ruleId, periodId: $periodId) === false) {
					continue;
				}

				$this->reverse(rule: $rule, posting: $posting, periodId: $periodId, asOf: $asOf);
				$reversed++;
			}
		}

		return $reversed;
	}//end reverseDue()

	/**
	 * Post one mirror entry on the first day of the run's period and record it on the posting.
	 *
	 * @param array<string,mixed> $rule     The rule.
	 * @param array<string,mixed> $posting  The posting to reverse.
	 * @param string              $periodId The run's period.
	 * @param DateTimeImmutable   $asOf     The run time.
	 *
	 * @return void
	 */
	private function reverse(array $rule, array $posting, string $periodId, DateTimeImmutable $asOf): void {
		$number = (string)$posting['journalEntryId'] . '-rev';
		$this->postJournal(
			journal: self::journal(
				rule: $rule,
				number: $number,
				date: $periodId . '-01',
				cents: (int)$posting['amountCents'],
				text: 'Reversal of accrual ' . (string)($rule['ruleName'] ?? '') . ' ' . (string)$posting['periodId'],
				reverse: true
			)
		);

		$this->objectService->patchObject(
			objectId: (string)$posting['id'],
			data: ['reversalId' => $number, 'reversalState' => 'reversed'],
			register: $this->settings->getRegisterSlug(),
			schema: 'AutoAccrualPosting'
		);
	}//end reverse()

	/**
	 * The administration's rules that reverse at the next period's start, by id.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function reversingRules(string $administrationId): array {
		$filters = [];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$rules = [];
		foreach ($this->scoped(schema: 'AutoAccrualRule')->findAll(['filters' => $filters]) as $row) {
			$rule = ObjectIdentifier::recordWithId(candidate: $row);
			if ($rule !== null && in_array((string)($rule['reversalPattern'] ?? ''), self::NEXT_PERIOD_PATTERNS, true) === true) {
				$rules[self::ruleId(rule: $rule)] = $rule;
			}
		}

		return $rules;
	}//end reversingRules()

	/**
	 * Whether a posting of the rule belongs to an earlier period and is not reversed yet.
	 *
	 * @param array<string,mixed> $posting  The posting.
	 * @param string              $ruleId   The rule.
	 * @param string              $periodId The run's period.
	 *
	 * @return bool
	 */
	private static function reversible(array $posting, string $ruleId, string $periodId): bool {
		return (string)($posting['ruleId'] ?? '') === $ruleId
			&& (string)($posting['reversalState'] ?? 'posted') === 'posted'
			&& trim((string)($posting['reversalId'] ?? '')) === ''
			&& (string)($posting['periodId'] ?? '') !== ''
			&& (string)$posting['periodId'] < $periodId
			&& trim((string)($posting['journalEntryId'] ?? '')) !== ''
			&& (int)($posting['amountCents'] ?? 0) > 0;
	}//end reversible()

	/**
	 * Whether the rule's accrual for this period and version is booked.
	 *
	 * @param string $ruleId   The rule.
	 * @param string $periodId The period.
	 * @param int    $version  The rule version.
	 *
	 * @return bool
	 */
	private function booked(string $ruleId, string $periodId, int $version): bool {
		$rows = $this->scoped(schema: 'AutoAccrualPosting')->findAll(['filters' => ['ruleId' => $ruleId, 'periodId' => $periodId]]);
		foreach ($rows as $row) {
			$posting = ObjectIdentifier::recordWithId(candidate: $row);
			if ($posting !== null
				&& (string)($posting['ruleId'] ?? '') === $ruleId
				&& (string)($posting['periodId'] ?? '') === $periodId
				&& (int)($posting['ruleVersion'] ?? 1) === $version
			) {
				return true;
			}
		}

		return false;
	}//end booked()

	/**
	 * The draft entry: debit target and credit contra, or the mirror for a reversal.
	 *
	 * @param array<string,mixed> $rule    The rule.
	 * @param string              $number  The journal number.
	 * @param string              $date    The entry date.
	 * @param int                 $cents   The amount in cents.
	 * @param string              $text    The description.
	 * @param bool                $reverse Whether this is the mirror entry.
	 *
	 * @return array<string,mixed>
	 */
	private static function journal(array $rule, string $number, string $date, int $cents, string $text, bool $reverse): array {
		$debit  = (string)($rule['targetGLAccount'] ?? '');
		$credit = (string)($rule['contraGLAccount'] ?? '');
		if ($reverse === true) {
			[$debit, $credit] = [$credit, $debit];
		}

		$amount = round($cents / 100, 2);

		return [
			'journalNumber' => $number,
			'entryDate' => $date,
			'description' => $text,
			'lines' => [
				['accountNumber' => $debit, 'side' => 'debit', 'amount' => $amount, 'description' => $text],
				['accountNumber' => $credit, 'side' => 'credit', 'amount' => $amount, 'description' => $text],
			],
			'journalType' => 'manual',
			'approvalState' => 'not-required',
			'administrationId' => (string)($rule['administrationId'] ?? ''),
			'state' => 'draft',
		];
	}//end journal()

	/**
	 * The entry date of a period's accrual: the run date, or the period's last day once it has passed.
	 *
	 * @param string            $periodId The yyyy-mm period.
	 * @param DateTimeImmutable $asOf     The run time.
	 *
	 * @return string Y-m-d.
	 */
	private static function entryDate(string $periodId, DateTimeImmutable $asOf): string {
		$today = $asOf->format('Y-m-d');
		if (preg_match('/^\d{4}-\d{2}$/', $periodId) !== 1) {
			return $today;
		}

		return min($today, (new DateTimeImmutable($periodId . '-01'))->format('Y-m-t'));
	}//end entryDate()

	/**
	 * The id a posting names its rule by.
	 *
	 * @param array<string,mixed> $rule The rule.
	 *
	 * @return string
	 */
	private static function ruleId(array $rule): string {
		return (string)($rule['id'] ?? ($rule['ruleName'] ?? ''));
	}//end ruleId()

	/**
	 * Save a draft journal entry and post it through `postDirect`.
	 *
	 * @param array<string,mixed> $journal The draft entry.
	 *
	 * @return string The journal entry's id.
	 */
	private function postJournal(array $journal): string {
		$journalId = ObjectIdentifier::resolve(saved: $this->scoped(schema: 'JournalEntry')->saveObject($journal));
		$this->transitions->run(objectId: $journalId, action: 'postDirect');

		return $journalId;
	}//end postJournal()

	/**
	 * The object service pointed at shillinq's register and a schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);
	}//end scoped()
}//end class
