<?php

/**
 * Books a bank feed line on arrival when it can only mean one open invoice.
 *
 * The rule is deliberately narrow: exactly one open invoice of the line's
 * administration has the same amount AND its invoice number (or payment
 * reference) appears in the line's remittance or end-to-end reference.
 * Anything less (no candidate, two, or an amount that only nearly fits) stays
 * unmatched for a person. A match it makes is confirmed as `system:bankfeed`
 * with the rule in the reason, through ManualMatchService, so the invoice is
 * settled by the same listener as a match by hand.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Bank
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

namespace OCA\Shillinq\Service\Bank;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\Contracts\PaymentPlanBankMatcherInterface;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Exact-match booking of feed lines (REQ-BCON-003).
 *
 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
 */
class ExactMatchBooker {
	/**
	 * The confirming actor of an automatic match.
	 *
	 * @var string
	 */
	public const ACTOR = 'system:bankfeed';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object surface.
	 * @param ManualMatchService     $matches       Writes and confirms the match.
	 * @param RegisterLocation        $registerLocation Where the register lives (slug + OpenRegister availability).
	 * @param LoggerInterface        $logger        Logger.
	 * @param PaymentPlanBankMatcherInterface|null $plans    Books a line naming a payment plan's reference to that plan.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ManualMatchService $matches,
		private readonly RegisterLocation $registerLocation,
		private readonly LoggerInterface $logger,
		private readonly ?PaymentPlanBankMatcherInterface $plans = null,
	) {

	}//end __construct()

	/**
	 * Book one line if exactly one open invoice fits it.
	 *
	 * @param array<string,mixed> $line A freshly written BankStatementLine with its id.
	 *
	 * @return array<string,mixed>|null The confirmed match, or null when the line waits for a person.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public function book(array $line): ?array {
		$amount = round((float)($line['amount'] ?? 0), 2);
		if ($amount === 0.0) {
			return null;
		}

		// Payment plans (REQ-RPPL-003): a line naming one active plan's
		// payment reference pays that plan's instalments, not one invoice.
		$planMatch = $this->planMatch(line: $line, amount: $amount);
		if ($planMatch !== null) {
			return $planMatch;
		}

		$candidate = $this->soleCandidate(line: $line, amount: $amount);
		if ($candidate === null) {
			return null;
		}

		return $this->confirm(line: $line, candidate: $candidate);

	}//end book()

	/**
	 * The payment plan's match for an incoming line, or null.
	 *
	 * @param array<string,mixed> $line   The BankStatementLine.
	 * @param float               $amount Its rounded, signed amount.
	 *
	 * @return array<string,mixed>|null
	 */
	private function planMatch(array $line, float $amount): array|null {
		if ($amount > 0 && $this->plans !== null) {
			return $this->plans->book(line: $line);
		}

		return null;

	}//end planMatch()

	/**
	 * The one open invoice with the line's exact amount whose number or
	 * payment reference the line names, with that reference and the booking
	 * reason; null when there is none or more than one.
	 *
	 * @param array<string,mixed> $line   The BankStatementLine.
	 * @param float               $amount Its rounded, signed amount.
	 *
	 * @return array{invoice:array<string,mixed>,reference:string,reason:string}|null
	 */
	private function soleCandidate(array $line, float $amount): array|null {
		$candidates = $this->referencedCandidates(
			invoices: $this->invoicesOfAmount(line: $line, amount: $amount),
			text: $this->searchText(line: $line)
		);
		if (count($candidates) !== 1) {
			return null;
		}

		$candidates[0]['reason'] = $this->bookingReason(amount: $amount, reference: $candidates[0]['reference']);
		return $candidates[0];

	}//end soleCandidate()

	/**
	 * The reason recorded on an automatic exact match.
	 *
	 * @param float  $amount    The line's rounded, signed amount.
	 * @param string $reference The invoice number or payment reference found.
	 *
	 * @return string
	 */
	private function bookingReason(float $amount, string $reference): string {
		return sprintf(
			'Booked on arrival by %s: exact amount EUR %s and reference %s',
			self::ACTOR,
			number_format(abs($amount), 2, '.', ','),
			$reference
		);

	}//end bookingReason()

	/**
	 * The open invoices (incoming line) or purchase transactions (outgoing
	 * line) of the line's administration asking exactly the line's amount.
	 *
	 * @param array<string,mixed> $line   The BankStatementLine.
	 * @param float               $amount Its rounded, signed amount.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function invoicesOfAmount(array $line, float $amount): array {
		$schema = 'APTransaction';
		if ($amount > 0) {
			$schema = 'ARInvoice';
		}

		$open = $this->openInvoices(schema: $schema, administrationId: (string)($line['administrationId'] ?? ''));
		return array_values(
			array_filter(
				$open,
				fn (array $invoice): bool => abs($this->invoiceAmount(schema: $schema, invoice: $invoice) - abs($amount)) < 0.005
			)
		);

	}//end invoicesOfAmount()

	/**
	 * The line's remittance and references, lower-cased, to search in.
	 *
	 * @param array<string,mixed> $line The BankStatementLine.
	 *
	 * @return string
	 */
	private function searchText(array $line): string {
		$parts = [(string)($line['remittanceInfo'] ?? ''), (string)($line['endToEndRef'] ?? ''), (string)($line['reference'] ?? '')];
		return strtolower(trim(implode(' ', $parts)));

	}//end searchText()

	/**
	 * The invoices the text names, each with the reference found.
	 *
	 * @param array<int,array<string,mixed>> $invoices The invoices of the right amount.
	 * @param string                         $text     The lower-cased search text.
	 *
	 * @return list<array{invoice:array<string,mixed>,reference:string}>
	 */
	private function referencedCandidates(array $invoices, string $text): array {
		$candidates = [];
		foreach ($invoices as $invoice) {
			$reference = self::referenceFound(text: $text, invoice: $invoice);
			if ($reference !== '') {
				$candidates[] = ['invoice' => $invoice, 'reference' => $reference];
			}
		}

		return $candidates;

	}//end referencedCandidates()

	/**
	 * Confirm the match to the candidate invoice; a failure is logged and the
	 * line waits for a person.
	 *
	 * @param array<string,mixed>                                                 $line      The BankStatementLine.
	 * @param array{invoice:array<string,mixed>,reference:string,reason:string} $candidate The sole candidate.
	 *
	 * @return array<string,mixed>|null
	 */
	private function confirm(array $line, array $candidate): array|null {
		try {
			return $this->matches->matchInvoices(
				line: $line,
				targetIds: [(string)$candidate['invoice']['id']],
				actor: self::ACTOR,
				reason: $candidate['reason']
			);
		} catch (Throwable $e) {
			$this->logger->warning('ExactMatchBooker: exact match could not be booked, line waits', ['exception' => $e->getMessage()]);
			return null;
		}

	}//end confirm()

	/**
	 * The invoice number or payment reference the text names as a whole word, or ''.
	 *
	 * @param string              $text    Lower-cased remittance and references.
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public static function referenceFound(string $text, array $invoice): string {
		foreach (['invoiceNumber', 'paymentReference'] as $field) {
			$value = trim((string)($invoice[$field] ?? ''));
			if ($value === '') {
				continue;
			}

			$pattern = '/(^|[^a-z0-9])' . preg_quote(strtolower($value), '/') . '($|[^a-z0-9])/';
			if (preg_match($pattern, $text) === 1) {
				return $value;
			}
		}

		return '';

	}//end referenceFound()

	/**
	 * Open invoices of the administration, with their ids.
	 *
	 * @param string $schema           `ARInvoice` or `APTransaction`.
	 * @param string $administrationId The administration.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function openInvoices(string $schema, string $administrationId): array {
		$open = ['ARInvoice' => ['issued', 'overdue'], 'APTransaction' => ['issued', 'overdue']][$schema];
		$field = InvoiceSettlementService::STATE_FIELDS[$schema];
		$rows = $this->objectService->setRegister($this->registerLocation->getRegisterSlug())->setSchema($schema)
			->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => 5000]);
		$invoices = [];
		foreach ($rows as $row) {
			$invoice = ObjectIdentifier::recordWithId(candidate: $row);
			if ($invoice !== null && in_array((string)($invoice[$field] ?? ''), $open, true) === true) {
				$invoices[] = $invoice;
			}
		}

		return $invoices;

	}//end openInvoices()

	/**
	 * The amount an invoice asks for.
	 *
	 * @param string              $schema  The invoice schema.
	 * @param array<string,mixed> $invoice The invoice.
	 *
	 * @return float
	 */
	private function invoiceAmount(string $schema, array $invoice): float {
		if ($schema === 'ARInvoice') {
			return round((float)($invoice['amountDue'] ?? ($invoice['grossAmount'] ?? 0)), 2);
		}

		return round((float)($invoice['totalAmount'] ?? 0), 2);

	}//end invoiceAmount()
}//end class
