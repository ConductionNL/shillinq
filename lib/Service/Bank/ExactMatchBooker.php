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
use OCA\Shillinq\Service\SettingsService;
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
	 * @param SettingsService        $settings      Register slug.
	 * @param LoggerInterface        $logger        Logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ManualMatchService $matches,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
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

		$schema = 'APTransaction';
		if ($amount > 0) {
			$schema = 'ARInvoice';
		}

		$parts = [(string)($line['remittanceInfo'] ?? ''), (string)($line['endToEndRef'] ?? ''), (string)($line['reference'] ?? '')];
		$text = strtolower(trim(implode(' ', $parts)));
		$candidates = [];
		foreach ($this->openInvoices(schema: $schema, administrationId: (string)($line['administrationId'] ?? '')) as $invoice) {
			if (abs($this->invoiceAmount(schema: $schema, invoice: $invoice) - abs($amount)) >= 0.005) {
				continue;
			}

			$reference = self::referenceFound(text: $text, invoice: $invoice);
			if ($reference !== '') {
				$candidates[] = ['invoice' => $invoice, 'reference' => $reference];
			}
		}

		if (count($candidates) !== 1) {
			return null;
		}

		$invoice = $candidates[0]['invoice'];
		$reason = sprintf(
			'Booked on arrival by %s: exact amount EUR %s and reference %s',
			self::ACTOR,
			number_format(abs($amount), 2, '.', ','),
			$candidates[0]['reference']
		);
		try {
			return $this->matches->matchInvoices(line: $line, targetIds: [(string)$invoice['id']], actor: self::ACTOR, reason: $reason);
		} catch (Throwable $e) {
			$this->logger->warning('ExactMatchBooker: exact match could not be booked, line waits', ['exception' => $e->getMessage()]);
			return null;
		}

	}//end book()

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
		$rows = $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema)
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
