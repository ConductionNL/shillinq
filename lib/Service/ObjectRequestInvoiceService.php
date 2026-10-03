<?php

/**
 * Object Request Invoice Service
 *
 * Issues the invoice a payer asked for when another app raised a payment
 * request on one of its objects. A player who chose "Request an invoice" at
 * registration gets one issued ARInvoice for the request's description and
 * amount, billed to their CustomerMaster, and the request names it in
 * `invoiceReference`. From then on a capture settles that invoice and books
 * nothing on the object, so the income is booked once (REQ-SCON-006).
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
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use RuntimeException;

/**
 * Builds and saves the issued invoice behind an object payment request.
 *
 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-006)
 */
class ObjectRequestInvoiceService {

	/**
	 * App config key: request type => VAT rate in percent, as JSON.
	 *
	 * A type without a rate is exempt (category E, rate 0).
	 *
	 * @var string
	 */
	public const VAT_CONFIG_KEY = 'paymentRequestVatRates';

	/**
	 * Days an invoice is due after its date when the request names no due date.
	 *
	 * @var int
	 */
	private const DEFAULT_TERM_DAYS = 14;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-083).
	 * @param ContributionDebtorResolver $debtors Resolves or creates the debtor's CustomerMaster.
	 * @param IAppConfig $appConfig The register slug and the VAT rates per type.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly ContributionDebtorResolver $debtors,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Issue one invoice for a request and answer its uuid.
	 *
	 * @param array<string, mixed> $request The validated request, before it is saved.
	 * @param string $administrationId The administration the invoice belongs to.
	 * @param string $today The invoice date (Y-m-d).
	 *
	 * @return string The ARInvoice uuid.
	 *
	 * @throws InvalidArgumentException When the request has no administration, no debtor, or no amount.
	 * @throws RuntimeException When OpenRegister answers the save without an id.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-006)
	 */
	public function issue(array $request, string $administrationId, string $today): string {
		if (preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $administrationId) !== 1) {
			throw new InvalidArgumentException('An invoice on request needs the administrationId of the administration that bills it.');
		}

		$debtor = $request['debtor'] ?? null;
		if (is_array($debtor) === false || $debtor === []) {
			throw new InvalidArgumentException('An invoice on request needs a debtor: a customerMasterId, or a name and an email.');
		}

		$gross = round((float)($request['amount'] ?? 0), 2);
		if ($gross <= 0.0) {
			throw new InvalidArgumentException('An invoice on request needs an amount above zero.');
		}

		$customer = $this->debtors->resolve(debtor: $debtor, administrationId: $administrationId);
		$invoice = $this->buildInvoice(
			request: $request,
			customerMasterId: $customer['customerMasterId'],
			administrationId: $administrationId,
			today: $today,
		);

		$saved = $this->objectService->saveObject(
			object: $invoice,
			register: $this->registerSlug(),
			schema: 'ARInvoice',
			_rbac: false,
		);

		$uuid = ObjectIdentifier::resolve(saved: $saved);
		if ($uuid === '') {
			throw new RuntimeException('The invoice was saved but OpenRegister answered without an id.');
		}

		return $uuid;
	}//end issue()

	/**
	 * The issued ARInvoice payload for a request: one line, gross as asked.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string $customerMasterId The debtor's customer.
	 * @param string $administrationId The administration.
	 * @param string $today The invoice date (Y-m-d).
	 *
	 * @return array<string, mixed> The ARInvoice payload.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-006)
	 */
	public function buildInvoice(array $request, string $customerMasterId, string $administrationId, string $today): array {
		$gross = round((float)($request['amount'] ?? 0), 2);
		$rate = $this->vatRate(requestType: (string)($request['requestType'] ?? ''));
		$net = $gross;
		$vat = 0.0;
		$category = 'E';
		if ($rate > 0.0) {
			$net = round($gross / (1 + ($rate / 100)), 2);
			$vat = round($gross - $net, 2);
			$category = 'S';
		}

		$description = trim((string)($request['description'] ?? ''));
		if ($description === '') {
			$description = (string)($request['requestType'] ?? 'Payment request');
		}

		$invoice = [
			'invoiceNumber' => $this->invoiceNumber(request: $request, today: $today),
			'administrationId' => $administrationId,
			'periodId' => substr($today, 0, 7),
			'customerId' => $customerMasterId,
			'invoiceDate' => $today,
			'dueDate' => $this->dueDate(request: $request, today: $today),
			'currency' => (string)($request['currency'] ?? 'EUR'),
			'netAmount' => $net,
			'vatAmount' => $vat,
			'grossAmount' => $gross,
			'lifecycleState' => 'issued',
			'invoiceType' => 'standard',
			// `invoiceLines` is the line property ARInvoice declares (EN 16931 BG-25).
			'invoiceLines' => [
				[
					'lineId' => '1',
					'quantity' => 1,
					'unitCode' => 'C62',
					'itemName' => $description,
					'netPrice' => $net,
					'netAmount' => $net,
					'vatCategory' => $category,
					'vatRate' => $rate,
				],
			],
		];

		$reference = trim((string)($request['paymentReference'] ?? ''));
		if ($reference !== '') {
			$invoice['invoiceNote'] = $reference;
		}

		return $invoice;
	}//end buildInvoice()

	/**
	 * The VAT rate mapped to a request type, 0 when none is.
	 *
	 * @param string $requestType The request type.
	 *
	 * @return float The rate in percent.
	 */
	private function vatRate(string $requestType): float {
		$raw = $this->appConfig->getValueString('shillinq', self::VAT_CONFIG_KEY, '');
		if ($raw === '') {
			return 0.0;
		}

		$rates = json_decode($raw, true);
		if (is_array($rates) === false || is_numeric($rates[$requestType] ?? null) === false) {
			return 0.0;
		}

		return max(0.0, (float)$rates[$requestType]);
	}//end vatRate()

	/**
	 * A unique invoice number: REQ, the year, and a random part.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string $today The invoice date.
	 *
	 * @return string The invoice number.
	 */
	private function invoiceNumber(array $request, string $today): string {
		$seed = (string)($request['paymentReference'] ?? '') . '|' . bin2hex(random_bytes(8));

		return sprintf('REQ-%s-%s', substr($today, 0, 4), strtoupper(substr(hash('sha256', $seed), 0, 10)));
	}//end invoiceNumber()

	/**
	 * The request's own due date, else the default term after the invoice date.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string $today The invoice date.
	 *
	 * @return string The due date (Y-m-d).
	 */
	private function dueDate(array $request, string $today): string {
		$due = substr((string)($request['dueAt'] ?? ''), 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) === 1 && $due >= $today) {
			return $due;
		}

		return gmdate('Y-m-d', ((int)strtotime($today . ' 00:00:00 UTC') + (self::DEFAULT_TERM_DAYS * 86400)));
	}//end dueDate()

	/**
	 * The register slug holding shillinq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		$register = $this->appConfig->getValueString('shillinq', 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end registerSlug()
}//end class
