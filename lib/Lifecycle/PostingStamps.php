<?php

/**
 * Shillinq PostingStamps
 *
 * The fields a post gives a ledger transaction. The mandatory NL and EU
 * ledger rules of LedgerIntegrityChecks ask a POSTED entry to be locked
 * against edits (`postingLocked`), kept long enough (`retentionUntil`, at
 * least 7 years under art. 52 AWR and 10 for real estate), ensured intact
 * (`integrityVerified`, VAT Directive art. 233) and on an audit trail that
 * names who posted it and when (`auditTrail`). A draft carries none of
 * these: they are what the post itself establishes. So the post stamps
 * them, and the post's guard judges the entry as the post leaves it (#516).
 *
 * `integrityVerified` records the controls a posted entry sits under: it is
 * locked, corrections go by reversal, and its audit rows are insert-only
 * (AuditTrailGuard). The hash chain of ledger-sealed-entries adds proof on
 * top of that.
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
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Applies the posting stamps to a GLTransaction.
 *
 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.5
 */
final class PostingStamps {

	/**
	 * Years a posted entry is kept: the longest NL term (art. 52 AWR, real
	 * estate), which also covers the general 7 years.
	 *
	 * @var int
	 */
	public const RETENTION_YEARS = 10;

	/**
	 * The audit-trail action a post records.
	 *
	 * @var string
	 */
	public const TRAIL_ACTION = 'post';

	/**
	 * Stamp a transaction as posted.
	 *
	 * Retention runs to 31 December of the posting year plus RETENTION_YEARS,
	 * and a later retention already on the entry stays. Without a readable
	 * posting date no retention is set, so the completeness rules refuse the
	 * entry. The post is recorded once on the audit trail.
	 *
	 * @param array<string,mixed> $transaction The GLTransaction.
	 * @param string $user The posting user id.
	 * @param DateTimeImmutable $now The moment of posting.
	 *
	 * @return array<string,mixed> The stamped transaction.
	 *
	 * @spec openspec/changes/ledger-posting-path/tasks.md#task-2.5
	 */
	public function apply(array $transaction, string $user, DateTimeImmutable $now): array {
		$transaction['postingLocked'] = true;
		$transaction['integrityVerified'] = true;

		$retention = $this->retentionFor(postingDate: (string)($transaction['postingDate'] ?? ''));
		$existing = (string)($transaction['retentionUntil'] ?? '');
		if ($retention !== '' && $existing < $retention) {
			$transaction['retentionUntil'] = $retention;
		}

		$trail = ($transaction['auditTrail'] ?? []);
		if (is_array($trail) === false) {
			$trail = [];
		}

		if ($this->postRecorded(trail: $trail) === false) {
			$trail[] = [
				'user' => $user,
				'timestamp' => $now->format(DateTimeInterface::ATOM),
				'action' => self::TRAIL_ACTION,
			];
		}

		$transaction['auditTrail'] = array_values($trail);
		return $transaction;
	}//end apply()

	/**
	 * The retention date for a posting date, '' when it cannot be read.
	 *
	 * @param string $postingDate The posting date (Y-m-d).
	 *
	 * @return string The retention date (Y-m-d), or ''.
	 */
	private function retentionFor(string $postingDate): string {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($postingDate, 0, 10));
		if ($date === false) {
			return '';
		}

		return ((int)$date->format('Y') + self::RETENTION_YEARS) . '-12-31';
	}//end retentionFor()

	/**
	 * Whether the audit trail already records a post.
	 *
	 * @param array<int|string,mixed> $trail The audit trail.
	 *
	 * @return bool
	 */
	private function postRecorded(array $trail): bool {
		foreach ($trail as $entry) {
			if (is_array($entry) === true && ($entry['action'] ?? '') === self::TRAIL_ACTION) {
				return true;
			}
		}

		return false;
	}//end postRecorded()
}//end class
