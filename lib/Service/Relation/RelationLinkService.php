<?php

/**
 * Relation Link Service
 *
 * Links the customer record and the supplier record of one organisation
 * (reporting-relation-both-sides REQ-RRBS-001). A pair is suggested when the
 * KvK numbers or the VAT numbers are equal after normalisation; it becomes a
 * link only when a user confirms it, and a dismissed pair is not suggested
 * again. One customer links to at most one supplier, and a supplier to at
 * most one customer, within an administration.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Relation;

use DateTimeImmutable;
use DateTimeInterface;
use DomainException;

/**
 * Suggests, confirms, dismisses and removes customer-supplier links.
 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
 */
class RelationLinkService {

	/**
	 * Constructor.
	 *
	 * @param RelationRecords $records The administration-scoped reads.
	 */
	public function __construct(
		private readonly RelationRecords $records,
	) {

	}//end __construct()

	/**
	 * A KvK or VAT number without spaces, dots and dashes, in capitals.
	 *
	 * @param mixed $number The number as typed.
	 *
	 * @return string The normalised number, empty when there is none.
	 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function normalise(mixed $number): string {
		if (is_scalar($number) === false) {
			return '';
		}

		return strtoupper((string)preg_replace('/[\s.\-]/', '', (string)$number));

	}//end normalise()

	/**
	 * The unlinked customer and supplier pairs with an equal KvK or VAT number.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, array<string, string>> customerId, customerName, payeeId, payeeName, matchedOn (kvk or vat), number.
	 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function suggestions(string $administrationId): array {
		$customers = $this->records->rows(schema: 'CustomerMaster', administrationId: $administrationId);
		$linked    = array_filter(array_map(static fn (array $customer): string => (string)($customer['payeeId'] ?? ''), $customers));
		$payees    = ['kvk' => [], 'vat' => []];
		foreach ($this->records->rows(schema: 'Payee', administrationId: $administrationId) as $payee) {
			if (in_array($payee['id'], $linked, true) === true) {
				continue;
			}

			foreach (['kvk' => ($payee['kvkNumber'] ?? null), 'vat' => ($payee['vatNumber'] ?? null)] as $kind => $number) {
				$key = $this->normalise(number: $number);
				if ($key !== '') {
					$payees[$kind][$key][] = $payee;
				}
			}
		}

		$suggestions = [];
		foreach ($customers as $customer) {
			if ((string)($customer['payeeId'] ?? '') !== '') {
				continue;
			}

			foreach ($this->matches(customer: $customer, payees: $payees) as $suggestion) {
				$suggestions[$suggestion['customerId'] . '|' . $suggestion['payeeId']] ??= $suggestion;
			}
		}

		return array_values($suggestions);

	}//end suggestions()

	/**
	 * Link a customer to a supplier; refused when either side is linked already.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The customer.
	 * @param string $payeeId          The supplier.
	 * @param string $userId           Who confirms the link.
	 * @param string $matchedOn        kvk, vat or manual.
	 *
	 * @return array<string, mixed> The link as written.
	 *
	 * @throws DomainException When a record is not found or a side is linked already.
	 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function link(string $administrationId, string $customerId, string $payeeId, string $userId, string $matchedOn): array {
		$customer = $this->records->one(schema: 'CustomerMaster', administrationId: $administrationId, id: $customerId);
		$payee    = $this->records->one(schema: 'Payee', administrationId: $administrationId, id: $payeeId);
		if ($customer === null || $payee === null) {
			throw new DomainException('Not found');
		}

		if (in_array($matchedOn, ['kvk', 'vat', 'manual'], true) === false) {
			throw new DomainException('A link is matched on kvk, vat or manual');
		}

		$current = (string)($customer['payeeId'] ?? '');
		if ($current !== '' && $current !== $payeeId) {
			throw new DomainException('This customer is already linked to another supplier');
		}

		foreach ($this->records->rows(schema: 'CustomerMaster', administrationId: $administrationId, filters: ['payeeId' => $payeeId]) as $other) {
			if ($other['id'] !== $customerId) {
				throw new DomainException('This supplier is already linked to another customer');
			}
		}

		$fields = [
			'payeeId'   => $payeeId,
			'payeeLink' => [
				'matchedOn'   => $matchedOn,
				'confirmedBy' => $userId,
				'confirmedAt' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			],
		];
		$this->records->patch(schema: 'CustomerMaster', id: $customerId, fields: $fields);

		return $fields;

	}//end link()

	/**
	 * Stop suggesting one pair.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The customer.
	 * @param string $payeeId          The supplier.
	 *
	 * @return void
	 *
	 * @throws DomainException When the customer is not found.
	 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function dismiss(string $administrationId, string $customerId, string $payeeId): void {
		$customer = $this->records->one(schema: 'CustomerMaster', administrationId: $administrationId, id: $customerId);
		if ($customer === null || $payeeId === '') {
			throw new DomainException('Not found');
		}

		$dismissed = array_values(array_unique(array_merge((array)($customer['dismissedPayeeSuggestions'] ?? []), [$payeeId])));
		$this->records->patch(schema: 'CustomerMaster', id: $customerId, fields: ['dismissedPayeeSuggestions' => $dismissed]);

	}//end dismiss()

	/**
	 * Remove a customer's link.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The customer.
	 *
	 * @return void
	 *
	 * @throws DomainException When the customer is not found.
	 *
 * @spec openspec/changes/reporting-relation-both-sides/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function unlink(string $administrationId, string $customerId): void {
		if ($this->records->one(schema: 'CustomerMaster', administrationId: $administrationId, id: $customerId) === null) {
			throw new DomainException('Not found');
		}

		$this->records->patch(schema: 'CustomerMaster', id: $customerId, fields: ['payeeId' => null, 'payeeLink' => null]);

	}//end unlink()

	/**
	 * The suggestions for one customer.
	 *
	 * @param array<string, mixed>                                          $customer The customer.
	 * @param array<string, array<string, array<int, array<string, mixed>>>> $payees   Unlinked payees by kind and number.
	 *
	 * @return array<int, array<string, string>> The suggestions.
	 */
	private function matches(array $customer, array $payees): array {
		$dismissed = (array)($customer['dismissedPayeeSuggestions'] ?? []);
		$numbers   = [
			'kvk' => $this->normalise(number: ($customer['kvkNumber'] ?? null)),
			'vat' => $this->normalise(number: ($customer['vatID'] ?? $customer['vatId'] ?? null)),
		];
		$found     = [];
		foreach ($numbers as $kind => $number) {
			foreach (($payees[$kind][$number] ?? []) as $payee) {
				if ($number === '' || in_array($payee['id'], $dismissed, true) === true) {
					continue;
				}

				$found[] = [
					'customerId'   => (string)$customer['id'],
					'customerName' => (string)($customer['legalName'] ?? $customer['tradeName'] ?? ''),
					'payeeId'      => (string)$payee['id'],
					'payeeName'    => (string)($payee['name'] ?? $payee['tradingName'] ?? ''),
					'matchedOn'    => $kind,
					'number'       => $number,
				];
			}
		}

		return $found;

	}//end matches()
}//end class
