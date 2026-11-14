<?php

/**
 * Deferral Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spreads a prepaid cost or deferred revenue from an invoice line over the
 * fiscal periods of its service period (ledger-period-end REQ-LPE-004), and
 * releases each ended period from the nightly soft close (REQ-LPE-005).
 *
 * Creating a schedule posts one journal entry moving the total from the result
 * account to the balance account. Each release posts the period's share back.
 * Every entry is a JournalEntry posted through its `postDirect` transition,
 * the way every posting in shillinq reaches the ledger.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Deferral
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Deferral;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\ObjectTransitionRunnerInterface;
use OCA\Shillinq\Kernel\Exception\DeferralRefusedException;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Creates and releases deferral schedules.
 *
 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
 */
class DeferralService {

	/**
	 * The schedule schema.
	 */
	public const SCHEMA = 'DeferralSchedule';

	/**
	 * The kinds a schedule can be.
	 */
	public const KINDS = ['prepaid-cost', 'deferred-revenue'];

	/**
	 * The longest free-text field accepted.
	 */
	private const MAX_TEXT = 255;

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
	 * The schedule a request would create, with its releases, without writing anything.
	 *
	 * @param array<string,mixed> $input The request: administrationId, sourceReference, description,
	 *                                   kind, resultAccount, balanceAccount, totalCents, periodFrom, periodTo.
	 *
	 * @return array<string,mixed> The schedule.
	 *
	 * @throws DeferralRefusedException When the request is not a usable deferral.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
	 */
	public function preview(array $input): array {
		$schedule = [
			'administrationId' => self::text(input: $input, key: 'administrationId'),
			'sourceReference' => self::text(input: $input, key: 'sourceReference'),
			'description' => self::text(input: $input, key: 'description', required: false),
			'kind' => self::text(input: $input, key: 'kind'),
			'resultAccount' => self::text(input: $input, key: 'resultAccount'),
			'balanceAccount' => self::text(input: $input, key: 'balanceAccount'),
			'totalCents' => self::cents(value: ($input['totalCents'] ?? null)),
			'periodFrom' => self::text(input: $input, key: 'periodFrom'),
			'periodTo' => self::text(input: $input, key: 'periodTo'),
		];

		if (in_array($schedule['kind'], self::KINDS, true) === false) {
			throw new DeferralRefusedException(message: 'Choose prepaid cost or deferred revenue');
		}

		if ($schedule['resultAccount'] === $schedule['balanceAccount']) {
			throw new DeferralRefusedException(message: 'The balance account must differ from the result account');
		}

		try {
			if (DeferralPlan::spansSeveralPeriods(from: $schedule['periodFrom'], to: $schedule['periodTo']) === false) {
				throw new DeferralRefusedException(message: 'The service period must span more than one period');
			}

			$schedule['releases'] = DeferralPlan::releases(
				totalCents: $schedule['totalCents'],
				from: $schedule['periodFrom'],
				to: $schedule['periodTo']
			);
		} catch (InvalidArgumentException $e) {
			throw new DeferralRefusedException(message: $e->getMessage());
		}

		$schedule['openingJournalEntryId'] = null;
		$schedule['state'] = 'active';

		return $schedule;
	}//end preview()

	/**
	 * Create the schedule and post the entry moving the total to the balance account.
	 *
	 * @param array<string,mixed> $input The request, as for preview().
	 *
	 * @return array<string,mixed> The stored schedule, with its id and opening entry.
	 *
	 * @throws DeferralRefusedException When the request is not usable or the line is already spread.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-an-invoice-line-spanning-several-periods-is-spread-over-them-req-lpe-004
	 */
	public function spread(array $input): array {
		$schedule = $this->preview(input: $input);
		if ($this->alreadySpread(administrationId: $schedule['administrationId'], sourceReference: $schedule['sourceReference']) === true) {
			throw new DeferralRefusedException(message: 'This invoice line is already spread', status: DeferralRefusedException::CONFLICT);
		}

		$schedule['id'] = ObjectIdentifier::resolve(saved: $this->scoped(schema: self::SCHEMA)->saveObject($schedule));

		$schedule['openingJournalEntryId'] = $this->postJournal(
			journal: self::journal(
				schedule: $schedule,
				number: 'SPR-' . substr(hash('sha256', $schedule['administrationId'] . '|' . $schedule['sourceReference']), 0, 10),
				date: $schedule['periodFrom'],
				cents: $schedule['totalCents'],
				opening: true
			)
		);

		$this->patch(id: $schedule['id'], fields: ['openingJournalEntryId' => $schedule['openingJournalEntryId']]);

		return $schedule;
	}//end spread()

	/**
	 * Post every release of an active schedule whose period has ended and has no entry yet.
	 *
	 * A release is posted once: its entry id is stored on the schedule right
	 * after it posts, so a second run the same night finds nothing to do. The
	 * schedule completes when its last release posts.
	 *
	 * @param string            $administrationId The administration.
	 * @param DateTimeImmutable $asOf             The run time; a period has ended when its last day is before this date.
	 *
	 * @return int The number of releases posted.
	 *
	 * @spec openspec/changes/ledger-period-end/specs/bookkeeping-continuous-close/spec.md#requirement-the-nightly-close-releases-each-ended-period-req-lpe-005
	 */
	public function releaseEnded(string $administrationId, DateTimeImmutable $asOf): int {
		$filters = ['state' => 'active'];
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		$posted = 0;
		foreach ($this->scoped(schema: self::SCHEMA)->findAll(['filters' => $filters]) as $row) {
			$schedule = ObjectIdentifier::recordWithId(candidate: $row);
			if ($schedule === null || (string)($schedule['state'] ?? '') !== 'active') {
				continue;
			}

			$posted += $this->releaseSchedule(schedule: $schedule, today: $asOf->format('Y-m-d'), asOf: $asOf);
		}

		return $posted;
	}//end releaseEnded()

	/**
	 * Release one schedule's ended periods and complete it when nothing is left.
	 *
	 * @param array<string,mixed> $schedule The schedule.
	 * @param string              $today    The run date (Y-m-d).
	 * @param DateTimeImmutable   $asOf     The run time.
	 *
	 * @return int The releases posted.
	 */
	private function releaseSchedule(array $schedule, string $today, DateTimeImmutable $asOf): int {
		$releases = (array)($schedule['releases'] ?? []);
		$posted   = 0;
		foreach ($releases as $index => $release) {
			if (is_array($release) === false || self::isDue(release: $release, today: $today) === false) {
				continue;
			}

			$releases[$index]['journalEntryId'] = $this->postJournal(
				journal: self::journal(
					schedule: $schedule,
					number: 'VRV-' . substr(hash('sha256', (string)$schedule['id'] . '|' . (string)$release['periodId']), 0, 10),
					date: (string)($release['periodEnd'] ?? $today),
					cents: (int)$release['amountCents'],
					opening: false
				)
			);
			$releases[$index]['releasedAt'] = $asOf->format(DateTimeInterface::ATOM);
			$this->patch(id: (string)$schedule['id'], fields: ['releases' => $releases]);
			$posted++;
		}

		if ($releases !== [] && self::allReleased(releases: $releases) === true) {
			$this->patch(id: (string)$schedule['id'], fields: ['state' => 'completed']);
		}

		return $posted;
	}//end releaseSchedule()

	/**
	 * Whether a release has ended before today and has no entry yet.
	 *
	 * @param array<string,mixed> $release The release.
	 * @param string              $today   The run date (Y-m-d).
	 *
	 * @return bool
	 */
	private static function isDue(array $release, string $today): bool {
		$end = (string)($release['periodEnd'] ?? '');
		if ($end === '' && preg_match('/^\d{4}-\d{2}$/', (string)($release['periodId'] ?? '')) === 1) {
			$end = (new DateTimeImmutable((string)$release['periodId'] . '-01'))->format('Y-m-t');
		}

		return $end !== ''
			&& $end < $today
			&& trim((string)($release['journalEntryId'] ?? '')) === ''
			&& (int)($release['amountCents'] ?? 0) > 0;
	}//end isDue()

	/**
	 * Whether every release with an amount has its entry.
	 *
	 * @param array<int,mixed> $releases The releases.
	 *
	 * @return bool
	 */
	private static function allReleased(array $releases): bool {
		foreach ($releases as $release) {
			if (is_array($release) === true
				&& (int)($release['amountCents'] ?? 0) > 0
				&& trim((string)($release['journalEntryId'] ?? '')) === ''
			) {
				return false;
			}
		}

		return true;
	}//end allReleased()

	/**
	 * The draft journal entry of an opening or a release.
	 *
	 * A prepaid cost moves from the cost account to the balance account on
	 * opening and back on release; deferred revenue the other way round.
	 *
	 * @param array<string,mixed> $schedule The schedule.
	 * @param string              $number   The journal number.
	 * @param string              $date     The entry date.
	 * @param int                 $cents    The amount in cents.
	 * @param bool                $opening  True for the opening entry, false for a release.
	 *
	 * @return array<string,mixed>
	 */
	private static function journal(array $schedule, string $number, string $date, int $cents, bool $opening): array {
		$debit  = (string)$schedule['resultAccount'];
		$credit = (string)$schedule['balanceAccount'];
		if ((((string)$schedule['kind'] === 'prepaid-cost') === $opening) === true) {
			[$debit, $credit] = [$credit, $debit];
		}

		$text      = trim((string)($schedule['description'] ?? ''));
		if ($text === '') {
			$text = (string)$schedule['sourceReference'];
		}

		$suffix = ' (release ' . substr($date, 0, 7) . ')';
		if ($opening === true) {
			$suffix = ' (spread)';
		}

		$text  .= $suffix;
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
			'administrationId' => (string)$schedule['administrationId'],
			'state' => 'draft',
		];
	}//end journal()

	/**
	 * Whether a schedule that is not cancelled already spreads this source.
	 *
	 * @param string $administrationId The administration.
	 * @param string $sourceReference  The invoice line reference.
	 *
	 * @return bool
	 */
	private function alreadySpread(string $administrationId, string $sourceReference): bool {
		$rows = $this->scoped(schema: self::SCHEMA)->findAll(
			['filters' => ['administrationId' => $administrationId, 'sourceReference' => $sourceReference]]
		);
		foreach ($rows as $row) {
			$schedule = ObjectIdentifier::recordWithId(candidate: $row);
			if ($schedule !== null
				&& (string)($schedule['sourceReference'] ?? '') === $sourceReference
				&& (string)($schedule['state'] ?? '') !== 'cancelled'
			) {
				return true;
			}
		}

		return false;
	}//end alreadySpread()

	/**
	 * Save a draft journal entry, post it through `postDirect` and answer its id.
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
	 * Merge fields into the stored schedule.
	 *
	 * @param string              $id     The schedule's id.
	 * @param array<string,mixed> $fields The fields to change.
	 *
	 * @return void
	 */
	private function patch(string $id, array $fields): void {
		$this->objectService->patchObject(objectId: $id, data: $fields, register: $this->settings->getRegisterSlug(), schema: self::SCHEMA);
	}//end patch()

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

	/**
	 * A trimmed text field of the request, required unless said otherwise.
	 *
	 * @param array<string,mixed> $input    The request.
	 * @param string              $key      The field.
	 * @param bool                $required Whether it must be present.
	 *
	 * @return string
	 *
	 * @throws DeferralRefusedException When a required field is empty or a field is too long.
	 */
	private static function text(array $input, string $key, bool $required = true): string {
		$value = ($input[$key] ?? '');
		if (is_scalar($value) === false) {
			$value = '';
		}

		$value = trim((string)$value);
		if ($required === true && $value === '') {
			throw new DeferralRefusedException(message: 'Field ' . $key . ' is required');
		}

		if (mb_strlen($value) > self::MAX_TEXT) {
			throw new DeferralRefusedException(message: 'Field ' . $key . ' is too long');
		}

		return $value;
	}//end text()

	/**
	 * A positive whole number of cents.
	 *
	 * @param mixed $value The request value.
	 *
	 * @return int
	 *
	 * @throws DeferralRefusedException When it is not a whole number above zero.
	 */
	private static function cents(mixed $value): int {
		if (is_int($value) === false && (is_string($value) === false || preg_match('/^\d{1,13}$/', trim($value)) !== 1)) {
			throw new DeferralRefusedException(message: 'The amount must be a whole number of cents above zero');
		}

		$cents = (int)$value;
		if ($cents <= 0 || $cents > 9999999999999) {
			throw new DeferralRefusedException(message: 'The amount must be a whole number of cents above zero');
		}

		return $cents;
	}//end cents()
}//end class
