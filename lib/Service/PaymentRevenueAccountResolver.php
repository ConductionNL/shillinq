<?php

/**
 * Payment Revenue Account Resolver
 *
 * ADR-107 puts the ledger in shillinq and only in shillinq: a case app asks a
 * citizen for money, and shillinq decides where the receipt lands. That
 * decision is a mapping from the request type (`leges`, `dwangsom`, `deposit`,
 * `other`) to a general-ledger account, held in one app-config value
 * `paymentRevenueAccounts` so an administrator can change it without a
 * release.
 *
 * A type with no mapping is not booked to a guessed account. It resolves to
 * null, the caller leaves the request in `captured_unapplied`, and the reason
 * names the type that is missing (REQ-SOPR-002). A receipt on the wrong
 * account is worse than a receipt that waits.
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
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\IAppConfig;

/**
 * Resolves the revenue account a captured object request books against.
 *
 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
 */
final class PaymentRevenueAccountResolver {
	/**
	 * The app-config key holding the JSON map of request type to account.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'paymentRevenueAccounts';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Nextcloud app config, holding the mapping.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The account a request type books against, or null when nothing is mapped.
	 *
	 * @param string $requestType One of leges, dwangsom, deposit, other.
	 *
	 * @return string|null The account number, or null when the type is unmapped.
	 *
	 * @spec openspec/changes/case-payment-requests/specs/object-payment-requests/spec.md (REQ-SOPR-002)
	 */
	public function resolve(string $requestType): ?string {
		$account = (string)($this->all()[$requestType] ?? '');

		if ($account === '') {
			return null;
		}

		return $account;
	}//end resolve()

	/**
	 * The whole mapping, for the administration screen and for a reason that
	 * wants to say which types ARE mapped.
	 *
	 * @return array<string, string> Request type to account number.
	 */
	public function all(): array {
		$raw = $this->appConfig->getValueString('shillinq', self::CONFIG_KEY, '');
		if (trim($raw) === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$map = [];
		foreach ($decoded as $type => $account) {
			if (is_string($type) === false || is_scalar($account) === false) {
				continue;
			}

			$account = trim((string)$account);
			if ($account !== '') {
				$map[$type] = $account;
			}
		}

		return $map;
	}//end all()
}//end class
