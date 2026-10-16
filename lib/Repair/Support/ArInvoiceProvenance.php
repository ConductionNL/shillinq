<?php

/**
 * AR invoice provenance derivation
 *
 * The pure half of BackfillArInvoiceProvenance: given an invoice saved before
 * ARInvoice 0.16.0, the recurring profiles and the invoice's audit trail, work
 * out which of `recurringProfileId`, `billingPeriod`, `customerReference` and
 * `invoiceLines[].glAccount` can be derived, and fill only the gaps.
 *
 * A generated invoice is matched to its profile by the generator's own
 * fingerprint: the customer, the administration, the invoice date the
 * generator would have written for that period (the invoice day clamped to the
 * month, as RecurringInvoiceGenerator::clampedInvoiceDate() does), a period the profile has already generated, and the profile's net
 * amount. Exactly one profile must fit; two make the invoice ambiguous and it
 * is left alone. A quick draft (`DRAFT-` number) is read from its create
 * audit entry, where the dropped reference and line accounts may survive.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair\Support;

use DateTimeImmutable;

/**
 * Derives the provenance an old ARInvoice lost, never overwriting a value.
 *
 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
 */
final class ArInvoiceProvenance {
	/**
	 * The quick draft's provisional number prefix.
	 *
	 * @var string
	 */
	public const QUICK_DRAFT_PREFIX = 'DRAFT-';

	/**
	 * The outcome when two profiles fit one invoice.
	 *
	 * @var string
	 */
	public const AMBIGUOUS = 'ambiguous';

	/**
	 * Whether the invoice is a quick draft.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/shillinq-invoice-quick-draft/spec.md (REQ-IQD-008)
	 */
	public function isQuickDraft(array $invoice): bool {
		return str_starts_with((string)($invoice['invoiceNumber'] ?? ''), self::QUICK_DRAFT_PREFIX);
	}//end isQuickDraft()

	/**
	 * Whether the invoice still has a gap a recurring profile could fill.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
	 */
	public function hasRecurringGap(array $invoice): bool {
		if ($this->isBlank(value: $invoice['recurringProfileId'] ?? null) === true
			|| $this->isBlank(value: $invoice['billingPeriod'] ?? null) === true
		) {
			return true;
		}

		return $this->hasLineGap(invoice: $invoice);
	}//end hasRecurringGap()

	/**
	 * Whether the invoice has a gap a quick-draft audit entry could fill.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/shillinq-invoice-quick-draft/spec.md (REQ-IQD-008)
	 */
	public function hasQuickDraftGap(array $invoice): bool {
		return $this->isBlank(value: $invoice['customerReference'] ?? null) === true || $this->hasLineGap(invoice: $invoice);
	}//end hasQuickDraftGap()

	/**
	 * The profile that generated the invoice, keyed by id; null when none fits,
	 * self::AMBIGUOUS when more than one does.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 * @param array<string, array<string, mixed>> $profiles The profiles by id.
	 *
	 * @return string|null The profile id, self::AMBIGUOUS, or null.
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
	 */
	public function matchProfile(array $invoice, array $profiles): ?string {
		$known = (string)($invoice['recurringProfileId'] ?? '');
		if ($known !== '') {
			if (isset($profiles[$known]) === true && $this->fits(invoice: $invoice, profile: $profiles[$known]) === true) {
				return $known;
			}

			return null;
		}

		$matches = [];
		foreach ($profiles as $profileId => $profile) {
			if ($this->fits(invoice: $invoice, profile: $profile) === true) {
				$matches[] = (string)$profileId;
			}
		}

		if (count($matches) > 1) {
			return self::AMBIGUOUS;
		}

		return ($matches[0] ?? null);
	}//end matchProfile()

	/**
	 * Fill the invoice's gaps from the profile that generated it.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 * @param string $profileId The profile id.
	 * @param array<string, mixed> $profile The profile.
	 *
	 * @return array<string, mixed> The invoice with its gaps filled.
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/recurring-invoicing/spec.md (REQ-RIN-011)
	 */
	public function fillFromProfile(array $invoice, string $profileId, array $profile): array {
		if ($this->isBlank(value: $invoice['recurringProfileId'] ?? null) === true) {
			$invoice['recurringProfileId'] = $profileId;
		}

		if ($this->isBlank(value: $invoice['billingPeriod'] ?? null) === true) {
			$invoice['billingPeriod'] = substr((string)$invoice['invoiceDate'], 0, 7);
		}

		$accounts = [];
		foreach (array_values((array)($profile['lines'] ?? [])) as $index => $line) {
			$accounts[$index] = ($line['revenueAccount'] ?? null);
		}

		return $this->fillLineAccounts(invoice: $invoice, accounts: $accounts);
	}//end fillFromProfile()

	/**
	 * Fill a quick draft's gaps from its create audit entry.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 * @param array<string, mixed> $created The create entry's changed fields, as field => [old, new].
	 *
	 * @return array<string, mixed> The invoice with its gaps filled.
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/shillinq-invoice-quick-draft/spec.md (REQ-IQD-008)
	 */
	public function fillFromAudit(array $invoice, array $created): array {
		$reference = $this->createdValue(created: $created, field: 'customerReference');
		if ($this->isBlank(value: $invoice['customerReference'] ?? null) === true && $this->isBlank(value: $reference) === false) {
			$invoice['customerReference'] = trim((string)$reference);
		}

		// The current shape writes `invoiceLines`; the older quick draft wrote
		// `lines` with a lineNumber from 1. Both are positional.
		$lines = $this->createdValue(created: $created, field: 'invoiceLines');
		if (is_array($lines) === false) {
			$lines = $this->createdValue(created: $created, field: 'lines');
		}

		$accounts = [];
		foreach (array_values((array)$lines) as $index => $line) {
			$accounts[$index] = (((array)$line)['glAccount'] ?? null);
		}

		return $this->fillLineAccounts(invoice: $invoice, accounts: $accounts);
	}//end fillFromAudit()

	/**
	 * Whether the invoice fits the profile's generator fingerprint.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 * @param array<string, mixed> $profile The profile.
	 *
	 * @return bool
	 */
	private function fits(array $invoice, array $profile): bool {
		$date = (string)($invoice['invoiceDate'] ?? '');
		$period = substr($date, 0, 7);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
			|| (string)($invoice['customerId'] ?? '') !== (string)($profile['customerReference'] ?? '')
			|| (string)($invoice['customerId'] ?? '') === ''
			|| (string)($invoice['administrationId'] ?? '') !== (string)($profile['administrationId'] ?? '')
		) {
			return false;
		}

		$first = substr((string)($profile['startDate'] ?? ''), 0, 7);
		$last = (string)($profile['lastBillingPeriod'] ?? '');
		if ($first === '' || $last === '' || $period < $first || $period > $last) {
			return false;
		}

		// The generator's invoice date: the invoice day clamped to the month.
		$daysInMonth = (int)(new DateTimeImmutable($period . '-01'))->format('t');
		$invoiceDay = min(max(1, min(31, (int)($profile['invoiceDay'] ?? 1))), $daysInMonth);
		if (sprintf('%s-%02d', $period, $invoiceDay) !== $date) {
			return false;
		}

		return abs($this->profileNet(profile: $profile) - (float)($invoice['netAmount'] ?? -1)) < 0.005;
	}//end fits()

	/**
	 * The profile's net amount for one period, as the generator sums it.
	 *
	 * @param array<string, mixed> $profile The profile.
	 *
	 * @return float
	 */
	private function profileNet(array $profile): float {
		$net = 0.0;
		foreach ((array)($profile['lines'] ?? []) as $line) {
			$net += ((float)($line['quantity'] ?? 1) * (float)($line['unitPrice'] ?? 0));
		}

		return round($net, 2);
	}//end profileNet()

	/**
	 * Give each line without an account the account at its position.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 * @param array<int, mixed> $accounts The account per line position.
	 *
	 * @return array<string, mixed> The invoice.
	 */
	private function fillLineAccounts(array $invoice, array $accounts): array {
		if (is_array($invoice['invoiceLines'] ?? null) === false) {
			return $invoice;
		}

		$lines = [];
		foreach (array_values($invoice['invoiceLines']) as $index => $line) {
			$account = ($accounts[$index] ?? null);
			if (is_array($line) === true && $this->isBlank(value: $line['glAccount'] ?? null) === true && $this->isBlank(value: $account) === false) {
				$line['glAccount'] = trim((string)$account);
			}

			$lines[] = $line;
		}

		$invoice['invoiceLines'] = $lines;
		return $invoice;
	}//end fillLineAccounts()

	/**
	 * Whether any line lacks an account.
	 *
	 * @param array<string, mixed> $invoice The invoice.
	 *
	 * @return bool
	 */
	private function hasLineGap(array $invoice): bool {
		foreach ((array)($invoice['invoiceLines'] ?? []) as $line) {
			if (is_array($line) === true && $this->isBlank(value: $line['glAccount'] ?? null) === true) {
				return true;
			}
		}

		return false;
	}//end hasLineGap()

	/**
	 * The new value of a field in a create entry's changed map.
	 *
	 * @param array<string, mixed> $created The changed map.
	 * @param string $field The field.
	 *
	 * @return mixed The new value, or null.
	 */
	private function createdValue(array $created, string $field): mixed {
		$change = ($created[$field] ?? null);
		if (is_array($change) === true && array_key_exists('new', $change) === true) {
			return $change['new'];
		}

		return null;
	}//end createdValue()

	/**
	 * Whether a value is missing: null, or a string that is empty after trimming.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isBlank(mixed $value): bool {
		return $value === null || (is_string($value) === true && trim($value) === '');
	}//end isBlank()
}//end class
