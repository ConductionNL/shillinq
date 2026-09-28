<?php

/**
 * Payment Request Portal Scope
 *
 * A payment request that stands on its own, without an invoice (leges on a
 * case, a dwangsom, a deposit), names its debtor in `debtor.customerMasterId`.
 * The customer portal cannot scope on that nested field: portaliq's per-row
 * check compares a flat row field. So such a request carries the same value in
 * `customerId`, a uuid reference to the CustomerMaster like
 * `ARInvoice.customerId`, and the portal lists it in `requestPayments`
 * (REQ-SOPR-005). A request on an invoice already reaches the portal through
 * that invoice and is left alone, or it would show twice.
 *
 * This class is that rule, in one place. The leaf API and the leges intake
 * apply it when they raise a request; BackfillPaymentRequestCustomer applies it
 * to requests raised before it existed. It does no I/O.
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
 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Gives a payment request without an invoice its portal scope.
 *
 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
 */
final class PaymentRequestPortalScope {
	/**
	 * The request with `customerId` set when it has no invoice and a customer
	 * debtor; otherwise unchanged.
	 *
	 * @param array<string, mixed> $request The payment request payload.
	 *
	 * @return array<string, mixed> The request, stamped when the rule applies.
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function stamp(array $request): array {
		if ($this->needsStamp(request: $request) === true) {
			$request['customerId'] = $this->debtorCustomer(request: $request);
		}

		return $request;
	}//end stamp()

	/**
	 * True when the request has no invoice, names a customer debtor and does
	 * not carry that customer as its scope yet.
	 *
	 * @param array<string, mixed> $request The payment request payload.
	 *
	 * @return bool True when stamp() would change the request.
	 *
	 * @spec openspec/changes/arinvoice-lines-and-portal-amounts/specs/portal-payment-initiation/spec.md (REQ-SPPI-008)
	 */
	public function needsStamp(array $request): bool {
		if ((string)($request['invoiceReference'] ?? '') !== '') {
			return false;
		}

		$customer = $this->debtorCustomer(request: $request);

		return $customer !== '' && (string)($request['customerId'] ?? '') !== $customer;
	}//end needsStamp()

	/**
	 * The debtor's customer, or '' when the debtor is a name and an email.
	 *
	 * @param array<string, mixed> $request The payment request payload.
	 *
	 * @return string The CustomerMaster reference.
	 */
	private function debtorCustomer(array $request): string {
		$debtor = ($request['debtor'] ?? null);
		if (is_array($debtor) === false || is_string($debtor['customerMasterId'] ?? null) === false) {
			return '';
		}

		return trim($debtor['customerMasterId']);
	}//end debtorCustomer()
}//end class
