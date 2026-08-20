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
			if ($preNotification === null) {
				return false;
			}

			$channel = (string)($preNotification['channel'] ?? '');
			$sentAt = (string)($preNotification['sentAt'] ?? '');

			// Proof of notification: either explicitly sent, or carried on the invoice line.
			$hasProof = ($sentAt !== '' || $channel === 'invoice_line');
			if ($hasProof === false) {
				return false;
			}

			$required = $requiredDays;
			if ($required === null) {
				$required = (int)($preNotification['noticeDays'] ?? self::DEFAULT_NOTICE_DAYS);
			}

			$dueRaw = (string)($collection['requestedCollectionDate'] ?? '');
			if ($dueRaw === '' || $sentAt === '') {
				// Invoice-line carrier without an explicit sentAt is accepted as
				// proof but its lead time cannot be measured here; the invoice
				// issue date governs the contractual lead (REQ-SDD-003 scenario 2).
				return $channel === 'invoice_line';
			}

			// Compare calendar days only (REQ-SDD-003): drop the time component
			// so a notice sent any time on day D-14 counts as a full 14-day lead.
			$due = (new DateTimeImmutable($dueRaw))->setTime(0, 0);
			$sent = (new DateTimeImmutable($sentAt))->setTime(0, 0);
			$leadDays = (int)$sent->diff($due)->format('%r%a');

			return $leadDays >= $required;
		} catch (\Throwable $e) {
			$this->logger->error(
				'PreNotificationGuard: batch-inclusion check failed — denying (fail-closed)',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end canIncludeInBatch()
}//end class
