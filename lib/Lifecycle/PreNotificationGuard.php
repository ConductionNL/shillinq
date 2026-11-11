<?php

/**
 * SEPA Pre-notification Guard
 *
 * ADR-031 exception-path guard enforcing the SDD pre-notification rule
 * (REQ-SDD-003): a collection MUST NOT enter a pain.008 batch unless its
 * pre-notification has been sent (or carried on the invoice line) AND the
 * lead time between notification and collection date satisfies the mandate's
 * configured notice period (default 14 calendar days per SDD CORE 2.6).
 *
 * ADR-031 exception reason: the pre-notification proof + lead-time check
 * spans the PreNotification record and the collection date, which the
 * declarative lifecycle DSL cannot yet express. Replace with a declarative
 * precondition when the engine supports cross-schema date comparison.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Pre-notification proof + lead-time enforcement for SEPA collections.
 *
 * Pure over (collection, preNotification, noticeDays) so it unit-tests
 * directly; the caller supplies the queried PreNotification record.
 *
 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
 */
class PreNotificationGuard {

	/**
	 * Default pre-notification lead time in calendar days (REQ-SDD-003).
	 */
	public const DEFAULT_NOTICE_DAYS = 14;

	/**
	 * Construct the guard.
	 *
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * True iff the collection may be included in a pain.008 batch.
	 *
	 * REQ-SDD-003: the pre-notification must be sent (sentAt not null, or
	 * channel invoice_line) AND the actual lead time from sentAt to the
	 * collection date must be at least the required notice days. Fails closed
	 * if no pre-notification proof exists or the lead time is too short.
	 *
	 * @param array<string,mixed> $collection The DirectDebitCollection.
	 * @param array<string,mixed>|null $preNotification The PreNotification record, if any.
	 * @param int|null $requiredDays Override notice days (defaults to record/14).
	 *
	 * @return bool True when the collection may be batched.
	 *
	 * @spec openspec/specs/bookkeeping-sepa-direct-debit/spec.md
	 */
	public function canIncludeInBatch(
		array $collection,
		?array $preNotification = null,
		?int $requiredDays = null,
	): bool {
		try {
			if ($preNotification === null || $this->hasProof(preNotification: $preNotification) === false) {
				return false;
			}

			$required = $this->requiredDays(preNotification: $preNotification, override: $requiredDays);
			$sentAt = (string)($preNotification['sentAt'] ?? '');
			$dueRaw = (string)($collection['requestedCollectionDate'] ?? '');
			if ($dueRaw === '' || $sentAt === '') {
				// Invoice-line carrier without an explicit sentAt is accepted as
				// proof but its lead time cannot be measured here; the invoice
				// issue date governs the contractual lead (REQ-SDD-003 scenario 2).
				return (string)($preNotification['channel'] ?? '') === 'invoice_line';
			}

			return $this->leadDays(sentAt: $sentAt, due: $dueRaw) >= $required;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PreNotificationGuard: batch-inclusion check failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canIncludeInBatch()

	/**
	 * Proof of notification: either explicitly sent, or carried on the invoice line.
	 *
	 * @param array<string,mixed> $preNotification The PreNotification record.
	 *
	 * @return bool True when the notification is proven.
	 */
	private function hasProof(array $preNotification): bool {
		return (string)($preNotification['sentAt'] ?? '') !== ''
			|| (string)($preNotification['channel'] ?? '') === 'invoice_line';

	}//end hasProof()

	/**
	 * The required notice days: the override, else the record's, else the default.
	 *
	 * @param array<string,mixed> $preNotification The PreNotification record.
	 * @param int|null $override The caller's override.
	 *
	 * @return integer The required lead time in calendar days.
	 */
	private function requiredDays(array $preNotification, int|null $override): int {
		if ($override !== null) {
			return $override;
		}

		return (int)($preNotification['noticeDays'] ?? self::DEFAULT_NOTICE_DAYS);

	}//end requiredDays()

	/**
	 * The lead time in calendar days from the notice to the collection date.
	 *
	 * Compares calendar days only (REQ-SDD-003): the time component is dropped
	 * so a notice sent any time on day D-14 counts as a full 14-day lead.
	 *
	 * @param string $sentAt When the notice was sent.
	 * @param string $due The requested collection date.
	 *
	 * @return integer The signed number of days.
	 */
	private function leadDays(string $sentAt, string $due): int {
		$dueDay = (new DateTimeImmutable($due))->setTime(0, 0);
		$sentDay = (new DateTimeImmutable($sentAt))->setTime(0, 0);
		return (int)$sentDay->diff($dueDay)->format('%r%a');

	}//end leadDays()
}//end class
