<?php

/**
 * Contact Customer Resolver
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A recurring profile names its customer as a Nextcloud contact
 * (`customerReference`, e.g. `contact:acme-bv`); invoices and the portal
 * need the financial `CustomerMaster`. This resolves one to the other: the
 * one customer whose `externalReference` is the contact reference, else the
 * one customer with the contact's e-mail address. No match, or more than one,
 * resolves to nothing, so a guess never links a profile to the wrong customer.
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
 * @spec openspec/changes/sales-cancellation/design.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\Contacts\IManager as IContactsManager;
use Throwable;

/**
 * Resolves a contact reference to the id of its CustomerMaster.
 *
 * @spec openspec/changes/sales-cancellation/design.md
 */
class ContactCustomerResolver {

	/**
	 * The prefix a contact reference may carry before the contact's UID.
	 */
	private const CONTACT_PREFIX = 'contact:';

	/**
	 * Constructor.
	 *
	 * @param IContactsManager $contacts Nextcloud's contacts, for the contact's e-mail address.
	 */
	public function __construct(
		private readonly IContactsManager $contacts,
	) {
	}//end __construct()

	/**
	 * The id of the one customer the contact reference stands for, or null.
	 *
	 * @param string                           $reference The profile's `customerReference`.
	 * @param array<int, array<string, mixed>> $customers The CustomerMaster rows.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/sales-cancellation/tasks.md
	 */
	public function resolve(string $reference, array $customers): ?string {
		$reference = trim($reference);
		if ($reference === '') {
			return null;
		}

		$byReference = array_values(
			array_filter($customers, static fn (array $customer): bool => trim((string)($customer['externalReference'] ?? '')) === $reference)
		);
		if ($byReference !== []) {
			return self::onlyId(rows: $byReference);
		}

		$email = $this->emailOf(reference: $reference);
		if ($email === '') {
			return null;
		}

		return self::onlyId(
			rows: array_values(array_filter($customers, static fn (array $customer): bool => strtolower(trim((string)($customer['email'] ?? ''))) === $email))
		);
	}//end resolve()

	/**
	 * The contact's first e-mail address, lower-cased, or '' when there is none.
	 *
	 * @param string $reference The contact reference.
	 *
	 * @return string
	 */
	private function emailOf(string $reference): string {
		$uid = $reference;
		if (str_starts_with($uid, self::CONTACT_PREFIX) === true) {
			$uid = substr($uid, strlen(self::CONTACT_PREFIX));
		}

		try {
			$found = $this->contacts->search($uid, ['UID'], ['strict_search' => true, 'types' => false]);
		} catch (Throwable) {
			return '';
		}

		foreach ($found as $contact) {
			if (is_array($contact) === false || (string)($contact['UID'] ?? '') !== $uid) {
				continue;
			}

			$email = ($contact['EMAIL'] ?? '');
			if (is_array($email) === true) {
				$email = ($email[array_key_first($email)] ?? '');
			}

			return strtolower(trim((string)$email));
		}

		return '';
	}//end emailOf()

	/**
	 * The id of the only row, or null when there is not exactly one.
	 *
	 * @param array<int, array<string, mixed>> $rows The candidates.
	 *
	 * @return string|null
	 */
	private static function onlyId(array $rows): ?string {
		if (count($rows) !== 1) {
			return null;
		}

		$id = ObjectIdentifier::resolve(saved: $rows[0]);
		if ($id === '') {
			return null;
		}

		return $id;
	}//end onlyId()
}//end class
