<?php

/**
 * Object Request Bank Settlement
 *
 * Settles an object payment request when its `payment-request` bank match is
 * confirmed, automatically or by the bookkeeper (REQ-ORS-004, design D4): it
 * appends a `bank-transfer` settlement with the line's amount and reference,
 * stamps `settledAt` once the request reports paid, and books the receipt
 * with the bank account of the statement on the debit side and the revenue
 * account mapped to the request type on the credit side. A request with an
 * invoice behind it settles that invoice instead and books nothing on the
 * object, so income is booked once. The same line settles a request once.
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
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-004)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Bank;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\PaymentRevenueAccountResolver;
use OCA\Shillinq\Service\PaymentSettlementService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Util\ObjectIdentifier;
use Psr\Log\LoggerInterface;

/**
 * A confirmed payment-request match to a settled, booked request.
 *
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-004)
 */
class ObjectRequestBankSettlement {
	/**
	 * Who a bank-match settlement is recorded for.
	 *
	 * @var string
	 */
	public const ACTOR = 'system:bank-match';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param SettingsService $settings The register slug.
	 * @param PaymentSettlementService $settlements Builds, appends and stamps settlements.
	 * @param PaymentRevenueAccountResolver $revenueAccounts The revenue account per request type.
	 * @param InvoiceSettlementService $invoices Settles an invoice behind a request.
	 * @param ManualMatchService $manualMatch Posts the receipt against the line's bank account.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly SettingsService $settings,
		private readonly PaymentSettlementService $settlements,
		private readonly PaymentRevenueAccountResolver $revenueAccounts,
		private readonly InvoiceSettlementService $invoices,
		private readonly ManualMatchService $manualMatch,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Settle the request a confirmed payment-request match names.
	 *
	 * @param array<string, mixed> $match The confirmed ReconciliationMatch.
	 *
	 * @return string What happened: settled, invoice, unbooked, already or missing.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-004)
	 */
	public function settle(array $match): string {
		$requestId = (string)($match['matchedObjectId'] ?? (((array)($match['targetRefs'] ?? []))[0] ?? ''));
		$request = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'PaymentRequest'), id: $requestId);
		$line = ObjectIdentifier::findOne(scoped: $this->scoped(schema: 'BankStatementLine'), id: (string)($match['bankStatementLineId'] ?? ''));
		if ($request === null || $line === null || (string)($request['subjectKind'] ?? '') !== 'object') {
			return 'missing';
		}

		$reference = (string)($line['endToEndRef'] ?? '');
		if ($reference === '') {
			$reference = (string)($line['lineId'] ?? ($line['id'] ?? ''));
		}

		foreach ((array)($request['settlements'] ?? []) as $existing) {
			if (($existing['method'] ?? '') === 'bank-transfer' && (string)($existing['reference'] ?? '') === $reference) {
				return 'already';
			}
		}

		$amount = round((float)($match['matchedAmount'] ?? ($line['amount'] ?? 0)), 2);
		$settledAt = self::dateTime(date: (string)($line['valueDate'] ?? ''));
		$request = $this->settlements->append(
			request: $request,
			settlement: $this->settlements->build(
				input: ['method' => 'bank-transfer', 'amount' => $amount, 'reference' => $reference],
				actor: self::ACTOR,
				settledAt: $settledAt
			)
		);
		$request = $this->settlements->stampSettled(request: $request, settledAt: $settledAt, via: 'bank-transfer');

		$outcome = $this->bookOrSettleInvoice(request: $request, line: $line, amount: $amount);

		$patch = ['settlements' => $request['settlements']];
		foreach (['settledAt', 'settledVia', 'revenueAccount'] as $field) {
			if ((string)($request[$field] ?? '') !== '') {
				$patch[$field] = $request[$field];
			}
		}

		$this->scoped(schema: 'PaymentRequest')->patchObject($requestId, $patch);

		return $outcome;
	}//end settle()

	/**
	 * Settle the invoice behind the request, or book the receipt on the object.
	 *
	 * @param array<string, mixed> $request The request, by reference: gains revenueAccount.
	 * @param array<string, mixed> $line The bank line.
	 * @param float $amount The amount that arrived.
	 *
	 * @return string settled, invoice or unbooked.
	 */
	private function bookOrSettleInvoice(array &$request, array $line, float $amount): string {
		$invoiceId = (string)($request['invoiceReference'] ?? '');
		if ($invoiceId !== '') {
			$this->invoices->settle(
				match: [
					'matchType' => 'ar-invoice',
					'targetRefs' => [$invoiceId],
					'matchedAmount' => $amount,
					'partial' => $amount + 0.005 < (float)($request['amount'] ?? 0),
				]
			);
			return 'invoice';
		}

		$revenue = $this->revenueAccounts->resolve(requestType: (string)($request['requestType'] ?? ''));
		if ($revenue === null) {
			$this->logger->warning(
				'ObjectRequestBankSettlement: no revenue account mapped, the receipt is not booked',
				['requestType' => (string)($request['requestType'] ?? ''), 'request' => (string)($request['id'] ?? '')]
			);
			return 'unbooked';
		}

		$request['revenueAccount'] = $revenue;
		$subject = (array)($request['subject'] ?? []);
		$this->manualMatch->postReceipt(
			line: $line,
			account: $revenue,
			description: sprintf(
				'%s on %s %s, reference %s',
				(string)($request['requestType'] ?? ''),
				(string)($subject['type'] ?? 'object'),
				(string)($subject['id'] ?? ''),
				(string)($request['paymentReference'] ?? '')
			)
		);

		return 'settled';
	}//end bookOrSettleInvoice()

	/**
	 * A value date as a date-time.
	 *
	 * @param string $date The value date, `Y-m-d` or a date-time.
	 *
	 * @return string The date-time, or empty for now.
	 */
	private static function dateTime(string $date): string {
		if (strlen($date) === 10) {
			return $date . 'T00:00:00Z';
		}

		return $date;
	}//end dateTime()

	/**
	 * The object service scoped to shillinq's register and one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return ObjectServiceInterface
	 */
	private function scoped(string $schema): ObjectServiceInterface {
		return $this->objectService->setRegister($this->settings->getRegisterSlug())->setSchema($schema);
	}//end scoped()
}//end class
