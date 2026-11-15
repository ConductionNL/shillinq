<?php

/**
 * Fee schedule matching rules
 *
 * The pure choices FeeScheduleService makes over stored schedules: which
 * entry applies to an intake channel (for both a tuple's schedules and a
 * schedule's `amounts`), and whether a stored schedule's window collides
 * with one about to be written.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Support\DateWindow;

/**
 * Channel choice and window overlap over fee schedules (stateless).
 *
 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
 */
final class FeeScheduleMatcher {
	/**
	 * The first entry for the intake channel, else the first channel-less entry.
	 *
	 * Used for both the schedules of a tuple and the `amounts` of a schedule.
	 *
	 * @param array<int, array<string, mixed>> $entries Entries carrying an optional `intakeChannel`.
	 * @param string $intakeChannel The channel the application came in through, or ''.
	 *
	 * @return array<string, mixed>|null The chosen entry, or null.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	public function channelEntry(array $entries, string $intakeChannel): ?array {
		return ($this->exactChannelEntry(entries: $entries, intakeChannel: $intakeChannel) ?? $this->defaultEntry(entries: $entries));
	}//end channelEntry()

	/**
	 * The first entry for exactly this channel; none for an empty channel.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 * @param string $intakeChannel The channel, or ''.
	 *
	 * @return array<string, mixed>|null The entry, or null.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	private function exactChannelEntry(array $entries, string $intakeChannel): ?array {
		if ($intakeChannel === '') {
			return null;
		}

		foreach ($entries as $entry) {
			if ((string)($entry['intakeChannel'] ?? '') === $intakeChannel) {
				return $entry;
			}
		}

		return null;
	}//end exactChannelEntry()

	/**
	 * The first channel-less entry.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 *
	 * @return array<string, mixed>|null The entry, or null.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	private function defaultEntry(array $entries): ?array {
		foreach ($entries as $entry) {
			if ((string)($entry['intakeChannel'] ?? '') === '') {
				return $entry;
			}
		}

		return null;
	}//end defaultEntry()

	/**
	 * Whether a stored schedule competes with the one about to be written: not
	 * the schedule itself, the same channel, and an overlapping window.
	 *
	 * @param array<string, mixed> $schedule The schedule about to be written.
	 * @param array<string, mixed> $candidate A stored schedule of the same kind.
	 *
	 * @return bool True when the two overlap.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	public function overlapsWith(array $schedule, array $candidate): bool {
		$selfId = (string)($schedule['id'] ?? '');
		if ($selfId !== '' && (string)($candidate['id'] ?? '') === $selfId) {
			return false;
		}

		if ((string)($candidate['intakeChannel'] ?? '') !== (string)($schedule['intakeChannel'] ?? '')) {
			return false;
		}

		return DateWindow::overlaps(
			aFrom: (string)($schedule['validFrom'] ?? ''),
			aTo: (string)($schedule['validTo'] ?? ''),
			bFrom: (string)($candidate['validFrom'] ?? ''),
			bTo: (string)($candidate['validTo'] ?? '')
		);
	}//end overlapsWith()

	/**
	 * The refusal naming the schedule a new one collides with.
	 *
	 * @param array<string, mixed> $candidate The overlapping stored schedule.
	 *
	 * @return string The message.
	 *
	 * @spec openspec/changes/leges-at-intake/specs/object-payment-requests/spec.md (REQ-SOPR-006)
	 */
	public function overlapMessage(array $candidate): string {
		$until = '';
		if ((string)($candidate['validTo'] ?? '') !== '') {
			$until = ' to ' . (string)$candidate['validTo'];
		}

		$named = '';
		$candidateId = (string)($candidate['id'] ?? '');
		if ($candidateId !== '') {
			$named = sprintf(' (%s)', $candidateId);
		}

		return sprintf(
			'This fee overlaps the schedule valid from %s%s%s; close that one before opening this.',
			(string)($candidate['validFrom'] ?? '?'),
			$until,
			$named
		);
	}//end overlapMessage()
}//end class
