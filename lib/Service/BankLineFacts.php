<?php

/**
 * Bank Line Facts
 *
 * The fields of one BankStatementLine that the bank-rule predicate vocabulary
 * reads, plus the optional anchor date a `date-window` predicate measures
 * against (REQ-BR-005 / REQ-BR-011).
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
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Read-only view of one bank line as the predicates see it.
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-011)
 */
final class BankLineFacts {

	/**
	 * Absolute line amount (bank signs are irrelevant to a rule).
	 *
	 * @var float
	 */
	public readonly float $absAmount;

	/**
	 * Line reference.
	 *
	 * @var string
	 */
	public readonly string $reference;

	/**
	 * Line narrative (fallback for the reference).
	 *
	 * @var string
	 */
	public readonly string $narrative;

	/**
	 * Counterparty name as the bank reports it.
	 *
	 * @var string
	 */
	public readonly string $counterpartyName;

	/**
	 * Counterparty IBAN as the bank reports it.
	 *
	 * @var string
	 */
	public readonly string $counterpartyIban;

	/**
	 * Line value date.
	 *
	 * @var string
	 */
	public readonly string $valueDate;

	/**
	 * Optional Y-m-d anchor for date-window predicates.
	 *
	 * @var string|null
	 */
	public readonly ?string $anchorDate;

	/**
	 * Read the predicate fields from one bank line.
	 *
	 * @param array<string,mixed> $line       One BankStatementLine row.
	 * @param string|null         $anchorDate Optional Y-m-d anchor for date-window.
	 */
	public function __construct(array $line, ?string $anchorDate) {
		$this->absAmount = abs((float)($line['amount'] ?? 0.0));
		$this->reference = (string)($line['reference'] ?? '');
		$this->narrative = (string)($line['narrative'] ?? '');
		$this->counterpartyName = (string)($line['counterpartyName'] ?? '');
		$this->counterpartyIban = (string)($line['counterpartyIban'] ?? '');
		$this->valueDate = (string)($line['valueDate'] ?? '');
		$this->anchorDate = $anchorDate;
	}//end __construct()

	/**
	 * True when both the anchor and the value date are present, so a
	 * date-window predicate can be measured at all.
	 *
	 * @return bool True when the window is determinable.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-005)
	 */
	public function hasDateWindowDates(): bool {
		return $this->anchorDate !== null && $this->anchorDate !== '' && $this->valueDate !== '';
	}//end hasDateWindowDates()

	/**
	 * The reference, or the narrative when the reference is empty.
	 *
	 * @return string The text a reference-match predicate is matched against.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md (REQ-BR-005)
	 */
	public function referenceOrNarrative(): string {
		if ($this->reference !== '') {
			return $this->reference;
		}

		return $this->narrative;
	}//end referenceOrNarrative()
}//end class
